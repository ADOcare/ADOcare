<?php

namespace App\Services\Vertex\Training;

use App\Models\ProposalAiFeedback;
use App\Services\ProposalOcrPrefillService;
use Illuminate\Support\Facades\Storage;

class ProposalTrainingTask implements VertexTrainingTask
{
    private const REQUIRED_KEYS = [
        'medical_diagnosis_codes',
        'nurse_diagnosis_codes',
        'epicrisis_description',
        'care_plan',
        'patient_mobility',
        'expected_duration',
        'procedures',
    ];

    public function __construct(private readonly ProposalOcrPrefillService $prefillService) {}

    public function pipeline(): string
    {
        return 'proposal';
    }

    public function label(): string
    {
        return 'Návrh (adocare-proposal)';
    }

    public function location(): string
    {
        return trim((string) config('services.vertex_ai.proposal.location'));
    }

    public function staticEndpointId(): string
    {
        return trim((string) config('services.vertex_ai.proposal.endpoint_id'));
    }

    public function datasetDirectory(): string
    {
        return 'ai/proposal-autotrain/datasets';
    }

    public function remoteDatasetPrefix(): string
    {
        return trim((string) config('services.vertex_ai.proposal.dataset_prefix', 'ai/proposal-feedback'), '/');
    }

    public function systemInstruction(): string
    {
        return ProposalOcrPrefillService::SYSTEM_INSTRUCTION;
    }

    public function loadRows(array $excludeFeedbackIds = []): array
    {
        $query = ProposalAiFeedback::query()
            ->with('document:id,path')
            ->orderBy('id');

        if ($excludeFeedbackIds !== []) {
            $query->whereNotIn('id', $excludeFeedbackIds);
        }

        $rows = [];

        $query->chunkById(250, function ($items) use (&$rows): void {
            foreach ($items as $feedback) {
                $expected = $this->expectedOutput($feedback);

                if ($expected === null || trim((string) $feedback->ocr_text) === '') {
                    continue;
                }

                $rows[] = [
                    'feedback_id' => (int) $feedback->id,
                    'input_prompt' => $this->prefillService->buildPrompt((string) $feedback->ocr_text),
                    'expected_output' => (string) json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        });

        return $rows;
    }

    public function evaluateOutput(string $expectedOutput, array $actual): array
    {
        if (! $this->isValidOutput($actual)) {
            return ['schema_valid' => false, 'similarity' => 0.0, 'critical_errors' => 0];
        }

        $expected = json_decode($expectedOutput, true);
        $expected = is_array($expected) ? $expected : [];
        $normalized = $this->prefillService->normalizeGeminiOutput($actual);

        $similarity = (
            $this->f1($expected['medical_diagnosis_codes'] ?? [], $normalized['medical_diagnosis_codes'])
            + $this->f1($expected['nurse_diagnosis_codes'] ?? [], $normalized['nurse_diagnosis_codes'])
            + $this->f1(array_column($expected['procedures'] ?? [], 'code'), array_column($normalized['procedures'], 'code'))
            + $this->f1($expected['patient_mobility'] ?? [], $normalized['patient_mobility'])
            + (($expected['expected_duration'] ?? '') === $normalized['expected_duration'] ? 1.0 : 0.0)
        ) / 5;

        // Values dropped by normalization were outside the allowed enums.
        $invalidEnums = count((array) ($actual['patient_mobility'] ?? [])) !== count($normalized['patient_mobility'])
            || trim((string) ($actual['expected_duration'] ?? '')) !== $normalized['expected_duration'];

        return [
            'schema_valid' => true,
            'similarity' => $similarity,
            'critical_errors' => $invalidEnums ? 1 : 0,
        ];
    }

    public function smokePrompt(): string
    {
        return $this->prefillService->buildPrompt(
            "Prepúšťacia správa\nDiagnóza: I10 Esenciálna hypertenzia, L97 Vred predkolenia.\n"
            ."Odporúčanie: preväz rany 1x denne, aplikácia liečiva subkutánne 2x denne. Pacient chodiaci s pomôckou."
        );
    }

    public function isValidOutput(array $decoded): bool
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $decoded)) {
                return false;
            }
        }

        return is_array($decoded['medical_diagnosis_codes'])
            && is_array($decoded['nurse_diagnosis_codes'])
            && is_array($decoded['patient_mobility'])
            && is_array($decoded['procedures']);
    }

    /**
     * Convert the nurse-approved proposal into the exact output shape of the prefill model.
     *
     * @return array<string, mixed>|null
     */
    private function expectedOutput(ProposalAiFeedback $feedback): ?array
    {
        $path = $feedback->document?->path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $proposal = json_decode((string) Storage::disk('local')->get($path), true);

        if (! is_array($proposal)) {
            return null;
        }

        return $this->prefillService->normalizeGeminiOutput([
            'medical_diagnosis_codes' => $this->codes($proposal['diagnosis'] ?? []),
            'nurse_diagnosis_codes' => $this->codes($proposal['nurse_diagnosis'] ?? []),
            'epicrisis_description' => (string) ($proposal['epicrisis'] ?? ''),
            'care_plan' => (string) ($proposal['care_plan'] ?? ''),
            'patient_mobility' => is_array($proposal['mobility'] ?? null) ? $proposal['mobility'] : [],
            'expected_duration' => (string) ($proposal['expected_duration'] ?? ''),
            'procedures' => is_array($proposal['procedures'] ?? null) ? $proposal['procedures'] : [],
        ]);
    }

    /**
     * Stored diagnoses look like "L97 - Vred predkolenia"; keep only the code.
     *
     * @return array<int, string>
     */
    private function codes(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->map(fn ($value) => trim((string) strtok(is_array($value) ? (string) ($value['code'] ?? '') : (string) $value, ' ')))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $expected
     * @param  array<int, mixed>  $actual
     */
    private function f1(array $expected, array $actual): float
    {
        $expected = array_values(array_unique(array_map('strval', $expected)));
        $actual = array_values(array_unique(array_map('strval', $actual)));

        if ($expected === [] && $actual === []) {
            return 1.0;
        }

        $hits = count(array_intersect($expected, $actual));

        if ($hits === 0) {
            return 0.0;
        }

        $precision = $hits / count($actual);
        $recall = $hits / count($expected);

        return 2 * $precision * $recall / ($precision + $recall);
    }
}
