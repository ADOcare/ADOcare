<?php

namespace App\Services;

use App\Models\InsuranceCompany;
use App\Models\Patient;
use App\Models\PatientCoverage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EoverenieInsuranceService
{
    private ?int $authenticationHttpStatus = null;

    public function lookup(
        string $personalNumber,
        ?string $firstName = null,
        ?string $lastName = null,
    ): array {
        $personalNumber = $this->normalizePersonalNumber($personalNumber);

        if ($personalNumber === '') {
            return $this->unknown('missing_personal_number');
        }

        $entryResult = $this->fetchEntry($personalNumber);

        if (($entryResult['status'] ?? null) !== 'received') {
            return $entryResult;
        }

        $entry = $entryResult['entry'];
        $history = collect(is_array($entry['history'] ?? null) ? $entry['history'] : []);
        $currentHistory = $history->first(fn (array $item) => empty($item['validTo']))
            ?? $history->sortByDesc('validFrom')->first();
        $registeredCode = $this->normalizeInsuranceCode(data_get($currentHistory, 'code'));
        $registeredName = (string) (
            $entry['insuranceCompany']
            ?? data_get($currentHistory, 'insuranceCompany')
            ?? ''
        );
        $insured = ($entry['success'] ?? false) === true
            && ($registeredCode !== '' || trim($registeredName) !== '');

        if (! $insured) {
            return [
                'status' => 'not_insured',
                'insured' => false,
                'matches_patient_name' => null,
                'identification_number' => $entry['identificationNumber'] ?? $personalNumber,
                'patient_name' => $entry['name'] ?? null,
                'registered_insurance_company' => null,
                'history' => $history->values()->all(),
            ];
        }

        $localCompany = $registeredCode !== ''
            ? InsuranceCompany::query()->where('code', $registeredCode)->first()
            : null;
        $registeredPatientName = trim((string) ($entry['name'] ?? ''));
        $enteredPatientName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
        $matchesPatientName = $enteredPatientName === ''
            || $registeredPatientName === ''
            || $this->normalizeText($registeredPatientName) === $this->normalizeText($enteredPatientName);
        $hasDuplicity = (bool) ($entry['hasDuplicity'] ?? false);

        $status = match (true) {
            $hasDuplicity => 'duplicity',
            ! $matchesPatientName => 'identity_mismatch',
            default => 'found',
        };

        return [
            'status' => $status,
            'insured' => true,
            'matches_patient_name' => $matchesPatientName,
            'identification_number' => $entry['identificationNumber'] ?? $personalNumber,
            'patient_name' => $entry['name'] ?? null,
            'valid_from' => $this->dateOnly(
                data_get($currentHistory, 'validFrom') ?? $entry['validFrom'] ?? null
            ),
            'valid_to' => $this->dateOnly(data_get($currentHistory, 'validTo')),
            'last_changed' => $entry['lastChanged'] ?? null,
            'has_duplicity' => $hasDuplicity,
            'registered_insurance_company' => $this->companyData(
                $localCompany?->id,
                $registeredCode,
                $registeredName,
            ),
            'local_insurance_company_found' => $localCompany !== null,
            'history' => $history->values()->all(),
        ];
    }

    public function verify(Patient $patient, ?PatientCoverage $coverage): array
    {
        if (! $coverage) {
            return $this->unknown('missing_coverage');
        }

        $category = $coverage->category?->value ?? $coverage->category;

        if ($category !== 'domestic') {
            return [
                'status' => 'not_applicable',
                'reason' => 'non_domestic_coverage',
                'insured' => null,
                'matches_saved_insurance' => null,
            ];
        }

        $result = $this->lookup(
            (string) $patient->personal_number,
            (string) $patient->first_name,
            (string) $patient->last_name,
        );

        if (($result['status'] ?? null) !== 'found') {
            $result['matches_saved_insurance'] = false;

            return $result;
        }

        $savedCompany = $coverage->insuranceCompany;
        $savedCode = $this->normalizeInsuranceCode($savedCompany?->code);
        $savedName = (string) ($savedCompany?->name ?? '');
        $registeredCompany = $result['registered_insurance_company'] ?? [];
        $registeredCode = $this->normalizeInsuranceCode($registeredCompany['code'] ?? null);
        $registeredName = (string) ($registeredCompany['name'] ?? '');

        $matches = $registeredCode !== '' && $savedCode !== ''
            ? $registeredCode === $savedCode
            : $this->normalizeText($registeredName) === $this->normalizeText($savedName);

        $result['status'] = $matches ? 'verified' : 'mismatch';
        $result['matches_saved_insurance'] = $matches;
        $result['saved_insurance_company'] = $this->companyData(
            $savedCompany?->id,
            $savedCode,
            $savedName,
        );

        return $result;
    }

    public function verifyInput(
        string $personalNumber,
        ?string $firstName,
        ?string $lastName,
        ?int $insuranceCompanyId,
        ?string $category,
    ): array {
        if ($category !== 'domestic') {
            return [
                'status' => 'not_applicable',
                'reason' => 'non_domestic_coverage',
                'insured' => null,
                'matches_saved_insurance' => null,
                'is_verified' => false,
            ];
        }

        $result = $this->lookup($personalNumber, $firstName, $lastName);

        if (($result['status'] ?? null) !== 'found') {
            $result['matches_saved_insurance'] = false;
            $result['is_verified'] = false;

            return $result;
        }

        $savedCompany = $insuranceCompanyId
            ? InsuranceCompany::query()->find($insuranceCompanyId)
            : null;
        $savedCode = $this->normalizeInsuranceCode($savedCompany?->code);
        $savedName = (string) ($savedCompany?->name ?? '');
        $registeredCompany = $result['registered_insurance_company'] ?? [];
        $registeredCode = $this->normalizeInsuranceCode($registeredCompany['code'] ?? null);
        $registeredName = (string) ($registeredCompany['name'] ?? '');

        $matches = $savedCompany !== null && (
            $registeredCode !== '' && $savedCode !== ''
                ? $registeredCode === $savedCode
                : $this->normalizeText($registeredName) === $this->normalizeText($savedName)
        );

        $result['status'] = $matches ? 'verified' : 'mismatch';
        $result['matches_saved_insurance'] = $matches;
        $result['is_verified'] = $matches;
        $result['saved_insurance_company'] = $this->companyData(
            $savedCompany?->id,
            $savedCode,
            $savedName,
        );

        return $result;
    }

    private function fetchEntry(string $personalNumber): array
    {
        if (! $this->hasCredentials()) {
            Log::warning('[EOVERENIE] Missing EOVERENIE_EMAIL or EOVERENIE_PASSWORD configuration.');

            return $this->unknown('configuration_missing');
        }

        try {
            $token = $this->getToken();
        } catch (ConnectionException $exception) {
            Log::warning('[EOVERENIE] Login endpoint connection failed.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->unknown('connection_failed');
        }

        if (! $token) {
            if ($this->authenticationHttpStatus === null) {
                Log::warning('[EOVERENIE] Authentication did not produce an HTTP response. Restart the PHP worker after clearing the Laravel configuration cache.');

                return $this->unknown('authentication_not_attempted');
            }

            $result = $this->unknown('authentication_failed');
            $result['http_status'] = $this->authenticationHttpStatus;

            return $result;
        }

        try {
            $response = $this->requestInsuranceCheck($personalNumber, $token);

            if ($response->status() === 401) {
                $token = $this->getToken(true);

                if ($token) {
                    $response = $this->requestInsuranceCheck($personalNumber, $token);
                }
            }
        } catch (ConnectionException $exception) {
            Log::warning('[EOVERENIE] Insurance endpoint connection failed.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->unknown('connection_failed');
        }

        if (! $response->successful()) {
            Log::warning('[EOVERENIE] Insurance endpoint returned an unsuccessful response.', [
                'http_status' => $response->status(),
            ]);

            return $this->unknown('unexpected_response', $response);
        }

        $payload = $response->json();
        $entry = is_array($payload) && isset($payload[0]) && is_array($payload[0])
            ? $payload[0]
            : null;

        if (! $entry) {
            Log::warning('[EOVERENIE] Insurance endpoint returned an invalid response structure.');

            return $this->unknown('invalid_response');
        }

        return [
            'status' => 'received',
            'entry' => $entry,
        ];
    }

    private function getToken(bool $forceRefresh = false): ?string
    {
        $email = trim((string) config('services.eoverenie.email'));
        $password = (string) config('services.eoverenie.password');
        $ttl = (int) config('services.eoverenie.token_ttl', 3300);
        $cacheKey = 'eoverenie_api_token:' . sha1($email);

        if (! $forceRefresh && $ttl > 0) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && trim($cached) !== '') {
                return $cached;
            }
        }

        if ($email === '' || $password === '') {
            return null;
        }

        $response = Http::timeout((int) config('services.eoverenie.timeout', 10))
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl() . '/login', [
                'username' => $email,
                'password' => $password,
            ]);

        $this->authenticationHttpStatus = $response->status();

        if (! $response->successful()) {
            Log::warning('[EOVERENIE] Login endpoint rejected authentication.', [
                'http_status' => $response->status(),
            ]);

            return null;
        }

        $token = $response->json('token');

        if (! is_string($token) || trim($token) === '') {
            Log::warning('[EOVERENIE] Login endpoint response does not contain a token.');

            return null;
        }

        $token = trim($token);

        if ($ttl > 0) {
            Cache::put($cacheKey, $token, now()->addSeconds($ttl));
        }

        return $token;
    }

    private function requestInsuranceCheck(string $personalNumber, string $token): Response
    {
        return Http::timeout((int) config('services.eoverenie.timeout', 10))
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->post($this->baseUrl() . '/insurance', [$personalNumber]);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.eoverenie.base_url'), '/');
    }

    private function hasCredentials(): bool
    {
        return trim((string) config('services.eoverenie.email')) !== ''
            && (string) config('services.eoverenie.password') !== '';
    }

    private function normalizePersonalNumber(string $personalNumber): string
    {
        return preg_replace('/[\s\/]+/', '', trim($personalNumber)) ?: '';
    }

    private function normalizeInsuranceCode(mixed $code): string
    {
        return strtoupper(trim((string) $code));
    }

    private function normalizeText(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($value))) ?: '';
    }

    private function dateOnly(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function companyData(?int $id, string $code, string $name): array
    {
        return [
            'id' => $id,
            'code' => $code !== '' ? $code : null,
            'name' => $name !== '' ? $name : null,
        ];
    }

    private function unknown(string $reason, ?Response $response = null): array
    {
        return [
            'status' => 'unknown',
            'reason' => $reason,
            'http_status' => $response?->status(),
            'insured' => null,
            'matches_patient_name' => null,
            'matches_saved_insurance' => null,
        ];
    }
}
