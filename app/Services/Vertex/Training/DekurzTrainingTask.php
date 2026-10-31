<?php

namespace App\Services\Vertex\Training;

use App\Models\DekurzAiFeedback;
use App\Models\Document;
use App\Services\Vertex\DekurzPromptBuilder;
use Illuminate\Support\Facades\Storage;

class DekurzTrainingTask implements VertexTrainingTask
{
    public const SYSTEM_INSTRUCTION = 'You are a nursing documentation assistant. Return valid JSON only.';

    public function __construct(private readonly DekurzPromptBuilder $promptBuilder) {}

    public function pipeline(): string
    {
        return 'dekurz';
    }

    public function label(): string
    {
        return 'Dekurz (adocare-dekurz)';
    }

    public function location(): string
    {
        return trim((string) config('services.vertex_ai.dekurz.location'));
    }

    public function staticEndpointId(): string
    {
        return trim((string) config('services.vertex_ai.dekurz.endpoint_id'));
    }

    public function datasetDirectory(): string
    {
        return 'ai/dekurz-autotrain/datasets';
    }

    public function remoteDatasetPrefix(): string
    {
        return trim((string) config('services.vertex_ai.auto_train.dataset_prefix', 'ai/dekurz-feedback'), '/');
    }

    public function systemInstruction(): string
    {
        return self::SYSTEM_INSTRUCTION;
    }

    public function loadRows(array $excludeFeedbackIds = []): array
    {
        $query = DekurzAiFeedback::query()
            ->whereNotNull('proposal_document_id')
            ->orderBy('id');

        $sources = $this->sources();
        if ($sources !== []) {
            $query->whereIn('source', $sources);
        }

        if ($excludeFeedbackIds !== []) {
            $query->whereNotIn('id', $excludeFeedbackIds);
        }

        $rows = [];
        $proposals = [];

        $query->chunkById(250, function ($items) use (&$rows, &$proposals): void {
            foreach ($items as $feedback) {
                $proposalId = (int) $feedback->proposal_document_id;
                $proposals[$proposalId] ??= $this->loadProposalPayload($proposalId);
                $proposal = $proposals[$proposalId];

                $sections = $this->cleanSections($feedback->final_sections ?? []);

                if ($proposal === null || $sections === []) {
                    continue;
                }

                $rows[] = [
                    'feedback_id' => (int) $feedback->id,
                    'input_prompt' => $this->promptBuilder->build($proposal),
                    'expected_output' => (string) json_encode(['sections' => $sections], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        });

        return $rows;
    }

    public function evaluateOutput(string $expectedOutput, array $actual): array
    {
        $sections = $actual['sections'] ?? null;
        $schemaValid = is_array($sections) && $sections !== [] && $this->isValidOutput($actual);

        if (! $schemaValid) {
            return ['schema_valid' => false, 'similarity' => 0.0, 'critical_errors' => 0];
        }

        $expectedText = $this->joinSections(data_get(json_decode($expectedOutput, true), 'sections', []));
        $actualText = $this->joinSections($sections);

        $diagnosisExpected = $this->extractCodes('/\b[A-Z][0-9]{2,4}\b/u', $expectedText);
        $diagnosisActual = $this->extractCodes('/\b[A-Z][0-9]{2,4}\b/u', $actualText);
        $procedureExpected = $this->extractCodes('/\b[0-9]{4}[A-Z]?\b/u', $expectedText);
        $procedureActual = $this->extractCodes('/\b[0-9]{4}[A-Z]?\b/u', $actualText);
        $medsExpected = $this->extractMedicationLikeTokens($expectedText);
        $medsActual = $this->extractMedicationLikeTokens($actualText);

        $similarity = (
            ($this->isSubset($diagnosisExpected, $diagnosisActual) ? 1.0 : 0.0)
            + ($this->isSubset($procedureExpected, $procedureActual) ? 1.0 : 0.0)
            + ($this->isSubset($medsExpected, $medsActual) ? 1.0 : 0.0)
            + $this->wordOverlap($expectedText, $actualText)
        ) / 4;

        $inventedCodes = array_diff($diagnosisActual, $diagnosisExpected) !== []
            || array_diff($procedureActual, $procedureExpected) !== [];

        return [
            'schema_valid' => true,
            'similarity' => $similarity,
            'critical_errors' => $inventedCodes ? 1 : 0,
        ];
    }

    public function smokePrompt(): string
    {
        return $this->promptBuilder->build([
            'diagnosis' => ['I10'],
            'nurse_diagnosis' => ['A110'],
            'epicrisis' => 'Stabilizovaný stav',
            'care_plan' => 'Monitoring',
            'mobility' => ['I'],
            'expected_duration' => 'one_month',
            'procedures' => [['code' => '3439', 'frequency' => 'daily']],
        ]);
    }

    public function isValidOutput(array $decoded): bool
    {
        $sections = $decoded['sections'] ?? null;

        return is_array($sections)
            && $sections !== []
            && collect($sections)->every(fn ($section) => is_array($section) && trim((string) ($section['text'] ?? '')) !== '');
    }

    /**
     * @return array<int, string>
     */
    private function sources(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.vertex_ai.auto_train.source', ''))
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadProposalPayload(int $proposalDocumentId): ?array
    {
        $document = Document::query()->find($proposalDocumentId);

        if (! $document || ! $document->path || ! Storage::disk('local')->exists($document->path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get($document->path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  mixed  $sections
     * @return array<int, array{text: string}>
     */
    private function cleanSections(mixed $sections): array
    {
        return collect(is_array($sections) ? $sections : [])
            ->map(fn ($section) => ['text' => trim((string) (is_array($section) ? ($section['text'] ?? '') : ''))])
            ->filter(fn (array $section) => $section['text'] !== '')
            ->values()
            ->all();
    }

    private function joinSections(mixed $sections): string
    {
        return collect($this->cleanSections($sections))->pluck('text')->implode("\n\n");
    }

    /**
     * @return array<int, string>
     */
    private function extractCodes(string $pattern, string $text): array
    {
        preg_match_all($pattern, mb_strtoupper($text), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * @return array<int, string>
     */
    private function extractMedicationLikeTokens(string $text): array
    {
        preg_match_all('/\b[A-Z][A-Z0-9\-]{3,}\b/u', mb_strtoupper($text), $matches);

        return array_values(array_unique(array_filter($matches[0] ?? [], fn (string $token) => ! is_numeric($token))));
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $actual
     */
    private function isSubset(array $expected, array $actual): bool
    {
        return $expected === [] || array_diff($expected, $actual) === [];
    }

    private function wordOverlap(string $expectedText, string $actualText): float
    {
        $words = fn (string $text) => collect(preg_split('/\s+/u', mb_strtolower(trim($text))) ?: [])
            ->filter(fn ($word) => mb_strlen($word) >= 4)
            ->values()
            ->all();

        $expectedWords = $words($expectedText);

        if ($expectedWords === []) {
            return 1.0;
        }

        return count(array_intersect($expectedWords, $words($actualText))) / count($expectedWords);
    }
}
