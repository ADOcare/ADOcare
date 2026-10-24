<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use App\Models\Patient;
use App\Models\PatientDekurzCustomTrack;
use App\Models\PatientDekurzCustomTrackPeriod;
use App\Models\PatientPoint;
use App\Models\PatientProcedureDekurzTemplate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DekurzDraftInitializer
{
    public function __construct(
        private DekurzScoringService $scoring,
        private DekurzTextResolver $textResolver,
        private DekurzScoringConsistencyService $consistency,
        private DekurzTimelineReadinessService $timelineReadiness,
    ) {}

    public function initialize(Patient $patient, string $month, User $actor): DekurzDraft
    {
        return DB::transaction(function () use ($patient, $month, $actor): DekurzDraft {
            $period = Carbon::createFromFormat('!Y-m', $month)->startOfMonth()->toDateString();

            $lockedPatient = Patient::query()
                ->lockForUpdate()
                ->findOrFail($patient->id);

            $draft = DekurzDraft::query()
                ->where('patient_id', $lockedPatient->id)
                ->whereDate('period', $period)
                ->lockForUpdate()
                ->first();

            $points = $this->scoring->documentableForMonth($patient, $month)
                ->sortBy(fn (PatientPoint $point): string => Carbon::parse($point->date)->format('Y-m-d').sprintf('-%010d', $point->id))
                ->values();

            if (! $draft) {
                $this->timelineReadiness->assertReady($lockedPatient, $actor, $points, $month);

                $draft = DekurzDraft::query()->create([
                    'patient_id' => $lockedPatient->id,
                    'branch_id' => $lockedPatient->branch_id,
                    'period' => $period,
                    'dekurz_number' => max(1, (int) $lockedPatient->dekurz_number),
                    'status' => DekurzDraft::STATUS_DRAFT,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            } else {
                if (! $draft->final_document_id || ! $draft->dekurz_number) {
                    $draft->dekurz_number = max(1, (int) $lockedPatient->dekurz_number);
                }

                if ($draft->status === DekurzDraft::STATUS_COMPLETED) {
                    // Reopen the SAME logical Dekurz. The assigned number and old final
                    // document remain untouched until a successful regeneration.
                    $draft->status = DekurzDraft::STATUS_DRAFT;
                }

                $draft->updated_by = $actor->id;
                $draft->save();
            }

            $this->consistency->authorizeDraftAccess($actor, $lockedPatient, $draft);

            $templates = PatientProcedureDekurzTemplate::query()
                ->where('patient_id', $patient->id)
                ->whereNull('legacy_imported_at')
                ->whereIn('procedure_id', $points->pluck('procedure_id')->unique())
                ->get()
                ->keyBy('procedure_id');

            $days = $draft->days()->with(['entries.procedure', 'entries.customTrack'])->get()
                ->keyBy(fn ($day): string => Carbon::parse($day->date)->toDateString());

            /** @var Collection<int, DekurzEntry> $previousByProcedure */
            $previousByProcedure = collect();

            $groupedProcedureDays = $points
                ->groupBy(fn (PatientPoint $point): string => Carbon::parse($point->date)->toDateString())
                ->sortKeys();

            foreach ($groupedProcedureDays as $date => $dayPoints) {
                $day = $days->get($date);
                if (! $day) {
                    $day = $draft->days()->create(['date' => $date]);
                    $days->put($date, $day);
                }

                $position = 0;
                foreach ($dayPoints->groupBy('procedure_id')->sortKeys() as $procedureId => $procedurePoints) {
                    $procedurePoints = $procedurePoints->values();
                    foreach ($procedurePoints as $point) {
                        $this->consistency->validatePoint($patient, $draft, $point);
                    }

                    $first = $procedurePoints->first();
                    $snapshotIds = $procedurePoints->pluck('id')->map(fn ($id): int => (int) $id)->all();
                    $quantity = $procedurePoints->sum(fn (PatientPoint $point): int => (int) $point->quantity);
                    $matches = $day->entries
                        ->filter(fn (DekurzEntry $entry): bool => ! $entry->isCustomTrack() && (int) $entry->procedure_id === (int) $procedureId)
                        ->values();
                    $existing = $matches->first();

                    // Older drafts stored one entry per scoring row. Collapse those rows into
                    // one track entry before applying the sequential model.
                    if ($matches->count() > 1) {
                        $matches->slice(1)->each(function (DekurzEntry $duplicate): void {
                            $duplicate->delete();
                        });
                    }

                    $previous = $previousByProcedure->get((int) $procedureId);

                    $patientTemplate = $templates->get($procedureId);
                    $previousCanFlow = $previous
                        && trim((string) $previous->text) !== ''
                        && ! in_array($previous->source, ['procedure_default', 'empty'], true);

                    if (! $existing) {
                        if ($previousCanFlow) {
                            $resolved = [
                                'text' => (string) $previous->text,
                                'source' => 'previous_entry',
                            ];
                        } elseif ($patientTemplate && trim((string) $patientTemplate->text) !== '') {
                            // A patient-specific text may be reused unchanged. It is already
                            // clinical context for this patient, unlike the generic DB default.
                            $resolved = [
                                'text' => (string) $patientTemplate->text,
                                'source' => 'patient_template',
                            ];
                        } elseif (! $previous) {
                            // The generic procedure default is only allowed to seed the first
                            // occurrence of this procedure in the month.
                            $resolved = $this->textResolver->resolve(null, $first->procedure);
                        } else {
                            $resolved = [
                                'text' => '',
                                'source' => 'empty',
                            ];
                        }

                        $existing = $day->entries()->create([
                            'procedure_id' => (int) $procedureId,
                            'patient_point_id' => $snapshotIds[0] ?? null,
                            'patient_point_ids' => $snapshotIds,
                            'patient_point_snapshot_id' => $snapshotIds[0] ?? null,
                            'patient_point_snapshot_ids' => $snapshotIds,
                            'quantity' => $quantity,
                            'track_position' => $position,
                            'text' => $resolved['text'],
                            'source' => $resolved['source'],
                            'source_entry_id' => $resolved['source'] === 'previous_entry' ? $previous?->id : null,
                        ]);
                    } else {
                        // Keep historical snapshots stable. The only automatic repair is for
                        // old drafts that copied an untouched generic DB default forward.
                        $existing->fill([
                            'patient_point_id' => $snapshotIds[0] ?? null,
                            'patient_point_ids' => $snapshotIds,
                            'patient_point_snapshot_id' => $snapshotIds[0] ?? null,
                            'patient_point_snapshot_ids' => $snapshotIds,
                            'quantity' => $quantity,
                            'track_position' => $position,
                        ]);

                        if (
                            ! $existing->edited_at
                            && $existing->source === 'previous_entry'
                            && $previous
                            && in_array($previous->source, ['procedure_default', 'empty'], true)
                        ) {
                            if ($patientTemplate && trim((string) $patientTemplate->text) !== '') {
                                $existing->text = (string) $patientTemplate->text;
                                $existing->source = 'patient_template';
                            } else {
                                $existing->text = '';
                                $existing->source = 'empty';
                            }
                            $existing->source_entry_id = null;
                        }

                        if ($existing->isDirty()) {
                            $existing->save();
                        }
                    }

                    $previousByProcedure->put((int) $procedureId, $existing);
                    $position++;
                }
            }

            $this->activateLegacyTextTracks($patient, $period);

            $periodConfigs = PatientDekurzCustomTrackPeriod::query()
                ->with('track')
                ->whereDate('period', $period)
                ->whereHas('track', fn ($query) => $query->where('patient_id', $patient->id))
                ->get();

            foreach ($periodConfigs as $config) {
                $track = $config->track;
                $dates = $config->distribution === 'selected_days'
                    ? collect($config->selected_dates ?? [])
                    : $groupedProcedureDays->keys();

                $dates = $dates
                    ->filter(fn ($date): bool => is_string($date) && str_starts_with($date, $month.'-'))
                    ->unique()
                    ->sort()
                    ->values();

                $previous = null;
                foreach ($dates as $date) {
                    $day = $days->get($date);
                    if (! $day) {
                        continue;
                    }

                    $existing = $day->entries
                        ->first(fn (DekurzEntry $entry): bool => (int) $entry->custom_track_id === (int) $track->id);

                    if (! $existing) {
                        $existing = $day->entries()->create([
                            'procedure_id' => null,
                            'custom_track_id' => $track->id,
                            'patient_point_id' => null,
                            'patient_point_ids' => [],
                            'patient_point_snapshot_id' => null,
                            'patient_point_snapshot_ids' => [],
                            'quantity' => null,
                            'track_position' => 1000 + (int) $track->id,
                            'text' => $previous?->text ?? (string) ($track->current_text ?? ''),
                            'source' => $previous ? 'previous_entry' : (filled($track->current_text) ? 'custom_template' : 'empty'),
                            'source_entry_id' => $previous?->id,
                        ]);
                    }

                    $previous = $existing;
                }
            }

            $draft->update(['updated_by' => $actor->id]);

            return $draft->load(['days.entries.procedure', 'days.entries.customTrack']);
        });
    }

    private function activateLegacyTextTracks(Patient $patient, string $period): void
    {
        $templates = PatientProcedureDekurzTemplate::query()
            ->with('procedure')
            ->where('patient_id', $patient->id)
            ->whereNotNull('legacy_imported_at')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->get();

        foreach ($templates as $template) {
            $label = trim((string) ($template->procedure?->description ?: $template->procedure?->code));
            $name = mb_substr('Pôvodný text – '.($label ?: 'Vlastný záznam'), 0, 120);
            $track = PatientDekurzCustomTrack::query()->firstOrCreate(
                ['legacy_template_id' => $template->id],
                [
                    'patient_id' => $patient->id,
                    'name' => $name,
                    'current_text' => $template->text,
                ],
            );

            PatientDekurzCustomTrackPeriod::query()->firstOrCreate(
                [
                    'custom_track_id' => $track->id,
                    'period' => $period,
                ],
                [
                    'distribution' => 'visit_days',
                    'selected_dates' => [],
                ],
            );
        }
    }
}
