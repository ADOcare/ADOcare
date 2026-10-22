<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientDekurzCustomTrackPeriod;
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
    ) {}

    /** @return array<string, mixed> */
    public function build(Patient $patient, string $month): array
    {
        $month = Carbon::createFromFormat('!Y-m', $month)->format('Y-m');
        $period = $month.'-01';
        $monthlyPoints = $this->scoring->forMonth($patient, $month);
        $points = $monthlyPoints
            ->filter(fn (PatientPoint $point): bool => $this->classifier->isDocumentable($point->procedure_code))
            ->values();

        $draft = DekurzDraft::query()
            ->with([
                'days' => fn ($query) => $query->orderBy('date'),
                'days.entries' => fn ($query) => $query->orderBy('track_position')->orderBy('id'),
                'days.entries.procedure',
                'days.entries.customTrack',
                'finalDocument',
            ])
            ->where('patient_id', $patient->id)
            ->whereDate('period', $period)
            ->first();

        $existingDocuments = $draft?->finalDocument
            ? collect([$draft->finalDocument])
            : collect();

        $suggestedDekurzNumber = $draft?->final_document_id
            ? $draft->dekurz_number
            : max(1, (int) $patient->dekurz_number);

        $templates = PatientProcedureDekurzTemplate::query()
            ->where('patient_id', $patient->id)
            ->whereNull('legacy_imported_at')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->get()
            ->keyBy('procedure_id');

        $customTracks = PatientDekurzCustomTrackPeriod::query()
            ->with('track')
            ->whereDate('period', $period)
            ->whereHas('track', fn ($query) => $query->where('patient_id', $patient->id))
            ->get();

        return [
            'patient' => [
                'id' => $patient->id,
                'name' => trim(($patient->first_name ?? '').' '.($patient->last_name ?? '')),
                'branch_id' => $patient->branch_id,
                'dekurz_number' => $suggestedDekurzNumber,
            ],
            'month' => $month,
            'procedures' => $this->procedures($points, $templates),
            'custom_tracks' => $customTracks->map(fn ($config): array => [
                'id' => $config->track->id,
                'name' => $config->track->name,
                'current_text' => $config->track->current_text,
                'distribution' => $config->distribution,
                'selected_dates' => $config->selected_dates ?? [],
            ])->values()->all(),
            'days' => $draft
                ? $this->draftDays($draft)
                : $this->uninitializedDays($points, $templates),
            'supporting_documents' => $this->supportingDocuments->forBuilder($patient, $month, $monthlyPoints),
            'existing_documents' => $existingDocuments->map(fn (Document $document): array => [
                'id' => $document->id,
                'name' => $document->name,
                'created_at' => $document->created_at?->toISOString(),
                'preview_url' => "/v1/dekurz/{$document->id}/preview",
                'download_url' => "/v1/dekurz/{$document->id}/download",
            ])->values()->all(),
            'draft' => $draft ? $this->draft($draft) : null,
            'scoring_changes' => $this->reconciliation($draft, $points),
        ];
    }

    /** @param Collection<int, PatientPoint> $points */
    private function procedures(Collection $points, $templates): array
    {
        return $points
            ->groupBy('procedure_id')
            ->map(function ($procedurePoints, $procedureId) use ($templates): array {
                $first = $procedurePoints->first();
                $procedure = $first->procedure;
                $resolved = $this->textResolver->resolve($templates->get($procedureId), $procedure);

                return [
                    'procedure_id' => (int) $procedureId,
                    'code' => (string) $first->procedure_code,
                    'description' => $procedure?->description,
                    'total_quantity' => $procedurePoints->sum(fn (PatientPoint $point): int => (int) $point->quantity),
                    'scoring_rows_count' => $procedurePoints->count(),
                    'dates' => $procedurePoints->map(fn (PatientPoint $point): string => $this->dateString($point->date))->unique()->sort()->values()->all(),
                    'template' => $resolved + [
                        'patient_template_text' => $templates->get($procedureId)?->text,
                        'procedure_default_text' => $procedure?->default_dekurz_text,
                    ],
                ];
            })
            ->sortBy('code')
            ->values()
            ->all();
    }

    /** @param Collection<int, PatientPoint> $points */
    private function uninitializedDays(Collection $points, $templates): array
    {
        $previousByProcedure = [];
        $days = [];

        foreach (
            $points
                ->groupBy(fn (PatientPoint $point): string => $this->dateString($point->date))
                ->sortKeys() as $date => $dayPoints
        ) {
            $entries = [];

            foreach ($dayPoints->groupBy('procedure_id')->sortKeys() as $procedureId => $procedurePoints) {
                $procedurePoints = $procedurePoints->values();
                $first = $procedurePoints->first();
                $template = $templates->get($procedureId);
                $previous = $previousByProcedure[(int) $procedureId] ?? null;

                if ($previous && ! in_array($previous['source'], ['procedure_default', 'empty'], true)) {
                    $resolved = [
                        'text' => $previous['text'],
                        'source' => 'previous_entry',
                    ];
                } elseif ($template && trim((string) $template->text) !== '') {
                    $resolved = [
                        'text' => (string) $template->text,
                        'source' => 'patient_template',
                    ];
                } elseif (! $previous) {
                    $resolved = $this->textResolver->resolve(null, $first->procedure);
                } else {
                    $resolved = [
                        'text' => '',
                        'source' => 'empty',
                    ];
                }

                $ids = $procedurePoints
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                $entries[] = [
                    'id' => null,
                    'track_type' => 'procedure',
                    'track_key' => 'procedure:'.$procedureId,
                    'track_name' => $first->procedure?->description ?? (string) $first->procedure_code,
                    'procedure_id' => (int) $procedureId,
                    'procedure_code' => (string) $first->procedure_code,
                    'procedure_name' => $first->procedure?->description,
                    'custom_track_id' => null,
                    'patient_point_ids' => $ids,
                    'patient_point_snapshot_ids' => $ids,
                    'quantity' => $procedurePoints->sum(fn (PatientPoint $point): int => (int) $point->quantity),
                    'is_excluded' => false,
                    'text' => $resolved['text'],
                    'source' => $resolved['source'],
                    'source_entry_id' => null,
                    'edited_at' => null,
                ];

                $previousByProcedure[(int) $procedureId] = $resolved;
            }

            $days[] = [
                'id' => null,
                'date' => $date,
                'general_note' => null,
                'entries' => $entries,
            ];
        }

        return $days;
    }

    private function draftDays(DekurzDraft $draft): array
    {
        return $draft->days->map(fn ($day): array => [
            'id' => $day->id,
            'date' => $this->dateString($day->date),
            'general_note' => $day->general_note,
            'entries' => $day->entries->map(fn (DekurzEntry $entry): array => $this->entry($entry))->values()->all(),
        ])->all();
    }

    private function entry(DekurzEntry $entry): array
    {
        $custom = $entry->isCustomTrack();

        return [
            'id' => $entry->id,
            'track_type' => $custom ? 'custom' : 'procedure',
            'track_key' => $entry->trackKey(),
            'track_name' => $custom
                ? ($entry->customTrack?->name ?? 'Vlastný záznam')
                : ($entry->procedure?->description ?? $entry->procedure?->code ?? 'Výkon'),
            'procedure_id' => $entry->procedure_id,
            'procedure_code' => $entry->procedure?->code,
            'procedure_name' => $entry->procedure?->description,
            'custom_track_id' => $entry->custom_track_id,
            'patient_point_ids' => array_values(array_map('intval', $entry->patient_point_ids ?? [])),
            'patient_point_snapshot_ids' => array_values(array_map('intval', $entry->patient_point_snapshot_ids ?? [])),
            'quantity' => $entry->quantity,
            'is_excluded' => (bool) $entry->is_excluded,
            'text' => (string) $entry->text,
            'source' => $entry->source,
            'source_entry_id' => $entry->source_entry_id,
            'edited_at' => $entry->edited_at?->toISOString(),
        ];
    }

    private function draft(DekurzDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'status' => $draft->status,
            'period' => Carbon::parse($draft->period)->format('Y-m'),
            'dekurz_number' => $draft->dekurz_number,
            'completed_at' => $draft->completed_at?->toISOString(),
            'final_document_id' => $draft->final_document_id,
        ];
    }

    /** @param Collection<int, PatientPoint> $currentPoints */
    private function reconciliation(?DekurzDraft $draft, Collection $currentPoints): array
    {
        if (! $draft) {
            return ['added' => [], 'removed' => [], 'changed' => []];
        }

        $savedPointIds = $draft->days
            ->flatMap->entries
            ->flatMap(fn (DekurzEntry $entry) => $entry->isCustomTrack() ? [] : ($entry->patient_point_snapshot_ids ?? []))
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $currentById = $currentPoints->keyBy('id');

        $added = $currentPoints
            ->reject(fn (PatientPoint $point): bool => $savedPointIds->contains((int) $point->id))
            ->map(fn (PatientPoint $point): array => $this->scoringRow($point))
            ->values()->all();

        $removed = $savedPointIds
            ->reject(fn (int $id): bool => $currentById->has($id))
            ->map(fn (int $id): array => ['patient_point_id' => $id])
            ->values()->all();

        $changed = [];
        foreach ($draft->days as $day) {
            foreach ($day->entries->whereNull('custom_track_id') as $entry) {
                foreach ($entry->patient_point_snapshot_ids ?? [] as $id) {
                    $point = $currentById->get((int) $id);
                    if (! $point) {
                        continue;
                    }
                    $differences = [];
                    $this->difference($differences, 'date', $this->dateString($day->date), $this->dateString($point->date));
                    $this->difference($differences, 'procedure_id', (int) $entry->procedure_id, (int) $point->procedure_id);
                    if ($differences !== []) {
                        $changed[] = ['patient_point_id' => (int) $id, 'dekurz_entry_id' => $entry->id, 'differences' => $differences];
                    }
                }
            }
        }

        return compact('added', 'removed', 'changed');
    }

    private function scoringRow(PatientPoint $point): array
    {
        return [
            'id' => $point->id,
            'date' => $this->dateString($point->date),
            'reference_date' => $point->getRawOriginal('reference_date') ?: null,
            'quantity' => (int) $point->quantity,
            'procedure_id' => $point->procedure_id,
            'procedure_code' => $point->procedure_code,
        ];
    }

    private function difference(array &$differences, string $field, $draftValue, $currentValue): void
    {
        if ($draftValue !== $currentValue) {
            $differences[$field] = ['draft' => $draftValue, 'current' => $currentValue];
        }
    }

    private function dateString($value): string
    {
        return Carbon::parse($value)->toDateString();
    }
}
