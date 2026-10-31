<?php

namespace App\Services\Vertex;

use Illuminate\Support\Facades\Http;

class VertexInferenceClient
{
    public function __construct(
        private readonly VertexAuthService $authService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function invokeEndpoint(string $location, string $endpointId, string $prompt, ?string $systemInstruction = null): array
    {
        $projectId = trim((string) config('services.vertex_ai.project_id'));
        $credentialsPath = trim((string) config('services.vertex_ai.credentials_path'));

        if ($projectId === '' || $credentialsPath === '') {
            throw new \RuntimeException('Vertex inference konfigurácia je neúplná.');
        }

        $token = $this->authService->getAccessToken($credentialsPath);

        $url = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/endpoints/%s:generateContent',
            $location,
            $projectId,
            $location,
            $endpointId
        );

        $response = Http::timeout(45)
            ->retry(2, 700, throw: false)
            ->withToken($token)
            ->acceptJson()
            ->post($url, [
                'systemInstruction' => [
                    'parts' => [[
                        'text' => $systemInstruction ?? 'You are a nursing documentation assistant. Return valid JSON only.',
                    ]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $prompt,
                    ]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.05,
                    'maxOutputTokens' => 2048,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Vertex endpoint vrátil chybu HTTP ' . $response->status() . '.');
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new \RuntimeException('Vertex endpoint vrátil neplatnú odpoveď.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function invokePublisherModel(string $location, string $model, string $prompt): array
    {
        $projectId = trim((string) config('services.vertex_ai.project_id'));
        $credentialsPath = trim((string) config('services.vertex_ai.credentials_path'));

        if ($projectId === '' || $credentialsPath === '') {
            throw new \RuntimeException('Vertex publisher konfigurácia je neúplná.');
        }

        $token = $this->authService->getAccessToken($credentialsPath);

        $baseUrl = $location === 'global'
            ? 'https://aiplatform.googleapis.com'
            : sprintf('https://%s-aiplatform.googleapis.com', $location);

        $url = sprintf(
            '%s/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $baseUrl,
            $projectId,
            $location,
            $model
        );

        $response = Http::timeout(45)
            ->retry(2, 700, throw: false)
            ->withToken($token)
            ->acceptJson()
            ->post($url, [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $prompt,
                    ]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.05,
                    'maxOutputTokens' => 2048,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Vertex publisher model vrátil chybu HTTP ' . $response->status() . '.');
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new \RuntimeException('Vertex publisher model vrátil neplatnú odpoveď.');
        }

        return $decoded;
    }

}
