<?php

namespace App\Services;

use App\Jobs\HandleVertexModelIncidentJob;
use App\Models\Document;
use App\Models\Patient;
use App\Services\Vertex\DekurzPromptBuilder;
use App\Services\Vertex\VertexSuggestionCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DekurzAiPrefillService
{
        public function __construct(
            private readonly DekurzPromptBuilder $promptBuilder,
            private readonly VertexSuggestionCache $suggestionCache
        ) {
        }

    private const VERTEX_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    /**
     * Build dekurz section draft texts from the patient's latest proposal
     * using the tuned Dekurz endpoint.
     *
     * @return array<string, mixed>
     */
    public function buildFromLatestProposal(Patient $patient): array
    {
        $document = Document::query()
            ->where('patient_id', $patient->id)
            ->where('type', 'proposal')
            ->orderByDesc('created_at')
            ->first();

        if (! $document) {
            throw new \RuntimeException(
                'Pacient nemá žiadny návrh ošetrovateľskej starostlivosti.'
            );
        }

        $proposal = $this->readProposalPayload($document);

        if (empty($proposal)) {
            throw new \RuntimeException(
                'Posledný návrh ošetrovateľskej starostlivosti neobsahuje údaje.'
            );
        }

        $suggestion = [
            'proposal_document_id' => $document->id,
            'sections' => $this->callVertexAndParse($proposal),
        ];

        $this->suggestionCache->remember('dekurz', (int) $patient->id, $suggestion);

        return $suggestion;
    }

    /**
     * Improve user-written dekurz text with the general Gemini model.
     */
    public function improveText(string $text): string
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            throw new \RuntimeException(
                'Text na vylepšenie je prázdny.'
            );
        }

        $response = $this->callGeneralVertexModel(
            $this->buildImprovePrompt($trimmed)
        );

        $output = $this->extractPredictionPayload($response);

        if (is_array($output)) {
            $candidate = trim((string) (
                $output['text']
                ?? $output['improved_text']
                ?? ''
            ));

            if ($candidate !== '') {
                return $this->extractImprovedText($candidate);
            }

            if (
                isset($output['sections'])
                && is_array($output['sections'])
            ) {
                $parts = [];

                foreach ($output['sections'] as $section) {
                    if (! is_array($section)) {
                        continue;
                    }

                    $sectionText = trim((string) (
                        $section['text'] ?? ''
                    ));

                    if ($sectionText !== '') {
                        $parts[] = $sectionText;
                    }
                }

                if (! empty($parts)) {
                    return implode("\n\n", $parts);
                }
            }
        }

        $raw = is_string($output) ? trim($output) : '';

        if ($raw === '') {
            throw new \RuntimeException(
                'AI nevrátila použiteľný text.'
            );
        }

        return $this->extractImprovedText($raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function readProposalPayload(Document $document): array
    {
        if (
            ! $document->path
            || ! Storage::disk('local')->exists($document->path)
        ) {
            return [];
        }

        $raw = Storage::disk('local')->get($document->path);
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array<int, array{text: string}>
     */
    private function callVertexAndParse(array $proposal): array
    {
        $attempts = 2;
        $lastError = null;
        $lastDecoded = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->callTunedDekurzEndpoint(
                    $this->promptBuilder->build(
                        $proposal,
                        $attempt > 1
                    )
                );

                $output = $this->extractPredictionPayload($response);
                $lastDecoded = $output;
            } catch (\Throwable $exception) {
                $lastError = $exception;
                continue;
            }

            $parsed = $this->parseSectionsFromOutput($output);

            if ($parsed !== null && ! empty($parsed)) {
                return $parsed;
            }

            $lastError = new \RuntimeException('Trénovaný Dekurz model vrátil odpoveď bez platnej JSON schémy.');
        }

        $deployment = $this->resolveActiveDekurzDeployment();
        $runtimeFallbackEnabled = (bool) config('services.vertex_ai.auto_train.runtime_fallback', true);

        if (
            $runtimeFallbackEnabled
            && ($deployment['source'] ?? '') === 'auto_train_state'
            && $this->isFallbackEligibleError($lastError, $lastDecoded)
        ) {
            $fallbackDeployment = [
                'location' => trim((string) config('services.vertex_ai.dekurz.location')),
                'endpoint_id' => trim((string) config('services.vertex_ai.dekurz.endpoint_id')),
                'source' => 'config',
            ];

            try {
                $response = $this->callTunedDekurzEndpoint(
                    $this->promptBuilder->build($proposal, true),
                    $fallbackDeployment
                );

                $parsed = $this->parseSectionsFromOutput($this->extractPredictionPayload($response));

                if ($parsed !== null && ! empty($parsed)) {
                    HandleVertexModelIncidentJob::dispatch([
                        'reason' => $lastError?->getMessage(),
                        'active_endpoint_id' => $deployment['endpoint_id'] ?? null,
                        'fallback_endpoint_id' => $fallbackDeployment['endpoint_id'] ?? null,
                        'status' => 'fallback_succeeded',
                    ]);

                    return $parsed;
                }
            } catch (\Throwable $fallbackError) {
                HandleVertexModelIncidentJob::dispatch([
                    'reason' => $lastError?->getMessage(),
                    'active_endpoint_id' => $deployment['endpoint_id'] ?? null,
                    'fallback_endpoint_id' => $fallbackDeployment['endpoint_id'] ?? null,
                    'status' => 'fallback_failed',
                ]);

                throw new \RuntimeException(
                    'Trénovaný endpoint zlyhal a statický fallback endpoint vrátil chybu: ' . $fallbackError->getMessage(),
                    previous: $fallbackError
                );
            }
        }

        if ($lastError !== null) {
            throw new \RuntimeException(
                $lastError->getMessage(),
                previous: $lastError
            );
        }

        throw new \RuntimeException(
            'AI nevrátila použiteľné texty dekurzu. Skúste to prosím znova.'
        );
    }

    /**
     * Call the tuned Dekurz endpoint.
     *
     * @return array<string, mixed>
     */
    private function callTunedDekurzEndpoint(string $userPrompt, ?array $forcedDeployment = null): array
    {
        $projectId = trim((string) config(
            'services.vertex_ai.project_id'
        ));

        $deployment = $forcedDeployment ?? $this->resolveActiveDekurzDeployment();
        $location = $deployment['location'];
        $endpointId = $deployment['endpoint_id'];

        $credentialsPath = trim((string) config(
            'services.vertex_ai.credentials_path'
        ));

        if ($projectId === '') {
            throw new \RuntimeException(
                'Chýba nastavenie VERTEX_PROJECT_ID.'
            );
        }

        if ($location === '') {
            throw new \RuntimeException(
                'Chýba nastavenie VERTEX_DEKURZ_LOCATION.'
            );
        }

        if ($endpointId === '') {
            throw new \RuntimeException(
                'Chýba endpoint ID trénovaného Dekurz modelu.'
            );
        }

        if ($credentialsPath === '') {
            throw new \RuntimeException(
                'Chýba nastavenie GOOGLE_APPLICATION_CREDENTIALS.'
            );
        }

        if (! is_file($credentialsPath)) {
            throw new \RuntimeException(
                'Súbor so service account JSON sa nenašiel.'
            );
        }

        if (! is_readable($credentialsPath)) {
            throw new \RuntimeException(
                'Súbor so service account JSON nie je čitateľný.'
            );
        }

        $accessToken = $this->getVertexAccessToken(
            $credentialsPath
        );

        $url = sprintf(
            '%s/v1/projects/%s/locations/%s/endpoints/%s:generateContent',
            $this->vertexApiBaseUrl($location),
            rawurlencode($projectId),
            rawurlencode($location),
            rawurlencode($endpointId)
        );

        Log::info('Dekurz AI: sending tuned Vertex request', [
            'project_id' => $projectId,
            'location' => $location,
            'endpoint_id' => $endpointId,
            'source' => $deployment['source'],
            'url' => $url,
        ]);

        $response = Http::timeout(75)
            ->retry(2, 700, throw: false)
            ->withToken($accessToken)
            ->acceptJson()
            ->post($url, [
                'systemInstruction' => [
                    'parts' => [[
                        'text' => 'You are a nursing documentation assistant. Return valid JSON only.',
                    ]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $userPrompt,
                    ]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.05,
                    'maxOutputTokens' => 2048,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            $googleMessage = trim((string) data_get(
                $response->json(),
                'error.message',
                ''
            ));

            Log::error('Dekurz AI: tuned Vertex request failed', [
                'status' => $response->status(),
                'project_id' => $projectId,
                'location' => $location,
                'endpoint_id' => $endpointId,
                'source' => $deployment['source'],
                'google_error' => $googleMessage,
            ]);

            $message = sprintf(
                'Trénovaný Dekurz model vrátil chybu HTTP %d.',
                $response->status()
            );

            if ($googleMessage !== '') {
                $message .= ' ' . $googleMessage;
            }

            throw new \RuntimeException($message);
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new \RuntimeException(
                'Trénovaný Dekurz model vrátil neplatnú odpoveď.'
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|string|null $decodedOutput
     */
    private function isFallbackEligibleError(?\Throwable $error, array|string|null $decodedOutput): bool
    {
        if ($decodedOutput !== null) {
            $parsed = $this->parseSectionsFromOutput($decodedOutput);
            if ($parsed === null || empty($parsed)) {
                return true;
            }
        }

        if (! $error) {
            return false;
        }

        $message = mb_strtolower($error->getMessage());

        if (
            str_contains($message, 'http 404')
            || str_contains($message, 'http 410')
            || str_contains($message, 'http 429')
            || str_contains($message, 'http 500')
            || str_contains($message, 'http 502')
            || str_contains($message, 'http 503')
            || str_contains($message, 'http 504')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection')
            || str_contains($message, 'neplatnú odpoveď')
            || str_contains($message, 'nevrátila použiteľné')
        ) {
            return true;
        }

        if (
            str_contains($message, 'http 400')
            || str_contains($message, 'http 401')
            || str_contains($message, 'http 403')
            || str_contains($message, 'chýba nastavenie')
        ) {
            return false;
        }

        return false;
    }

    /**
     * Call the general Gemini publisher model for text improvement.
     *
     * @return array<string, mixed>
     */
    private function callGeneralVertexModel(string $userPrompt): array
    {
        $projectId = trim((string) config(
            'services.vertex_ai.project_id'
        ));

        $location = trim((string) config(
            'services.vertex_ai.general_location',
            'global'
        ));

        $model = trim((string) config(
            'services.vertex_ai.general_model',
            'gemini-2.5-flash-lite'
        ));

        $credentialsPath = trim((string) config(
            'services.vertex_ai.credentials_path'
        ));

        if ($projectId === '') {
            throw new \RuntimeException(
                'Chýba nastavenie VERTEX_PROJECT_ID.'
            );
        }

        if ($location === '') {
            throw new \RuntimeException(
                'Chýba nastavenie VERTEX_GENERAL_LOCATION.'
            );
        }

        if ($model === '') {
            throw new \RuntimeException(
                'Chýba nastavenie VERTEX_GENERAL_MODEL.'
            );
        }

        if ($credentialsPath === '') {
            throw new \RuntimeException(
                'Chýba nastavenie GOOGLE_APPLICATION_CREDENTIALS.'
            );
        }

        if (! is_file($credentialsPath)) {
            throw new \RuntimeException(
                'Súbor so service account JSON sa nenašiel.'
            );
        }

        if (! is_readable($credentialsPath)) {
            throw new \RuntimeException(
                'Súbor so service account JSON nie je čitateľný.'
            );
        }

        $accessToken = $this->getVertexAccessToken(
            $credentialsPath
        );

        $baseUrl = $this->vertexApiBaseUrl($location);

        $url = sprintf(
            '%s/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $baseUrl,
            rawurlencode($projectId),
            rawurlencode($location),
            rawurlencode($model)
        );

        Log::info('Dekurz AI: sending general text-improvement request', [
            'project_id' => $projectId,
            'location' => $location,
            'model' => $model,
            'url' => $url,
        ]);

        $response = Http::timeout(75)
            ->retry(2, 700, throw: false)
            ->withToken($accessToken)
            ->acceptJson()
            ->post($url, [
                'systemInstruction' => [
                    'parts' => [[
                        'text' => 'Si odborný jazykový asistent pre zdravotnícku dokumentáciu. Zachovaj všetky medicínske fakty a vráť iba validný JSON.',
                    ]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $userPrompt,
                    ]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 2048,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            $googleMessage = trim((string) data_get(
                $response->json(),
                'error.message',
                ''
            ));

            Log::error('Dekurz AI: general text-improvement request failed', [
                'status' => $response->status(),
                'project_id' => $projectId,
                'location' => $location,
                'model' => $model,
                'google_error' => $googleMessage,
            ]);

            $message = sprintf(
                'Všeobecný AI model vrátil chybu HTTP %d.',
                $response->status()
            );

            if ($googleMessage !== '') {
                $message .= ' ' . $googleMessage;
            }

            throw new \RuntimeException($message);
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new \RuntimeException(
                'Všeobecný AI model vrátil neplatnú odpoveď.'
            );
        }

        return $decoded;
    }

    /**
     * Uses the automatically promoted endpoint from state.json when available.
     * Otherwise it falls back to the static Dekurz endpoint from .env.
     *
     * @return array{
     *     location: string,
     *     endpoint_id: string,
     *     source: string
     * }
     */
    private function resolveActiveDekurzDeployment(): array
    {
        $fallback = [
            'location' => trim((string) config(
                'services.vertex_ai.dekurz.location'
            )),
            'endpoint_id' => trim((string) config(
                'services.vertex_ai.dekurz.endpoint_id'
            )),
            'source' => 'config',
        ];

        $statePath = trim((string) config(
            'services.vertex_ai.auto_train.state_path',
            'ai/dekurz-autotrain/state.json'
        ));

        if (
            $statePath === ''
            || ! Storage::disk('local')->exists($statePath)
        ) {
            return $fallback;
        }

        $state = json_decode(
            Storage::disk('local')->get($statePath),
            true
        );

        if (! is_array($state)) {
            return $fallback;
        }

        $location = trim((string) (
            $state['active_location'] ?? ''
        ));

        $endpointId = trim((string) (
            $state['active_endpoint_id'] ?? ''
        ));

        if ($location === '' || $endpointId === '') {
            return $fallback;
        }

        return [
            'location' => $location,
            'endpoint_id' => $endpointId,
            'source' => 'auto_train_state',
        ];
    }

    /**
     * @param array<string, mixed>|string|null $output
     * @return array<int, array{text: string}>|null
     */
    private function parseSectionsFromOutput(
        array|string|null $output
    ): ?array {
        if (is_array($output)) {
            if (
                isset($output['sections'])
                && is_array($output['sections'])
            ) {
                try {
                    return $this->normalizeSections($output);
                } catch (\Throwable) {
                    return null;
                }
            }

            if (isset($output['text'])) {
                $single = trim((string) $output['text']);

                return $single !== ''
                    ? [['text' => $single]]
                    : null;
            }

            return null;
        }

        if (! is_string($output) || trim($output) === '') {
            return null;
        }

        $decodedJson = json_decode($output, true);

        if (! is_array($decodedJson)) {
            preg_match(
                '/\{(?:[^{}]|(?R))*\}/s',
                $output,
                $match
            );

            $decodedJson = isset($match[0])
                ? json_decode($match[0], true)
                : null;
        }

        if (
            is_array($decodedJson)
            && isset($decodedJson['sections'])
            && is_array($decodedJson['sections'])
        ) {
            try {
                return $this->normalizeSections($decodedJson);
            } catch (\Throwable) {
                return null;
            }
        }

        $chunks = preg_split('/\n{2,}/', trim($output)) ?: [];
        $sections = [];

        foreach ($chunks as $chunk) {
            $text = trim(
                preg_replace(
                    '/^[\-\*\d\.\)\s]+/u',
                    '',
                    (string) $chunk
                ) ?? ''
            );

            if ($text !== '') {
                $sections[] = ['text' => $text];
            }
        }

        return ! empty($sections) ? $sections : null;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, array{text: string}>
     */
    private function normalizeSections(array $decoded): array
    {
        $sections = [];

        $raw = is_array($decoded['sections'] ?? null)
            ? $decoded['sections']
            : [];

        foreach ($raw as $section) {
            if (! is_array($section)) {
                continue;
            }

            $text = trim((string) ($section['text'] ?? ''));

            if ($text !== '') {
                $sections[] = ['text' => $text];
            }
        }

        if (empty($sections)) {
            throw new \RuntimeException(
                'AI nevrátila žiadne použiteľné texty dekurzu.'
            );
        }

        return $sections;
    }

    private function buildImprovePrompt(string $text): string
    {
        return "Vylepši nasledujúci text dekurzu v slovenskom jazyku.\n"
            . "Zachovaj všetky medicínske fakty, lieky, výkony a pôvodný význam.\n"
            . "Uprav iba gramatiku, čitateľnosť, štruktúru a profesionálny štýl.\n"
            . "Nevymýšľaj nové klinické tvrdenia.\n"
            . "Vráť iba validný JSON v tvare: "
            . "{\"improved_text\":\"...\"}.\n\n"
            . "VSTUPNÝ TEXT:\n"
            . $text;
    }

    private function extractImprovedText(string $raw): string
    {
        $text = trim($raw);

        if ($text === '') {
            throw new \RuntimeException(
                'AI nevrátila použiteľný text.'
            );
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            $candidate = trim((string) (
                $decoded['improved_text']
                ?? $decoded['text']
                ?? ''
            ));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        if (
            preg_match(
                '/\{(?:[^{}]|(?R))*\}/s',
                $text,
                $match
            ) === 1
        ) {
            $embedded = json_decode($match[0], true);

            if (is_array($embedded)) {
                $candidate = trim((string) (
                    $embedded['improved_text']
                    ?? $embedded['text']
                    ?? ''
                ));

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $loose = $this->extractLooseImprovedText($text);

        if ($loose !== null && $loose !== '') {
            return $loose;
        }

        return $text;
    }

    private function extractLooseImprovedText(string $text): ?string
    {
        if (
            preg_match(
                '/"improved_text"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s',
                $text,
                $match
            ) === 1
        ) {
            $candidate = trim(
                stripcslashes((string) $match[1])
            );

            return $candidate !== '' ? $candidate : null;
        }

        if (
            preg_match(
                '/"improved_text"\s*:\s*"(.*)$/s',
                $text,
                $match
            ) === 1
        ) {
            $candidate = (string) $match[1];

            $candidate = preg_replace(
                '/"\s*}\s*$/s',
                '',
                $candidate
            ) ?? $candidate;

            $candidate = trim(stripcslashes($candidate));

            return $candidate !== '' ? $candidate : null;
        }

        return null;
    }

    private function getVertexAccessToken(
        string $credentialsPath
    ): string {
        $json = json_decode(
            (string) file_get_contents($credentialsPath),
            true
        );

        if (! is_array($json)) {
            throw new \RuntimeException(
                'Service account JSON je neplatný.'
            );
        }

        $clientEmail = (string) ($json['client_email'] ?? '');
        $privateKey = (string) ($json['private_key'] ?? '');

        if ($clientEmail === '' || $privateKey === '') {
            throw new \RuntimeException(
                'Service account JSON neobsahuje client_email alebo private_key.'
            );
        }

        $now = time();

        $headerJson = json_encode(
            ['alg' => 'RS256', 'typ' => 'JWT'],
            JSON_UNESCAPED_SLASHES
        );

        $claimSetJson = json_encode([
            'iss' => $clientEmail,
            'sub' => $clientEmail,
            'scope' => self::VERTEX_SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES);

        if (! is_string($headerJson) || ! is_string($claimSetJson)) {
            throw new \RuntimeException(
                'Nepodarilo sa vytvoriť JWT pre Vertex AI.'
            );
        }

        $header = $this->base64UrlEncode($headerJson);
        $claimSet = $this->base64UrlEncode($claimSetJson);
        $unsignedJwt = $header . '.' . $claimSet;
        $signature = '';

        $signed = openssl_sign(
            $unsignedJwt,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256
        );

        if (! $signed) {
            throw new \RuntimeException(
                'Nepodarilo sa podpísať JWT pre Vertex AI.'
            );
        }

        $jwt = $unsignedJwt
            . '.'
            . $this->base64UrlEncode($signature);

        $response = Http::asForm()
            ->timeout(30)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if (! $response->successful()) {
            $googleMessage = trim((string) data_get(
                $response->json(),
                'error_description',
                ''
            ));

            $message = 'Nepodarilo sa získať Vertex access token.';

            if ($googleMessage !== '') {
                $message .= ' ' . $googleMessage;
            }

            throw new \RuntimeException($message);
        }

        $accessToken = trim((string) data_get(
            $response->json(),
            'access_token',
            ''
        ));

        if ($accessToken === '') {
            throw new \RuntimeException(
                'Vertex access token nebol vrátený.'
            );
        }

        return $accessToken;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '='
        );
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>|string|null
     */
    private function extractPredictionPayload(
        array $decoded
    ): array|string|null {
        $candidates = [
            data_get($decoded, 'candidates.0.content.parts.0.text'),
            data_get($decoded, 'candidates.0.output'),
            data_get($decoded, 'predictions.0.text'),
            data_get($decoded, 'predictions.0.content.parts.0.text'),
            data_get($decoded, 'predictions.0.output'),
            data_get($decoded, 'predictions.0.response.text'),
            data_get($decoded, 'predictions.0'),
        ];

        foreach ($candidates as $candidate) {
            if (
                is_string($candidate)
                && trim($candidate) !== ''
            ) {
                return $candidate;
            }

            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function vertexApiBaseUrl(string $location): string
    {
        return match ($location) {
            'global' => 'https://aiplatform.googleapis.com',

            'eu', 'us' => sprintf(
                'https://aiplatform.%s.rep.googleapis.com',
                $location
            ),

            default => sprintf(
                'https://%s-aiplatform.googleapis.com',
                $location
            ),
        };
    }
}