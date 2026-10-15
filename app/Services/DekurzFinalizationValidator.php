<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DekurzFinalizationValidator
{
    public function __construct(
        private DekurzScoringService $scoring,
        private DekurzScoringConsistencyService $consistency,
    ) {
    }

    /** @return Collection<int, PatientPoint> */
    public function validate(Patient $patient, DekurzDraft $draft, User $actor): Collection
    {
        $this->consistency->authorizeDraftAccess($actor, $patient, $draft);
        $draft->loadMissing(['finalDocument', 'days.entries.procedure']);
        $errors = [];

        if ($draft->status !== DekurzDraft::STATUS_DRAFT) {
            $message = $draft->final_document_id && $draft->finalDocument
                ? 'Dekurz už bol finalizovaný.'
                : 'Dokončený Dekurz nemá platný historický dokument.';
            $errors['draft.status'][] = $message;
        } elseif ($draft->final_document_id || $draft->completed_at) {
            $errors['draft.status'][] = 'Rozpracovaný Dekurz obsahuje neplatné údaje o finalizácii.';
        }

        $month = Carbon::parse($draft->period)->format('Y-m');
        $points = $this->scoring->documentableForMonth($patient, $month);
        $pointsById = $points->keyBy('id');
        $entries = $draft->days->flatMap->entries;

        if ($points->isEmpty()) {
            $errors['scoring'][] = 'Pre zvolené obdobie nie je evidované bodovanie určené pre Dekurz.';
        }

        foreach ($draft->days->groupBy(fn ($day): string => Carbon::parse($day->date)->toDateString()) as $date => $days) {
            if ($days->count() > 1) {
                $errors['days'][] = "Koncept obsahuje duplicitný deň {$date}.";
            }
        }

        foreach ($draft->days as $day) {
            $date = Carbon::parse($day->date)->toDateString();
            if (!str_starts_with($date, $month.'-')) {
                $errors["days.{$day->id}.date"][] = 'Deň nepatrí do obdobia konceptu.';
            }
            if ($day->entries->isEmpty()) {
                $errors["days.{$day->id}.entries"][] = 'Deň neobsahuje žiadny záznam Dekurzu.';
            }
        }

        $entriesByPoint = $entries
            ->filter(fn ($entry): bool => $entry->patient_point_snapshot_id !== null)
            ->groupBy('patient_point_snapshot_id');

        foreach ($entriesByPoint as $pointId => $pointEntries) {
            if ($pointEntries->count() > 1) {
                $errors['entries'][] = "Bodovanie {$pointId} je v koncepte použité viackrát.";
            }
        }

        foreach ($points as $point) {
            $pointEntries = $entriesByPoint->get($point->id, collect());
            if ($pointEntries->count() !== 1) {
                $errors["scoring.{$point->id}"][] = 'Bodovanie nemá práve jeden záznam Dekurzu.';
            }
        }

        foreach ($entries as $entry) {
            $key = "entries.{$entry->id}";
            $pointId = $entry->patient_point_snapshot_id;
            $point = $pointId ? $pointsById->get($pointId) : null;

            if (!$pointId || !$point) {
                $errors[$key][] = 'Zdrojové bodovanie už neexistuje alebo už nepatrí do Dekurzu.';
                continue;
            }
            if ((int) $entry->patient_point_id !== (int) $point->id) {
                $errors[$key][] = 'Aktívny odkaz na bodovanie sa nezhoduje so snapshot odkazom.';
            }
            if ((int) $point->patient_id !== (int) $patient->id) {
                $errors[$key][] = 'Zdrojové bodovanie patrí inému pacientovi.';
            }
            if ((int) $point->branch_id !== (int) $draft->branch_id) {
                $errors[$key][] = 'Zdrojové bodovanie patrí inej pobočke.';
            }
            if (Carbon::parse($entry->day->date)->toDateString() !== Carbon::parse($point->date)->toDateString()) {
                $errors[$key][] = 'Dátum záznamu sa nezhoduje so zdrojovým bodovaním.';
            }
            if ((int) $entry->procedure_id !== (int) $point->procedure_id) {
                $errors[$key][] = 'Výkon záznamu sa nezhoduje so zdrojovým bodovaním.';
            }
            if ((int) $entry->quantity !== (int) $point->quantity || (int) $entry->quantity < 1) {
                $errors[$key][] = 'Množstvo záznamu sa nezhoduje so zdrojovým bodovaním.';
            }
            if (trim((string) $entry->text) === '') {
                $errors[$key][] = 'Text záznamu je povinný.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $points;
    }
}