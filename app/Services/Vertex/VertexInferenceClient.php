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
    public function invokeEndpoint(
        string $location,
        string $endpointId,
        string $prompt,
        ?string $systemInstruction = null
    ): array {
        $projectId = trim(
            (string) config('services.vertex_ai.project_id')
        );

        $credentialsPath = trim(
            (string) config('services.vertex_ai.credentials_path')
        );

        if ($projectId === '' || $credentialsPath === '') {
            throw new \RuntimeException(
                'Vertex inference konfigurácia je neúplná.'
            );
        }

        $token = $this->authService->getAccessToken(
            $credentialsPath
        );

        $url = sprintf(
            '%s/v1/projects/%s/locations/%s/endpoints/%s:generateContent',
            $this->apiBaseUrl($location),
            rawurlencode($projectId),
            rawurlencode($location),
            rawurlencode($endpointId)
        );

        $response = Http::timeout(45)
            ->retry(2, 700, throw: false)
            ->withToken($token)
            ->acceptJson()
            ->post($url, [
                'systemInstruction' => [
                    'parts' => [
                        [
                            'text' => $systemInstruction
                                ?? 'You are a nursing documentation assistant. Return valid JSON only.',
                        ],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => $prompt,
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.05,
                    'maxOutputTokens' => 2048,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Vertex endpoint vrátil chybu HTTP '
                . $response->status()
                . '. '
                . mb_substr((string) $response->body(), 0, 500)
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new \RuntimeException(
                'Vertex endpoint vrátil neplatnú odpoveď.'
            );
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function invokePublisherModel(
        string $location,
        string $model,
        string $prompt
    ): array {
        $projectId = trim(
            (string) config('services.vertex_ai.project_id')
        );

        $credentialsPath = trim(
            (string) config('services.vertex_ai.credentials_path')
        );

        if ($projectId === '' || $credentialsPath === '') {
            throw new \RuntimeException(
                'Vertex publisher konfigurácia je neúplná.'
            );
        }

        $token = $this->authService->getAccessToken(
            $credentialsPath
        );

        $url = sprintf(
            '%s/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $this->apiBaseUrl($location),
            rawurlencode($projectId),
            rawurlencode($location),
            rawurlencode($model)
        );

        $response = Http::timeout(45)
            ->retry(2, 700, throw: false)
            ->withToken($token)
            ->acceptJson()
            ->post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => $prompt,
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.05,
                    'maxOutputTokens' => 2048,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Vertex publisher model vrátil chybu HTTP '
                . $response->status()
                . '. '
                . mb_substr((string) $response->body(), 0, 500)
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new \RuntimeException(
                'Vertex publisher model vrátil neplatnú odpoveď.'
            );
        }

        return $decoded;
    }

    private function apiBaseUrl(string $location): string
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