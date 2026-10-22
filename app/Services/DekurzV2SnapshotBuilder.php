<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class DekurzV2SnapshotBuilder
{
    public function __construct(
        private DekurzDocumentService $legacyDocuments,
        private DekurzSupportingDocumentService $supportingDocuments,
    ) {}

    /** @param Collection<int, \App\Models\PatientPoint> $points */
    public function build(Patient $patient, DekurzDraft $draft, User $actor, Document $document, Collection $points): array
    {
        $patient->loadMissing(['branch.company', 'insuranceCompany']);
        $draft->loadMissing(['days.entries.procedure', 'days.entries.customTrack']);
        $month = Carbon::parse($draft->period)->format('Y-m');
        $dailyTexts = [];
        $structuredDays = [];

        foreach ($draft->days->sortBy('date') as $day) {
            $entries = $day->entries
                ->reject(fn ($entry): bool => (bool) $entry->is_excluded)
                ->sortBy(fn ($entry): string => sprintf('%05d-%010d', $entry->track_position, $entry->id))
                ->values()
                ->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'track_type' => $entry->isCustomTrack() ? 'custom' : 'procedure',
                    'track_key' => $entry->trackKey(),
                    'track_name' => $entry->isCustomTrack() ? $entry->customTrack?->name : $entry->procedure?->description,
                    'patient_point_snapshot_ids' => $entry->patient_point_snapshot_ids ?? [],
                    'patient_point_ids' => $entry->patient_point_ids ?? [],
                    'procedure' => $entry->isCustomTrack() ? null : [
                        'id' => $entry->procedure_id,
                        'code' => $entry->procedure?->code,
                        'description' => $entry->procedure?->description,
                    ],
                    'custom_track' => $entry->isCustomTrack() ? [
                        'id' => $entry->custom_track_id,
                        'name' => $entry->customTrack?->name,
                    ] : null,
                    'quantity' => $entry->quantity,
                    'text' => (string) $entry->text,
                    'source' => $entry->source,
                    'source_entry_id' => $entry->source_entry_id,
                    'edited_at' => $entry->edited_at?->toISOString(),
                ])->all();

            $parts = collect($entries)->pluck('text')->map(fn ($text): string => trim((string) $text))->filter()->values();
            $date = Carbon::parse($day->date)->toDateString();
            $dailyTexts[$date] = $parts->implode("\n\n");
            $structuredDays[$date] = ['id' => $day->id, 'date' => $date, 'entries' => $entries];
        }

        $renderDays = collect($this->legacyDocuments->attachVisitTimesForPatient(
            $dailyTexts,
            (int) $patient->id,
            (int) $actor->id,
            (int) $draft->branch_id,
        ))->map(function (array $day) use ($structuredDays): array {
            $structured = $structuredDays[$day['date']];
            return $structured + ['text' => $day['text'], 'terrain_time' => $day['terrain_time'], 'administrative_time' => $day['administrative_time']];
        })->values()->all();

        $company = $patient->branch?->company;
        return [
            'schema_version' => 3,
            'source' => 'dekurz_v2',
            'document_id' => $document->id,
            'draft_id' => $draft->id,
            'created_at' => now()->toISOString(),
            'finalized_at' => now()->toISOString(),
            'user_id' => $actor->id,
            'user_name' => trim(($actor->title ? $actor->title.' ' : '').($actor->first_name ?? '').' '.($actor->last_name ?? '')),
            'company_id' => $company?->id,
            'company_name' => $company?->name ?? '',
            'company_address' => collect([$company?->address, $company?->city])->filter()->implode(', '),
            'branch_id' => $draft->branch_id,
            'insurance_company_id' => $patient->insurance_company_id,
            'insurance_code' => $patient->insuranceCompany?->branch_code ?? '',
            'patient_id' => $patient->id,
            'patient_name' => trim(($patient->title ? $patient->title.' ' : '').($patient->first_name ?? '').' '.($patient->last_name ?? '')),
            'patient_personal_number' => $patient->personal_number,
            'patient_birth_number' => $patient->personal_number,
            'patient_address' => collect([$patient->address, $patient->city, $patient->zip])->filter()->implode(', '),
            'dekurz_number' => max(1, (int) $draft->dekurz_number),
            'month' => $month,
            'period' => $month,
            'days' => $renderDays,
            'scoring_rows' => $points->map(fn ($point): array => [
                'id' => $point->id,
                'date' => Carbon::parse($point->date)->toDateString(),
                'procedure_id' => $point->procedure_id,
                'procedure_code' => $point->procedure_code,
                'quantity' => (int) $point->quantity,
            ])->values()->all(),
            'supporting_documents' => $this->supportingDocuments->forBuilder($patient, $month, $points),
        ];
    }
}
