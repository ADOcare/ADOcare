<?php

namespace App\Services\Vertex;

use Illuminate\Support\Facades\Storage;

class VertexAutotrainStateService
{
    /**
     * @return array<string, mixed>
     */
    public function read(string $pipeline = 'dekurz'): array
    {
        $path = $this->statePath($pipeline);

        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $raw = Storage::disk('local')->get($path);
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function writeAtomic(array $state, string $pipeline = 'dekurz'): void
    {
        $path = $this->statePath($pipeline);
        $directory = trim((string) dirname($path), '.');

        if ($directory !== '' && ! Storage::disk('local')->exists($directory)) {
            Storage::disk('local')->makeDirectory($directory);
        }

        $tmpPath = $path . '.tmp.' . now()->timestamp . '-' . bin2hex(random_bytes(4));
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new \RuntimeException('Nepodarilo sa serializovať state.json.');
        }

        Storage::disk('local')->put($tmpPath, $json);

        $tmpRaw = Storage::disk('local')->get($tmpPath);
        $tmpDecoded = json_decode((string) $tmpRaw, true);

        if (! is_array($tmpDecoded)) {
            Storage::disk('local')->delete($tmpPath);
            throw new \RuntimeException('Dočasný state JSON je neplatný.');
        }

        $absoluteTmp = Storage::disk('local')->path($tmpPath);
        $absoluteTarget = Storage::disk('local')->path($path);

        if (! @rename($absoluteTmp, $absoluteTarget)) {
            Storage::disk('local')->delete($tmpPath);
            throw new \RuntimeException('Nepodarilo sa atomicky prepnúť state.json.');
        }
    }

    public function statePath(string $pipeline = 'dekurz'): string
    {
        if ($pipeline === 'proposal') {
            return trim((string) config('services.vertex_ai.proposal.state_path', 'ai/proposal-autotrain/state.json'));
        }

        return trim((string) config('services.vertex_ai.auto_train.state_path', 'ai/dekurz-autotrain/state.json'));
    }
}
