<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class DekurzSupportingDocumentService
{
    /**
     * @param  Collection<int, \App\Models\PatientPoint>  $points
     * @return array<string, mixed>
     */
    public function forBuilder(Patient $patient, string $month, Collection $points): array
    {
        $referenceDates = $points
            ->pluck('reference_date')
            ->filter()
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->values();
        $referenceCounts = $referenceDates->countBy()->sortDesc();
        $dominantReferenceDate = $referenceCounts->keys()->first();

        return [
            'finding' => [
                'dominant_reference_date' => $dominantReferenceDate,
                'reference_dates' => $referenceDates->unique()->sort()->values()->all(),
                ...$this->selectLatestByPeriod($patient, 'scan', 'scan'),
            ],
            'proposal' => $this->selectLatestByPeriod($patient, 'proposal', 'proposals'),
            'previous_dekurz' => $this->selectLatestByPeriod($patient, 'dekurz', 'dekurz')['default'],
        ];
    }

    /** @return array{default: array<string, mixed>|null, candidates: list<array<string, mixed>>} */
    private function selectLatestByPeriod(Patient $patient, string $type, string $routePrefix): array
    {
        $documents = Document::query()
            ->where('patient_id', $patient->id)
            ->where('type', $type)
            ->whereNotNull('period')
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $candidates = $documents
            ->map(fn (Document $document): array => $this->metadata(
                $document,
                $routePrefix,
                $document->period.'-01',
                'document_period',
                'latest_by_period',
            ))
            ->values();

        return [
            'default' => $candidates->first(),
            'candidates' => $candidates->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function metadata(Document $document, string $routePrefix, string $date, string $dateSource, string $match): array
    {
        $base = "/v1/{$routePrefix}/{$document->id}";

        return [
            'id' => $document->id,
            'type' => $document->type,
            'name' => $document->name,
            'period' => $document->period,
            'document_date' => $date,
            'date_source' => $dateSource,
            'match' => $match,
            'created_at' => $document->created_at?->toISOString(),
            'show_url' => $base,
            'preview_url' => $base.'/preview',
            'download_url' => $base.'/download',
        ];
    }
}
