<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use App\Support\ClinicalFrequency;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds proposal prefill data from the latest scanned OCR document
 * using the tuned Vertex AI proposal endpoint.
 */
class ProposalOcrPrefillService
{
    private const VERTEX_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const MAX_OCR_TEXT_CHARS = 24000;

    /**
     * @return array<string, mixed>
     */
    public function getLatestScanAvailability(Patient $patient): array
    {
        $document = $this->getLatestScanDocument($patient);

        if (! $document) {
            return [
                'has_scan_document' => false,
                'has_extracted_text' => false,
                'can_prefill' => false,
                'latest_scan_document_id' => null,
                'scanned_at' => null,
            ];
        }

        $payload = $this->readScanPayload($document);
        $ocrText = $this->extractOcrText($payload);

        return [
            'has_scan_document' => true,
            'has_extracted_text' => trim($ocrText) !== '',
            'can_prefill' => trim($ocrText) !== '',
            'latest_scan_document_id' => $document->id,
            'scanned_at' => $payload['scanned_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPrefillFromLatestScan(Patient $patient): array
    {
        $document = $this->getLatestScanDocument($patient);

        if (! $document) {
            Log::warning('Proposal OCR prefill: latest scan document missing', [
                'patient_id' => $patient->id,
            ]);

            throw new \RuntimeException(
                'Pacient nemá žiadny naskenovaný dokument.'
            );
        }

        Log::info('Proposal OCR prefill: started', [
            'patient_id' => $patient->id,
            'scan_document_id' => $document->id,
        ]);

        $payload = $this->readScanPayload($document);
        $ocrText = $this->extractOcrText($payload);

        if (trim($ocrText) === '') {
            Log::warning('Proposal OCR prefill: OCR stage has no extracted text', [
                'patient_id' => $patient->id,
                'scan_document_id' => $document->id,
            ]);

            throw new \RuntimeException(
                'V poslednom skene nie je dostupný OCR text.'
            );
        }

        Log::info('Proposal OCR prefill: OCR stage succeeded', [
            'patient_id' => $patient->id,
            'scan_document_id' => $document->id,
            'ocr_text_chars' => mb_strlen($ocrText),
        ]);

        try {
            $structured = $this->callVertexAndParse($ocrText);
        } catch (\Throwable $exception) {
            Log::error('Proposal OCR prefill: Vertex interpretation stage failed', [
                'patient_id' => $patient->id,
                'scan_document_id' => $document->id,
                'exception' => get_class($exception),
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }

        Log::info('Proposal OCR prefill: Vertex interpretation stage succeeded', [
            'patient_id' => $patient->id,
            'scan_document_id' => $document->id,
        ]);

        return [
            'latest_scan_document_id' => $document->id,
            'scanned_at' => $payload['scanned_at'] ?? null,
            'prefill' => $structured,
        ];
    }

    private function getLatestScanDocument(Patient $patient): ?Document
    {
        return Document::query()
            ->where('patient_id', $patient->id)
            ->where('type', 'scan')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function readScanPayload(Document $document): array
    {
        if (
            ! $document->path
            || ! Storage::disk('local')->exists($document->path)
        ) {
            Log::warning('Proposal OCR prefill: scan payload file missing', [
                'scan_document_id' => $document->id,
            ]);

            return [];
        }

        $raw = Storage::disk('local')->get($document->path);
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractOcrText(array $payload): string
    {
        $main = (string) ($payload['extracted_text'] ?? '');

        if (trim($main) !== '') {
            return mb_substr($main, 0, self::MAX_OCR_TEXT_CHARS);
        }

        $pages = $payload['extracted_pages'] ?? [];

        if (! is_array($pages)) {
            return '';
        }

        $combined = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $text = trim((string) ($page['text'] ?? ''));

            if ($text !== '') {
                $combined[] = $text;
            }
        }

        return mb_substr(
            implode("\n\n", $combined),
            0,
            self::MAX_OCR_TEXT_CHARS
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function callVertexAndParse(string $ocrText): array
    {
        $config = $this->resolveVertexConfig();
        $accessToken = $this->getVertexAccessToken(
            $config['credentials_path']
        );

        $url = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/endpoints/%s:generateContent',
            rawurlencode($config['location']),
            rawurlencode($config['project_id']),
            rawurlencode($config['location']),
            rawurlencode($config['endpoint_id'])
        );

        Log::info('Proposal OCR prefill: sending tuned Vertex request', [
            'project_id' => $config['project_id'],
            'location' => $config['location'],
            'endpoint_id' => $config['endpoint_id'],
            'url' => $url,
        ]);

        $response = Http::timeout(75)
            ->retry(2, 700, throw: false)
            ->withToken($accessToken)
            ->acceptJson()
            ->post($url, [
                'systemInstruction' => [
                    'parts' => [[
                        'text' => 'Si asistent pre zdravotnícky systém. Vráť výlučne validný JSON bez markdownu.',
                    ]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $this->buildPrompt($ocrText),
                    ]],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.1,
                    'maxOutputTokens' => 4096,
                ],
            ]);

        if (! $response->successful()) {
            $googleMessage = trim((string) data_get(
                $response->json(),
                'error.message',
                ''
            ));

            Log::error('Proposal OCR prefill: tuned Vertex request failed', [
                'status' => $response->status(),
                'project_id' => $config['project_id'],
                'location' => $config['location'],
                'endpoint_id' => $config['endpoint_id'],
                'google_error' => $googleMessage,
            ]);

            $message = sprintf(
                'Trénovaný Proposal model vrátil chybu HTTP %d.',
                $response->status()
            );

            if ($googleMessage !== '') {
                $message .= ' '.$googleMessage;
            }

            throw new \RuntimeException($message);
        }

        $decodedResponse = $response->json();

        if (! is_array($decodedResponse)) {
            throw new \RuntimeException(
                'Trénovaný Proposal model vrátil neplatnú odpoveď.'
            );
        }

        $text = trim((string) data_get(
            $decodedResponse,
            'candidates.0.content.parts.0.text',
            ''
        ));

        if ($text === '') {
            throw new \RuntimeException(
                'Trénovaný Proposal model nevrátil použiteľné dáta.'
            );
        }

        $decodedJson = json_decode($text, true);

        if (! is_array($decodedJson)) {
            Log::warning('Proposal OCR prefill: tuned model returned invalid JSON', [
                'response_chars' => mb_strlen($text),
            ]);

            throw new \RuntimeException(
                'Trénovaný Proposal model vrátil neplatný JSON.'
            );
        }

        return $this->normalizeGeminiOutput($decodedJson);
    }

    /**
     * @return array{
     *     credentials_path: string,
     *     project_id: string,
     *     location: string,
     *     endpoint_id: string
     * }
     */
    private function resolveVertexConfig(): array
    {
        $credentialsPath = trim((string) config(
            'services.vertex_ai.credentials_path'
        ));

        $projectId = trim((string) config(
            'services.vertex_ai.project_id'
        ));

        $location = trim((string) config(
            'services.vertex_ai.proposal.location'
        ));

        $endpointId = trim((string) config(
            'services.vertex_ai.proposal.endpoint_id'
        ));

        if ($credentialsPath === '') {
            throw new \RuntimeException(
                'Chýba nastavenie GOOGLE_APPLICATION_CREDENTIALS.'
            );
        }

        if (! is_file($credentialsPath)) {
            throw new \RuntimeException(
                'Súbor so service account credentials pre Vertex AI sa nenašiel.'
            );
        }

        if (! is_readable($credentialsPath)) {
            throw new \RuntimeException(
                'Súbor so service account credentials pre Vertex AI nie je čitateľný.'
            );
        }

        if ($projectId === '') {
            throw new \RuntimeException(
                'Chýba nastavenie VERTEX_PROJECT_ID.'
            );
        }

        if ($location === '') {
            throw new \RuntimeException(
                'Chýba nastavenie VERTEX_PROPOSAL_LOCATION.'
            );
        }

        if ($endpointId === '') {
            throw new \RuntimeException(
                'Chýba nastavenie VERTEX_PROPOSAL_ENDPOINT_ID.'
            );
        }

        return [
            'credentials_path' => $credentialsPath,
            'project_id' => $projectId,
            'location' => $location,
            'endpoint_id' => $endpointId,
        ];
    }

    private function getVertexAccessToken(string $credentialsPath): string
    {
        $json = json_decode(
            (string) file_get_contents($credentialsPath),
            true
        );

        if (! is_array($json)) {
            throw new \RuntimeException(
                'Service account credentials JSON je neplatný.'
            );
        }

        $clientEmail = (string) ($json['client_email'] ?? '');
        $privateKey = (string) ($json['private_key'] ?? '');

        if ($clientEmail === '' || $privateKey === '') {
            throw new \RuntimeException(
                'Service account credentials JSON neobsahuje client_email alebo private_key.'
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
        $unsignedJwt = $header.'.'.$claimSet;
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
            .'.'
            .$this->base64UrlEncode($signature);

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
                $message .= ' '.$googleMessage;
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

    private function buildPrompt(string $ocrText): string
    {
        $frequencyValues = implode('"|"', ClinicalFrequency::canonicalValues());

        return "Si asistent pre zdravotnícky systém. Z OCR textu extrahuj údaje pre návrh ošetrovateľskej starostlivosti.\n"
            ."Vráť výlučne JSON bez markdownu a bez ďalšieho textu.\n"
            ."Použi presne tento formát:\n"
            ."{\n"
            ."  \"medical_diagnosis_codes\": string[],\n"
            ."  \"nurse_diagnosis_codes\": string[],\n"
            ."  \"epicrisis_description\": string,\n"
            ."  \"care_plan\": string,\n"
            ."  \"patient_mobility\": (\"H\"|\"I\"|\"F\")[],\n"
            ."  \"expected_duration\": (\"one_month\"|\"three_months\"|\"six_months\"|\"over_six_months\"|\"\"),\n"
            ."  \"procedures\": [{ \"code\": string, \"frequency\": (\"{$frequencyValues}\"|\"\") }]\n"
            ."}\n"
            ."Ak niečo nie je možné spoľahlivo zistiť, použi prázdnu hodnotu.\n"
            ."Nedomýšľaj kódy diagnóz ani výkonov.\n\n"
            ."OCR TEXT:\n"
            .$ocrText;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function normalizeGeminiOutput(array $decoded): array
    {
        $medical = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            is_array($decoded['medical_diagnosis_codes'] ?? null)
                ? $decoded['medical_diagnosis_codes']
                : []
        )));

        $nurse = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            is_array($decoded['nurse_diagnosis_codes'] ?? null)
                ? $decoded['nurse_diagnosis_codes']
                : []
        )));

        $allowedMobility = ['H', 'I', 'F'];

        $mobility = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            is_array($decoded['patient_mobility'] ?? null)
                ? $decoded['patient_mobility']
                : []
        ), fn ($value) => in_array($value, $allowedMobility, true)));

        $allowedDuration = [
            'one_month',
            'three_months',
            'six_months',
            'over_six_months',
            '',
        ];

        $duration = trim((string) ($decoded['expected_duration'] ?? ''));

        if (! in_array($duration, $allowedDuration, true)) {
            $duration = '';
        }

        $allowedFrequency = ClinicalFrequency::canonicalValues();

        $procedures = [];

        $rawProcedures = is_array($decoded['procedures'] ?? null)
            ? $decoded['procedures']
            : [];

        foreach ($rawProcedures as $procedure) {
            if (! is_array($procedure)) {
                continue;
            }

            $code = trim((string) ($procedure['code'] ?? ''));
            $frequency = ClinicalFrequency::normalize(trim((string) ($procedure['frequency'] ?? '')));

            if (
                $frequency !== ''
                && ! in_array($frequency, $allowedFrequency, true)
            ) {
                $frequency = '';
            }

            if ($code === '' && $frequency === '') {
                continue;
            }

            $procedures[] = [
                'code' => $code,
                'frequency' => $frequency,
            ];
        }

        return [
            'medical_diagnosis_codes' => array_values(array_unique($medical)),
            'nurse_diagnosis_codes' => array_values(array_unique($nurse)),
            'epicrisis_description' => trim((string) ($decoded['epicrisis_description'] ?? '')),
            'care_plan' => trim((string) ($decoded['care_plan'] ?? '')),
            'patient_mobility' => array_values(array_unique($mobility)),
            'expected_duration' => $duration,
            'procedures' => $procedures,
        ];
    }
}
