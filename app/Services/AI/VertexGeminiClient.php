<?php

namespace App\Services\AI;

use App\Services\Vertex\VertexAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Shared foundation for four AI operations. NO prompts, outputs, or raw Google
 * error bodies are written to the usage ledger or application logs.
 */
class VertexGeminiClient
{
    public function __construct(
        private readonly VertexAuthService $auth,
        private readonly AiUsageMeter $meter,
        private readonly AiResponseSchemaValidator $schemaValidator
    ) {
    }

    /**
     * @param array<string, mixed> $responseSchema Vertex-compatible response schema.
     * @return array{data: array<string, mixed>, request_id: string, workflow: string, model: string, usage: array<string, mixed>}
     */
    public function generateJson(
        string $workflow,
        string $prompt,
        string $systemInstruction,
        array $responseSchema,
        ?int $companyId = null,
        ?int $userId = null,
        ?int $documentId = null,
        string $billingContext = 'customer'
    ): array {
        if (! config('ai.enabled', false)) {
            throw new \RuntimeException('New Vertex AI infrastructure is disabled (AI_VERTEX_ENABLED=false).');
        }

        $profile = config('ai.workflows.' . $workflow);

        if (! is_array($profile) || $workflow === '' || ! array_key_exists('model', $profile)) {
            throw new \InvalidArgumentException('Unrecognized AI workflow.');
        }

        $model = (string) $profile['model'];
        $location = trim((string) config('ai.vertex.location', 'eu'));
        $project = trim((string) (config('ai.vertex.project_id') ?: config('services.vertex_ai.project_id')));
        $credentials = trim((string) (config('ai.vertex.credentials_path') ?: config('services.vertex_ai.credentials_path')));

        if (! preg_match('/^[a-zA-Z0-9_.-]+$/', $model)
            || ! preg_match('/^[a-z0-9-]+$/', $location)
            || ! preg_match('/^[a-zA-Z0-9_.:-]+$/', $project)) {
            throw new \RuntimeException('Invalid Vertex model, location, or project configuration.');
        }

        if ($credentials === '' || trim($prompt) === '' || trim($systemInstruction) === '' || $responseSchema === []) {
            throw new \InvalidArgumentException('Vertex credentials, prompt, system instruction or schema missing.');
        }

        if (strtoupper((string) ($responseSchema['type'] ?? '')) !== 'OBJECT') {
            throw new \InvalidArgumentException('This client requires a JSON object root response schema.');
        }

        if (mb_strlen($prompt) + mb_strlen($systemInstruction) > (int) config('ai.vertex.max_input_chars', 40000)) {
            throw new \InvalidArgumentException('AI prompt exceeds the configured character limit.');
        }

        if (! in_array($billingContext, ['customer', 'internal_backfill'], true)) {
            throw new \InvalidArgumentException('Invalid AI billing context.');
        }

        $this->validateAttribution($companyId, $userId, $documentId);

        // Fail closed if the usage ledger is not available: no unmetered requests.
        $requestId = $this->meter->begin($workflow, $model, $location, $companyId, $userId, $documentId, $billingContext);
        $started = microtime(true);

        try {
            $token = $this->auth->getAccessToken($credentials);
        } catch (\Throwable $exception) {
            $this->meter->fail($requestId, 'authentication_failed', null, $this->elapsed($started));
            throw new \RuntimeException('Unable to authenticate to Vertex AI.');
        }

        $host = match ($location) {
            'global' => 'https://aiplatform.googleapis.com',
            'eu', 'us' => "https://aiplatform.{$location}.rep.googleapis.com",
            default => "https://{$location}-aiplatform.googleapis.com",
        };

        $url = sprintf(
            '%s/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $host,
            rawurlencode($project),
            rawurlencode($location),
            rawurlencode($model)
        );

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => (float) ($profile['temperature'] ?? 0.0),
                'maxOutputTokens' => (int) ($profile['max_output_tokens'] ?? 4096),
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ];

        if (isset($profile['thinking_level'])) {
            $payload['generationConfig']['thinkingConfig'] = [
                'thinkingLevel' => (string) $profile['thinking_level'],
            ];
        }

        $httpResponse = null;
        $maxAttempts = max(1, min(3, (int) config('ai.vertex.max_attempts', 1)));

        try {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $httpResponse = Http::timeout((int) config('ai.vertex.timeout_seconds', 90))
                    ->withToken($token)
                    ->acceptJson()
                    ->post($url, $payload);

                if ($httpResponse->successful()) {
                    break;
                }

                if ($attempt === $maxAttempts || ! in_array($httpResponse->status(), [429, 502, 503, 504], true)) {
                    break;
                }

                usleep($attempt * 400000);
            }
        } catch (\Throwable $exception) {
            $this->meter->fail($requestId, 'transport_failed', null, $this->elapsed($started));
            throw new \RuntimeException('Vertex API transport failed.');
        }

        if (! $httpResponse || ! $httpResponse->successful()) {
            $status = $httpResponse?->status();
            $this->meter->fail($requestId, 'vertex_http_' . ($status ?? 'unknown'), $status, $this->elapsed($started));
            // Never echo Google's error body: it can contain parts of patient content.
            throw new \RuntimeException('Vertex API returned HTTP ' . ($status ?? 'unknown') . '.');
        }

        $response = $httpResponse->json();
        $response = is_array($response) ? $response : [];

        try {
            $finishReason = (string) data_get($response, 'candidates.0.finishReason', '');
            if ($finishReason !== '' && $finishReason !== 'STOP') {
                throw new \RuntimeException('Vertex did not complete generation normally.');
            }

            $parts = data_get($response, 'candidates.0.content.parts', []);
            $text = '';

            foreach (is_array($parts) ? $parts : [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }

            if (trim($text) === '') {
                throw new \RuntimeException('Vertex returned no generated text.');
            }

            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \RuntimeException('Vertex returned a non-object JSON output.');
            }

            $this->schemaValidator->assertValid($responseSchema, $data);
        } catch (\Throwable $exception) {
            // Invalid or truncated output can STILL be chargeable: record tokens.
            $this->meter->complete($requestId, $response, $this->elapsed($started), $model, 'invalid_output');
            throw new \RuntimeException('Vertex returned invalid structured output.');
        }

        $this->meter->complete($requestId, $response, $this->elapsed($started), $model, 'succeeded');

        return [
            'data' => $data,
            'request_id' => $requestId,
            'workflow' => $workflow,
            'model' => $model,
            'usage' => is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [],
        ];
    }

    private function validateAttribution(?int $companyId, ?int $userId, ?int $documentId): void
    {
        if ($userId !== null && ($companyId === null || ! DB::table('users')->where('id', $userId)->where('company_id', $companyId)->exists())) {
            throw new \InvalidArgumentException('User does not belong to the specified company.');
        }

        if ($documentId !== null) {
            $document = DB::table('documents')
                ->leftJoin('branches', 'documents.branch_id', '=', 'branches.id')
                ->leftJoin('patients', 'documents.patient_id', '=', 'patients.id')
                ->leftJoin('branches as patient_branch', 'patients.branch_id', '=', 'patient_branch.id')
                ->where('documents.id', $documentId)
                ->first([
                    'documents.company_id as direct_company_id',
                    'branches.company_id as branch_company_id',
                    'patient_branch.company_id as patient_company_id',
                ]);
            if (! $document || $companyId === null) {
                throw new \InvalidArgumentException('Document/company attribution missing.');
            }
            $ids = array_values(array_unique(array_filter([
                $document->direct_company_id,
                $document->branch_company_id,
                $document->patient_company_id,
            ], static fn ($id): bool => $id !== null)));
            if (count($ids) !== 1 || (int) $ids[0] !== $companyId) {
                throw new \InvalidArgumentException('Document does not belong to a single matching company.');
            }
        }
    }

    private function elapsed(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }
}
