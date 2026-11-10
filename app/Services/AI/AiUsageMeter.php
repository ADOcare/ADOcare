<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stores metadata only: never prompts, source documents, or model outputs. */
class AiUsageMeter
{
    public function begin(
        string $workflow,
        string $model,
        string $location,
        ?int $companyId,
        ?int $userId,
        ?int $documentId,
        string $billingContext = 'customer'
    ): string {
        $requestId = (string) Str::uuid();

        DB::table('ai_usage_events')->insert([
            'request_id' => $requestId,
            'workflow' => $workflow,
            'model' => $model,
            'location' => $location,
            'company_id' => $companyId,
            'user_id' => $userId,
            'document_id' => $documentId,
            'billing_context' => $billingContext,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $requestId;
    }

    /** @param array<string, mixed> $response */
    public function complete(string $requestId, array $response, int $latencyMs, string $model, string $status): void
    {
        $usage = data_get($response, 'usageMetadata', []);
        $usage = is_array($usage) ? $usage : [];
        $input = isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null;
        $output = isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null;
        $thoughts = isset($usage['thoughtsTokenCount']) ? (int) $usage['thoughtsTokenCount'] : null;
        $cached = isset($usage['cachedContentTokenCount']) ? (int) $usage['cachedContentTokenCount'] : null;

        DB::table('ai_usage_events')->where('request_id', $requestId)->update([
            'status' => $status,
            'provider_response_id' => data_get($response, 'responseId'),
            'prompt_tokens' => $input,
            'candidate_tokens' => $output,
            'thought_tokens' => $thoughts,
            'cached_prompt_tokens' => $cached,
            'total_tokens' => isset($usage['totalTokenCount']) ? (int) $usage['totalTokenCount'] : null,
            'estimated_cost_usd' => $this->estimatedCost($model, $input, $output, $thoughts, $cached),
            'http_status' => 200,
            'latency_ms' => $latencyMs,
            'updated_at' => now(),
        ]);
    }

    public function fail(string $requestId, string $errorCode, ?int $httpStatus, int $latencyMs): void
    {
        DB::table('ai_usage_events')->where('request_id', $requestId)->update([
            'status' => 'failed',
            'error_code' => mb_substr($errorCode, 0, 64),
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs,
            'updated_at' => now(),
        ]);
    }

    private function estimatedCost(string $model, ?int $input, ?int $output, ?int $thoughts, ?int $cached): ?float
    {
        $prices = config('ai.prices.' . $model);

        if (! is_array($prices) || $input === null || $output === null) {
            return null;
        }

        $inputPrice = $prices['input_per_million_usd'] ?? null;
        $outputPrice = $prices['output_per_million_usd'] ?? null;
        $cachedPrice = $prices['cached_input_per_million_usd'] ?? null;

        if (! is_numeric($inputPrice) || ! is_numeric($outputPrice)) {
            return null;
        }

        if (($cached ?? 0) > 0 && ! is_numeric($cachedPrice)) {
            return null;
        }

        // Vertex promptTokenCount includes cached prompt tokens.
        $normalInput = max(0, $input - ($cached ?? 0));
        $billableOutput = $output + ($thoughts ?? 0);

        return round((
            $normalInput * (float) $inputPrice
            + $billableOutput * (float) $outputPrice
            + ($cached ?? 0) * (float) ($cachedPrice ?? 0)
        ) / 1000000, 8);
    }
}
