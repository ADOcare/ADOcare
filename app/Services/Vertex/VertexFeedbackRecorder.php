<?php

namespace App\Services\Vertex;

use App\Models\DekurzAiFeedback;
use App\Models\Document;
use App\Models\Patient;
use App\Models\ProposalAiFeedback;
use App\Services\ProposalOcrPrefillService;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stores nurse-approved documents as training examples for the Vertex fine-tuning pipelines.
 * Recording never interrupts the user flow; failures are only reported.
 */
class VertexFeedbackRecorder
{
    public const DEKURZ_SOURCE = 'dekurz_v2';

    public const PROPOSAL_SOURCE = 'proposal_store';

    private const PROPOSAL_SCAN_MAX_AGE_DAYS = 90;

    public function __construct(
        private readonly VertexSuggestionCache $suggestions,
        private readonly ProposalOcrPrefillService $proposalPrefill
    ) {}

    /**
     * @param  array<string, mixed>|null  $snapshot  Finalized Dekurz JSON snapshot; read from storage when omitted.
     */
    public function recordDekurz(Document $document, ?array $snapshot = null, bool $useCachedSuggestion = true): ?DekurzAiFeedback
    {
        try {
            if (DekurzAiFeedback::query()->where('document_id', $document->id)->exists()) {
                return null;
            }

            $finalSections = $this->sectionsFromSnapshot($snapshot ?? $this->readJson($document));
            $proposal = $this->latestDocumentBefore((int) $document->patient_id, 'proposal', $document);

            if ($finalSections === [] || ! $proposal) {
                return null;
            }

            $cached = $useCachedSuggestion ? $this->suggestions->pull('dekurz', (int) $document->patient_id) : null;
            $suggestedSections = $this->cleanSections($cached['sections'] ?? []);
            $suggestedText = $this->joinSections($suggestedSections);
            $finalText = $this->joinSections($finalSections);

            return DekurzAiFeedback::create([
                'document_id' => $document->id,
                'patient_id' => $document->patient_id,
                'user_id' => $document->user_id,
                'branch_id' => $document->branch_id,
                'proposal_document_id' => $proposal->id,
                'source' => self::DEKURZ_SOURCE,
                'suggested_sections' => $suggestedSections,
                'final_sections' => $finalSections,
                'has_user_edits' => $this->normalize($suggestedText) !== $this->normalize($finalText),
                'suggestion_char_count' => mb_strlen($suggestedText),
                'final_char_count' => mb_strlen($finalText),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function recordProposal(Document $document, Patient $patient, bool $useCachedSuggestion = true): ?ProposalAiFeedback
    {
        try {
            if (ProposalAiFeedback::query()->where('document_id', $document->id)->exists()) {
                return null;
            }

            $cached = $useCachedSuggestion ? $this->suggestions->pull('proposal', (int) $patient->id) : null;
            $createdAt = $document->created_at ?? now();
            $scan = $this->proposalPrefill->latestScanOcr($patient, $createdAt);

            if (! $scan || $scan['document']->created_at?->lt($createdAt->copy()->subDays(self::PROPOSAL_SCAN_MAX_AGE_DAYS))) {
                return null;
            }

            return ProposalAiFeedback::create([
                'document_id' => $document->id,
                'patient_id' => $patient->id,
                'user_id' => $document->user_id,
                'scan_document_id' => $scan['document']->id,
                'source' => self::PROPOSAL_SOURCE,
                'ocr_text' => $scan['ocr_text'],
                'suggestion' => $cached['prefill'] ?? null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<int, array{text: string}>
     */
    private function sectionsFromSnapshot(?array $snapshot): array
    {
        if (! $snapshot) {
            return [];
        }

        $sections = $this->cleanSections($snapshot['narrative_sections'] ?? []);

        if ($sections === []) {
            $sections = $this->cleanSections($snapshot['sections'] ?? []);
        }

        return $sections !== [] ? $sections : $this->cleanSections($snapshot['days'] ?? []);
    }

    /**
     * @return array<int, array{text: string}>
     */
    private function cleanSections(mixed $sections): array
    {
        return collect(is_array($sections) ? $sections : [])
            ->map(fn ($section): string => trim((string) (is_array($section) ? ($section['text'] ?? '') : '')))
            ->filter()
            ->unique()
            ->map(fn (string $text): array => ['text' => $text])
            ->values()
            ->all();
    }

    private function latestDocumentBefore(int $patientId, string $type, Document $reference): ?Document
    {
        return Document::query()
            ->where('patient_id', $patientId)
            ->where('type', $type)
            ->where('created_at', '<=', $reference->created_at ?? now())
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(Document $document): ?array
    {
        if (! $document->path || ! Storage::disk('local')->exists($document->path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get($document->path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<int, array{text: string}>  $sections
     */
    private function joinSections(array $sections): string
    {
        return collect($sections)->pluck('text')->implode("\n\n");
    }

    private function normalize(string $text): string
    {
        $normalized = mb_strtolower(trim($text));

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }
}
