<?php

namespace App\Services\Vertex;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers the last AI suggestion per patient so the final, nurse-approved
 * document can be stored together with it as training feedback.
 */
class VertexSuggestionCache
{
    private const TTL_DAYS = 45;

    /**
     * @param  array<string, mixed>  $suggestion
     */
    public function remember(string $pipeline, int $patientId, array $suggestion): void
    {
        Cache::put($this->key($pipeline, $patientId), $suggestion, now()->addDays(self::TTL_DAYS));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pull(string $pipeline, int $patientId): ?array
    {
        $value = Cache::pull($this->key($pipeline, $patientId));

        return is_array($value) ? $value : null;
    }

    private function key(string $pipeline, int $patientId): string
    {
        return "vertex-suggestion:{$pipeline}:{$patientId}";
    }
}
