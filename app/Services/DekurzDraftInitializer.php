<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\Patient;
use App\Models\PatientProcedureDekurzTemplate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DekurzDraftInitializer
{
    public function __construct(
        private DekurzScoringService $scoring,
        private DekurzTextResolver $textResolver,
        private DekurzScoringConsistencyService $consistency,
    ) {
    }

    public function initialize(Patient $patient, string $month, User $actor): DekurzDraft
    {
        return DB::transaction(function () use ($patient, $month, $actor): DekurzDraft {
            $period = Carbon::createFromFormat('!Y-m', $month)->startOfMonth()->toDateString();
            $draft = DekurzDraft::query()->firstOrCreate(
                ['patient_id' => $patient->id, 'period' => $period],
                [
                    'branch_id' => $patient->branch_id,
                    'status' => DekurzDraft::STATUS_DRAFT,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            $this->consistency->authorizeDraftAccess($actor, $patient, $draft);

            if ($draft->status === DekurzDraft::STATUS_COMPLETED) {
                return $draft->load(['days.entries.procedure', 'days.entries.patientPoint']);
            }

            $points = $this->scoring->documentableForMonth($patient, $month);
            $templates = PatientProcedureDekurzTemplate::query()
                ->where('patient_id', $patient->id)
                ->whereIn('procedure_id', $points->pluck('procedure_id')->unique())
                ->get()
                ->keyBy('procedure_id');
            $days = $draft->days()
                ->get()
                ->keyBy(fn ($day): string => Carbon::parse($day->date)->toDateString());

            foreach ($points as $point) {
                $this->consistency->validatePoint($patient, $draft, $point);
                $date = Carbon::parse($point->date)->toDateString();
                $day = $days->get($date);
                if (!$day) {
                    $day = $draft->days()->create(['date' => $date]);
                    $days->put($date, $day);
                }
                $resolved = $this->textResolver->resolve($templates->get($point->procedure_id), $point->procedure);

                $entry = $day->entries()->firstOrCreate(
                    ['patient_point_snapshot_id' => $point->id],
                    [
                        'procedure_id' => $point->procedure_id,
                        'patient_point_id' => $point->id,
                        'quantity' => $point->quantity,
                        'text' => $resolved['text'],
                        'source' => $resolved['source'],
                    ],
                );

                if ($entry->wasRecentlyCreated) {
                    $this->consistency->validateEntry($entry, $point);
                }
            }

            $draft->update(['updated_by' => $actor->id]);

            return $draft->load(['days.entries.procedure', 'days.entries.patientPoint']);
        });
    }
}