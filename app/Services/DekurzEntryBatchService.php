<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use App\Models\PatientDekurzCustomTrack;
use App\Models\PatientDekurzCustomTrackPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DekurzEntryBatchService
{
    public function __construct(
        private PatientProcedureDekurzTemplateService $templates,
    ) {}

    /** @return array{updated_count:int, updated_entry_ids:list<int>} */
    public function propagate(
        DekurzDraft $draft,
        DekurzEntry $source,
        string $text,
        string $mode,
        array $targetEntryIds,
        array $targetDates,
        bool $saveAsPatientTemplate,
        User $actor,
    ): array {
        $source->loadMissing(['day', 'procedure', 'customTrack']);

        if ((int) $source->day->dekurz_draft_id !== (int) $draft->id) {
            throw ValidationException::withMessages([
                'entry' => 'Záznam nepatrí do zvoleného konceptu Dekurzu.',
            ]);
        }

        if ($draft->status !== DekurzDraft::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'draft' => 'Dokončený Dekurz nie je možné upravovať.',
            ]);
        }

        return DB::transaction(function () use (
            $draft,
            $source,
            $text,
            $mode,
            $targetEntryIds,
            $targetDates,
            $saveAsPatientTemplate,
            $actor,
        ): array {
            $text = trim($text);
            $sourceDate = $source->day->date->toDateString();

            $draftDays = $draft->days()
                ->orderBy('date')
                ->get();

            $availableDates = $draftDays
                ->map(fn ($day): string => $day->date->toDateString())
                ->filter(fn (string $date): bool => $date >= $sourceDate)
                ->values();

            if ($mode === 'current') {
                $resolvedDates = collect([$sourceDate]);
            } elseif ($mode === 'following') {
                // For custom tracks the frontend sends the concrete day list.
                // Using it explicitly makes "this and following days" deterministic
                // even when the custom entry does not exist on those days yet.
                $requestedDates = $source->isCustomTrack() && $targetDates !== []
                    ? collect($targetDates)
                        ->map(fn ($date): string => (string) $date)
                        ->push($sourceDate)
                        ->unique()
                        ->sort()
                        ->values()
                    : $availableDates;

                if ($requestedDates->diff($availableDates)->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'target_dates' => 'Niektoré nasledujúce dni nepatria do tohto Dekurzu alebo sú pred zdrojovým dňom.',
                    ]);
                }

                $resolvedDates = $requestedDates;
            } else {
                $requestedDates = collect($targetDates)
                    ->map(fn ($date): string => (string) $date)
                    ->push($sourceDate)
                    ->unique()
                    ->sort()
                    ->values();

                if ($requestedDates->diff($availableDates)->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'target_dates' => 'Niektoré vybrané dni nepatria do tohto Dekurzu alebo sú pred zdrojovým dňom.',
                    ]);
                }

                $resolvedDates = $requestedDates;
            }

            if ($source->isCustomTrack()) {
                $targets = collect();

                foreach ($draftDays as $day) {
                    $date = $day->date->toDateString();

                    if (! $resolvedDates->contains($date)) {
                        continue;
                    }

                    $entry = $day->entries()
                        ->where('custom_track_id', $source->custom_track_id)
                        ->first();

                    if (! $entry) {
                        $entry = $day->entries()->create([
                            'procedure_id' => null,
                            'custom_track_id' => $source->custom_track_id,
                            'patient_point_id' => null,
                            'patient_point_ids' => [],
                            'patient_point_snapshot_id' => null,
                            'patient_point_snapshot_ids' => [],
                            'quantity' => null,
                            'track_position' => 1000 + (int) $source->custom_track_id,
                            'is_excluded' => false,
                            'text' => $text,
                            'source' => 'manual',
                            'source_entry_id' => $source->id,
                            'edited_at' => now(),
                        ]);
                    }

                    $targets->push($entry);
                }

                $period = PatientDekurzCustomTrackPeriod::query()
                    ->firstOrNew([
                        'custom_track_id' => $source->custom_track_id,
                        'period' => $draft->period->copy()->startOfMonth()->toDateString(),
                    ]);

                $period->fill([
                    'distribution' => 'selected_days',
                    'selected_dates' => collect($period->selected_dates ?? [])
                        ->merge($resolvedDates)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                ]);
                $period->save();
            } else {
                $query = DekurzEntry::query()
                    ->with('day')
                    ->whereNull('custom_track_id')
                    ->where('procedure_id', $source->procedure_id)
                    ->where('is_excluded', false)
                    ->whereHas('day', fn ($dayQuery) => $dayQuery
                        ->where('dekurz_draft_id', $draft->id)
                        ->where(function ($dateQuery) use ($resolvedDates): void {
                            foreach ($resolvedDates as $date) {
                                $dateQuery->orWhereDate('date', $date);
                            }
                        }));

                if ($mode === 'selected' && $targetEntryIds !== []) {
                    $requestedIds = collect($targetEntryIds)
                        ->map(fn ($id): int => (int) $id)
                        ->push((int) $source->id)
                        ->unique()
                        ->values();

                    $query->whereKey($requestedIds);
                }

                $targets = $query->get();
            }

            $now = now();
            $genericDefault = (
                ! $source->isCustomTrack()
                && $source->source === 'procedure_default'
                && trim((string) $source->text) === $text
            );

            foreach ($targets as $entry) {
                $isSource = (int) $entry->id === (int) $source->id;

                if ($genericDefault) {
                    $entrySource = 'procedure_default';
                    $sourceEntryId = null;
                    $editedAt = null;
                } elseif ($isSource) {
                    $entrySource = trim((string) $source->text) === $text
                        ? $source->source
                        : 'manual';
                    $sourceEntryId = $entrySource === 'previous_entry'
                        ? $source->source_entry_id
                        : null;
                    $editedAt = $entrySource === 'manual'
                        ? $now
                        : $source->edited_at;
                } else {
                    $entrySource = 'previous_entry';
                    $sourceEntryId = $source->id;
                    $editedAt = null;
                }

                $entry->update([
                    'text' => $text,
                    'source' => $entrySource,
                    'source_entry_id' => $sourceEntryId,
                    'edited_at' => $editedAt,
                    'is_excluded' => false,
                ]);
            }

            if ($saveAsPatientTemplate) {
                if ($source->isCustomTrack()) {
                    PatientDekurzCustomTrack::query()
                        ->whereKey($source->custom_track_id)
                        ->where('patient_id', $draft->patient_id)
                        ->update(['current_text' => $text]);
                } elseif ($source->procedure) {
                    $patient = $draft->patient()->firstOrFail();
                    $this->templates->upsert($patient, $source->procedure, $text, $actor);
                }
            }

            $draft->update([
                'updated_by' => $actor->id,
            ]);

            return [
                'updated_count' => $targets->count(),
                'updated_entry_ids' => $targets
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ];
        });
    }
}
