<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class DekurzSupportingDocumentService
{
    /**
     * @param Collection<int, \App\Models\PatientPoint> $points
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
            'finding' => $this->selectFinding($patient, $month, $referenceDates->unique()->sort()->values()->all(), $dominantReferenceDate),
            'proposal' => $this->selectProposal($patient, $month),
            'previous_dekurz' => $this->selectPreviousDekurz($patient, $month),
        ];
    }

    /**
     * @param list<string> $referenceDates
     * @return array<string, mixed>
     */
    private function selectFinding(Patient $patient, string $month, array $referenceDates, ?string $dominantReferenceDate): array
    {
        $documents = Document::query()
            ->where('patient_id', $patient->id)
            ->where('type', 'scan')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $candidates = $documents
            ->map(function (Document $document) use ($month, $referenceDates): array {
                $payload = $this->readPayload($document);
                $date = $this->payloadDate($payload['scanned_at'] ?? null, $document->created_at);
                $eligible = Carbon::parse($date)->lte(Carbon::createFromFormat('!Y-m', $month)->endOfMonth());
                $match = in_array($date, $referenceDates, true)
                    ? 'exact_reference_date'
                    : (str_starts_with($date, $month)
                        ? 'requested_month'
                        : ($eligible ? 'latest_before_month_end' : 'future_candidate'));

                return $this->metadata($document, 'scan', $date, 'scanned_at_or_created_at', $match) + [
                    '_score' => $match === 'exact_reference_date' ? 300 : ($match === 'requested_month' ? 200 : ($eligible ? 100 : 0)),
                    '_eligible' => $eligible,
                ];
            })
            ->sortByDesc(fn (array $candidate): string => sprintf('%03d-%s', $candidate['_score'], $candidate['document_date']))
            ->values();

        $default = $candidates->first(fn (array $candidate): bool => $candidate['_eligible']);

        return [
            'dominant_reference_date' => $dominantReferenceDate,
            'reference_dates' => $referenceDates,
            'default' => $default ? $this->withoutInternalFields($default) : null,
            'candidates' => $candidates->map(fn (array $candidate): array => $this->withoutInternalFields($candidate))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function selectProposal(Patient $patient, string $month): array
    {
        $monthEnd = Carbon::createFromFormat('!Y-m', $month)->endOfMonth();
        $documents = Document::query()
            ->where('patient_id', $patient->id)
            ->where('type', 'proposal')
            ->orderByDesc('period')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $candidates = $documents->map(function (Document $document) use ($month, $monthEnd): array {
            $payload = $this->readPayload($document);
            $date = $this->payloadDate($payload['date'] ?? null, $document->created_at);
            $eligible = Carbon::parse($date)->lte($monthEnd);
            $match = str_starts_with($date, $month)
                ? 'requested_month'
                : ($eligible ? 'latest_before_month_end' : 'future_candidate');

            return $this->metadata($document, 'proposals', $date, isset($payload['date']) ? 'proposal_payload' : 'created_at', $match) + [
                '_eligible' => $eligible,
            ];
        })->values();

        $default = $candidates->first(fn (array $candidate): bool => $candidate['_eligible']);

        return [
            'default' => $default ? $this->withoutInternalFields($default) : null,
            'candidates' => $candidates->map(fn (array $candidate): array => $this->withoutInternalFields($candidate))->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function selectPreviousDekurz(Patient $patient, string $month): ?array
    {
        $document = Document::query()
            ->where('patient_id', $patient->id)
            ->where('type', 'dekurz')
            ->where('period', '<', $month)
            ->orderByDesc('period')
            ->orderByDesc('created_at')
            ->first();

        if (!$document) {
            return null;
        }

        return $this->metadata(
            $document,
            'dekurz',
            $document->period ? $document->period.'-01' : $document->created_at->toDateString(),
            $document->period ? 'document_period' : 'created_at',
            'latest_strictly_before_month',
        );
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

    /** @return array<string, mixed> */
    private function readPayload(Document $document): array
    {
        try {
            if (!$document->path || !Storage::disk('local')->exists($document->path)) {
                return [];
            }

            $payload = json_decode(Storage::disk('local')->get($document->path), true);

            return is_array($payload) ? $payload : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function payloadDate($value, $fallback): string
    {
        try {
            return Carbon::parse($value ?: $fallback)->toDateString();
        } catch (\Throwable) {
            return Carbon::parse($fallback)->toDateString();
        }
    }

    /** @param array<string, mixed> $candidate */
    private function withoutInternalFields(array $candidate): array
    {
        unset($candidate['_eligible'], $candidate['_score']);

        return $candidate;
    }
}