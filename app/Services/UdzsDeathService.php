<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class UdzsDeathService
{
    private const TOKEN_CACHE_KEY = 'udzs_api_token';

    public function checkPersonalNumber(string $personalNumber): array
    {
        $normalized = $this->normalizePersonalNumber($personalNumber);
        if ($normalized === '') {
            return [
                'status' => 'unknown',
                'reason' => 'missing_personal_number',
                'data' => null,
            ];
        }

        $token = $this->getToken();
        if (!$token) {
            return [
                'status' => 'unknown',
                'reason' => 'authentication_failed',
                'data' => null,
            ];
        }

        $response = $this->requestDeathCheck($normalized, $token);

        if ($response->status() === 401) {
            $token = $this->getToken(true);
            if ($token) {
                $response = $this->requestDeathCheck($normalized, $token);
            }
        }

        if ($response->status() === 404) {
            return [
                'status' => 'alive',
                'data' => null,
            ];
        }

        if ($response->successful()) {
            return [
                'status' => 'dead',
                'data' => $response->json(),
            ];
        }

        return [
            'status' => 'unknown',
            'reason' => 'unexpected_response',
            'http_status' => $response->status(),
            'data' => $response->json(),
        ];
    }

    private function getToken(bool $forceRefresh = false): ?string
    {
        $ttl = (int) config('services.udzs.token_ttl', 3300);

        if (!$forceRefresh && $ttl > 0) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if (is_string($cached) && trim($cached) !== '') {
                return $cached;
            }
        }

        $email = (string) config('services.udzs.email');
        $password = (string) config('services.udzs.password');
        $baseUrl = rtrim((string) config('services.udzs.base_url'), '/');
        $timeout = (int) config('services.udzs.timeout', 10);
        $cookieSession = config('services.udzs.cookie_session');

        if ($email === '' || $password === '') {
            return null;
        }

        $http = Http::timeout($timeout)
            ->acceptJson();
        if ($cookieSession) {
            $http = $http->withHeaders([
                'Cookie' => 'cookiesession1=' . $cookieSession,
            ]);
        }

        $authPayload = [
            'email' => $email,
            'password' => $password,
        ];

        $response = $http->post($baseUrl . '/User/authenticate', $authPayload);

        if (!$response->successful()) {
            return null;
        }

        $token = $this->extractToken($response);
        if (!$token) {
            return null;
        }

        if ($ttl > 0) {
            Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($ttl));
        }

        return $token;
    }

    private function requestDeathCheck(string $personalNumber, string $token): Response
    {
        $baseUrl = rtrim((string) config('services.udzs.base_url'), '/');
        $timeout = (int) config('services.udzs.timeout', 10);
        $cookieSession = config('services.udzs.cookie_session');

        $http = Http::timeout($timeout)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Cookie' => $cookieSession ? 'cookiesession1=' . $cookieSession : '',
            ]);

        return $http->get($baseUrl . '/Umrtia/' . $personalNumber);
    }

    private function extractToken(Response $response): ?string
    {
        $json = $response->json();
        if (is_array($json)) {
            $token = $json['token'] ?? $json['access_token'] ?? $json['accessToken'] ?? null;
            $tokenType = $json['token_type'] ?? $json['tokenType'] ?? null;

            if (is_string($token) && trim($token) !== '') {
                $token = trim($token);
                if (is_string($tokenType) && trim($tokenType) !== '' && stripos($token, $tokenType) !== 0) {
                    $token = trim($tokenType) . ' ' . $token;
                }

                return $token;
            }
        }

        $body = trim((string) $response->body());
        if ($body !== '') {
            return $body;
        }

        return null;
    }

    private function normalizePersonalNumber(string $personalNumber): string
    {
        $normalized = preg_replace('/\s+/', '', trim($personalNumber));
        return $normalized ?: '';
    }
}
