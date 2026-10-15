<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\PatientProcedureDekurzTemplate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class DekurzBuilderService
{
    public function __construct(
        private DekurzScoringService $scoring,
        private DekurzProcedureClassifier $classifier,
        private DekurzTextResolver $textResolver,
        private DekurzSupportingDocumentService $supportingDocuments,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(Patient $patient, string $month): array
    {
        $month = Carbon::createFromFormat('!Y-m', $month)->format('Y-m');
        $monthlyPoints = $this->scoring->forMonth($patient, $month);
        $points = $monthlyPoints
            ->filter(fn (PatientPoint $point): bool => $this->classifier->isDocumentable($point->procedure_code))
            ->values();
        $draft = DekurzDraft::query()
            ->with([
                'days' => fn ($query) => $query->orderBy('date'),
                'days.entries' => fn ($query) => $query->orderBy('id'),
                'days.entries.procedure',
                'days.entries.patientPoint',
            ])
            ->where('patient_id', $patient->id)
            ->where('period', $month.'-01')
            ->first();
        $templates = PatientProcedureDekurzTemplate::query()
            ->where('patient_id', $patient->id)
            ->whereIn('procedure_id', $points->pluck('procedure_id')->unique())
            ->get()
            ->keyBy('procedure_id');
        $savedEntries = $draft
            ? $draft->days->flatMap->entries
                ->filter(fn (DekurzEntry $entry): bool => $entry->patient_point_snapshot_id !== null)
                ->keyBy('patient_point_snapshot_id')
            : collect();

        return [
            'patient' => [
                'id' => $patient->id,
                'name' => trim(($patient->first_name ?? '').' '.($patient->last_name ?? '')),
                'branch_id' => $patient->branch_id,
            ],
            'month' => $month,
            'procedures' => $this->procedures($points, $templates),
            'days' => $this->days($points, $templates, $savedEntries),
            'supporting_documents' => $this->supportingDocuments->forBuilder($patient, $month, $monthlyPoints),
            'draft' => $draft ? $this->draft($draft) : null,
            'scoring_changes' => $this->reconciliation($draft, $points),
        ];
    }

    /**
     * @param Collection<int, PatientPoint> $points
     * @param \Illuminate\Support\Collection<int, PatientProcedureDekurzTemplate> $templates
     * @return array<int, array<string, mixed>>
     */
    private function procedures(Collection $points, $templates): array
    {
        return $points
            ->groupBy('procedure_id')
            ->map(function ($procedurePoints, $procedureId) use ($templates): array {
                $first = $procedurePoints->first();
                $procedure = $first->procedure;

                return [
                    'procedure_id' => (int) $procedureId,
                    'code' => (string) $first->procedure_code,
                    'description' => $procedure?->description,
                    'total_quantity' => $procedurePoints->sum(fn (PatientPoint $point): int => (int) $point->quantity),
                    'scoring_rows_count' => $procedurePoints->count(),
                    'dates' => $procedurePoints
                        ->map(fn (PatientPoint $point): string => $this->dateString($point->date))
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                    'scoring_rows' => $procedurePoints
                        ->map(fn (PatientPoint $point): array => $this->scoringRow($point))
                        ->values()
                        ->all(),
                    'template' => $this->textResolver->resolve($templates->get($procedureId), $procedure),
                ];
            })
            ->sortBy('code')
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, PatientPoint> $points
     * @param \Illuminate\Support\Collection<int, PatientProcedureDekurzTemplate> $templates
     * @param \Illuminate\Support\Collection<int, DekurzEntry> $savedEntries
     * @return array<int, array<string, mixed>>
     */
    private function days(Collection $points, $templates, $savedEntries): array
    {
        return $points
            ->groupBy(fn (PatientPoint $point): string => $this->dateString($point->date))
            ->map(function ($dayPoints, string $date) use ($templates, $savedEntries): array {
                $entries = $dayPoints->map(function (PatientPoint $point) use ($templates, $savedEntries): array {
                    $saved = $savedEntries->get($point->id);
                    $resolved = $saved
                        ? ['text' => $saved->text, 'source' => 'saved_draft']
                        : $this->textResolver->resolve($templates->get($point->procedure_id), $point->procedure);

                    return [
                        'patient_point_id' => $point->id,
                        'dekurz_entry_id' => $saved?->id,
                        'procedure_id' => $point->procedure_id,
                        'procedure_code' => $point->procedure_code,
                        'quantity' => (int) $point->quantity,
                        'text' => $resolved['text'],
                        'source' => $resolved['source'],
                        'saved_source' => $saved?->source,
                        'edited_at' => $saved?->edited_at?->toISOString(),
                    ];
                })->sortBy([
                    ['procedure_code', 'asc'],
                    ['patient_point_id', 'asc'],
                ])->values()->all();

                return [
                    'date' => $date,
                    'entries' => $entries,
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function draft(DekurzDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'status' => $draft->status,
            'period' => Carbon::parse($draft->period)->format('Y-m'),
            'completed_at' => $draft->completed_at?->toISOString(),
            'final_document_id' => $draft->final_document_id,
            'days' => $draft->days->map(fn ($day): array => [
                'id' => $day->id,
                'date' => $this->dateString($day->date),
                'general_note' => $day->general_note,
                'entries' => $day->entries->map(fn (DekurzEntry $entry): array => [
                    'id' => $entry->id,
                    'patient_point_id' => $entry->patient_point_id,
                    'patient_point_snapshot_id' => $entry->patient_point_snapshot_id,
                    'procedure_id' => $entry->procedure_id,
                    'procedure_code' => $entry->procedure?->code,
                    'quantity' => $entry->quantity,
                    'text' => $entry->text,
                    'source' => $entry->source,
                    'edited_at' => $entry->edited_at?->toISOString(),
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * @param Collection<int, PatientPoint> $currentPoints
     * @return array{added: array<int, mixed>, removed: array<int, mixed>, changed: array<int, mixed>}
     */
    private function reconciliation(?DekurzDraft $draft, Collection $currentPoints): array
    {
        if (!$draft) {
            return ['added' => [], 'removed' => [], 'changed' => []];
        }

        $savedEntries = $draft->days
            ->flatMap->entries
            ->filter(fn (DekurzEntry $entry): bool => $entry->patient_point_snapshot_id !== null)
            ->keyBy('patient_point_snapshot_id');
        $added = $currentPoints
            ->reject(fn (PatientPoint $point): bool => $savedEntries->has($point->id))
            ->map(fn (PatientPoint $point): array => $this->scoringRow($point))
            ->values()
            ->all();

        $sourceRows = PatientPoint::query()
            ->whereIn('id', $savedEntries->keys())
            ->get()
            ->keyBy('id');
        $removed = [];
        $changed = [];

        foreach ($savedEntries as $sourceId => $entry) {
            $current = $sourceRows->get($sourceId);
            if (!$current) {
                $removed[] = [
                    'patient_point_id' => (int) $sourceId,
                    'dekurz_entry_id' => $entry->id,
                    'procedure_id' => $entry->procedure_id,
                    'date' => $this->dateString($entry->day->date),
                    'quantity' => $entry->quantity,
                ];
                continue;
            }

            $differences = [];
            $this->difference($differences, 'patient_id', (int) $draft->patient_id, (int) $current->patient_id);
            $this->difference($differences, 'date', $this->dateString($entry->day->date), $this->nullableDateString($current->date));
            $this->difference($differences, 'procedure_id', (int) $entry->procedure_id, (int) $current->procedure_id);
            $this->difference($differences, 'quantity', (int) $entry->quantity, (int) $current->quantity);

            if ($differences !== []) {
                $changed[] = [
                    'patient_point_id' => (int) $sourceId,
                    'dekurz_entry_id' => $entry->id,
                    'differences' => $differences,
                ];
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    /** @return array<string, mixed> */
    private function scoringRow(PatientPoint $point): array
    {
        return [
            'id' => $point->id,
            'date' => $this->dateString($point->date),
            'reference_date' => $this->nullableDateString($point->reference_date),
            'quantity' => (int) $point->quantity,
            'procedure_id' => $point->procedure_id,
            'procedure_code' => $point->procedure_code,
        ];
    }

    /** @param array<string, mixed> $differences */
    private function difference(array &$differences, string $field, $draftValue, $currentValue): void
    {
        if ($draftValue !== $currentValue) {
            $differences[$field] = [
                'draft' => $draftValue,
                'current' => $currentValue,
            ];
        }
    }

    private function dateString($value): string
    {
        return Carbon::parse($value)->toDateString();
    }

    private function nullableDateString($value): ?string
    {
        return $value ? $this->dateString($value) : null;
    }
}