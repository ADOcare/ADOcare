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
    ) {
    }

    /**
     * @param Collection<int, \App\Models\PatientPoint> $points
     * @return array<string, mixed>
     */
    public function build(
        Patient $patient,
        DekurzDraft $draft,
        User $actor,
        Document $document,
        Collection $points,
    ): array {
        $patient->loadMissing(['branch.company', 'insuranceCompany']);
        $draft->loadMissing(['days.entries.procedure']);
        $month = Carbon::parse($draft->period)->format('Y-m');
        $dailyTexts = [];
        $structuredDays = [];

        foreach ($draft->days->sortBy('date') as $day) {
            $entries = $day->entries
                ->sortBy(fn ($entry): string => sprintf('%s-%010d', $entry->procedure?->code ?? '', $entry->id))
                ->values()
                ->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'patient_point_snapshot_id' => $entry->patient_point_snapshot_id,
                    'patient_point_id' => $entry->patient_point_id,
                    'procedure' => [
                        'id' => $entry->procedure_id,
                        'code' => $entry->procedure?->code,
                        'description' => $entry->procedure?->description,
                    ],
                    'quantity' => $entry->quantity,
                    'text' => (string) $entry->text,
                    'source' => $entry->source,
                    'edited_at' => $entry->edited_at?->toISOString(),
                ])
                ->all();
            $parts = collect($entries)->pluck('text')->map(fn ($text): string => trim((string) $text))->filter()->values();
            if (trim((string) $day->general_note) !== '') {
                $parts->push(trim((string) $day->general_note));
            }
            $date = Carbon::parse($day->date)->toDateString();
            $dailyTexts[$date] = $parts->implode("\n\n");
            $structuredDays[$date] = [
                'id' => $day->id,
                'date' => $date,
                'general_note' => $day->general_note,
                'entries' => $entries,
            ];
        }

        $renderDays = collect($this->legacyDocuments->attachVisitTimesForPatient(
            $dailyTexts,
            (int) $patient->id,
            (int) $actor->id,
            (int) $draft->branch_id,
        ))->map(function (array $day) use ($structuredDays): array {
            $structured = $structuredDays[$day['date']];

            return $structured + [
                'text' => $day['text'],
                'terrain_time' => $day['terrain_time'],
                'administrative_time' => $day['administrative_time'],
            ];
        })->values()->all();

        $branch = $patient->branch;
        $company = $branch?->company;
        $insurance = $patient->insuranceCompany;

        return [
            'schema_version' => 2,
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
            'insurance_code' => $insurance?->branch_code ?? '',
            'patient_id' => $patient->id,
            'patient_name' => trim(($patient->title ? $patient->title.' ' : '').($patient->first_name ?? '').' '.($patient->last_name ?? '')),
            'patient_personal_number' => $patient->personal_number,
            'patient_birth_number' => $patient->personal_number,
            'patient_address' => collect([$patient->address, $patient->city, $patient->zip])->filter()->implode(', '),
            'dekurz_number' => max(1, (int) $patient->dekurz_number),
            'month' => $month,
            'period' => $month,
            'days' => $renderDays,
            'scoring_rows' => $points->map(fn ($point): array => [
                'id' => $point->id,
                'date' => Carbon::parse($point->date)->toDateString(),
                'reference_date' => $point->getRawOriginal('reference_date') ?: null,
                'patient_id' => $point->patient_id,
                'branch_id' => $point->branch_id,
                'procedure_id' => $point->procedure_id,
                'procedure_code' => $point->procedure_code,
                'quantity' => (int) $point->quantity,
            ])->values()->all(),
            'supporting_documents' => $this->supportingDocuments->forBuilder($patient, $month, $points),
        ];
    }
}
