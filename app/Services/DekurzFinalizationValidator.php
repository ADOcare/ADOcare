<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\PatientProcedureDekurzTemplate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DekurzFinalizationValidator
{
    public function __construct(
        private DekurzScoringService $scoring,
        private DekurzScoringConsistencyService $consistency,
    ) {}

    /** @return Collection<int, PatientPoint> */
    public function validate(Patient $patient, DekurzDraft $draft, User $actor): Collection
    {
        $this->consistency->authorizeDraftAccess($actor, $patient, $draft);
        $draft->loadMissing(['finalDocument', 'days.entries.procedure', 'days.entries.customTrack']);
        $errors = [];

        if ($draft->status !== DekurzDraft::STATUS_DRAFT) {
            $errors['draft.status'][] = $draft->final_document_id && $draft->finalDocument
                ? 'Dekurz už bol finalizovaný.'
                : 'Dokončený Dekurz nemá platný historický dokument.';
        }

        if (! $draft->dekurz_number || $draft->dekurz_number < 1) {
            $errors['dekurz_number'][] = 'Číslo Dekurzu je povinné.';
        }

        $month = Carbon::parse($draft->period)->format('Y-m');
        $points = $this->scoring->documentableForMonth($patient, $month);
        $pointsById = $points->keyBy('id');
        $usedPointIds = collect();
        $patientTemplates = PatientProcedureDekurzTemplate::query()
            ->where('patient_id', $patient->id)
            ->get()
            ->keyBy('procedure_id');

        if ($points->isEmpty()) {
            $errors['scoring'][] = 'Pre zvolené obdobie nie je evidované bodovanie určené pre Dekurz.';
        }

        foreach ($draft->days as $day) {
            $date = Carbon::parse($day->date)->toDateString();
            if (! str_starts_with($date, $month.'-')) {
                $errors["days.{$day->id}.date"][] = 'Deň nepatrí do obdobia konceptu.';
            }
            if ($day->entries->isEmpty()) {
                $errors["days.{$day->id}.entries"][] = 'Deň neobsahuje žiadny záznam Dekurzu.';
            }

            foreach ($day->entries as $entry) {
                $key = "entries.{$entry->id}";

                // Exclusion removes only the documentation section. Keep the
                // scoring linkage below so reconciliation still knows that the
                // source performance belongs to this Dekurz.
                if (! $entry->is_excluded) {
                    $text = trim((string) $entry->text);
                    if ($text === '') {
                        $errors[$key][] = 'Text záznamu je povinný.';
                    }
                    if (str_contains($text, '[TEXT]')) {
                        $errors[$key][] = 'Text obsahuje nedoplnenú hodnotu [TEXT].';
                    }

                    if (! $entry->isCustomTrack()) {
                        $patientTemplateText = trim((string) ($patientTemplates->get($entry->procedure_id)?->text ?? ''));
                        $procedureDefaultText = trim((string) ($entry->procedure?->default_dekurz_text ?? ''));
                        $matchesPatientTemplate = $patientTemplateText !== '' && $text === $patientTemplateText;
                        $matchesGenericDefault = $procedureDefaultText !== '' && $text === $procedureDefaultText;

                        if ($matchesGenericDefault && ! $matchesPatientTemplate) {
                            $errors[$key][] = 'Všeobecný predvolený text výkonu musí byť pred dokončením upravený pre pacienta.';
                        }
                    }
                }

                if ($entry->isCustomTrack()) {
                    if (! $entry->is_excluded && (int) $entry->customTrack?->patient_id !== (int) $patient->id) {
                        $errors[$key][] = 'Vlastný záznam patrí inému pacientovi.';
                    }
                    continue;
                }

                $ids = collect($entry->patient_point_snapshot_ids ?? [])->map(fn ($id): int => (int) $id)->filter();
                if ($ids->isEmpty()) {
                    $errors[$key][] = 'Výkon nemá zdrojové bodovanie.';
                    continue;
                }

                $expectedQuantity = 0;
                foreach ($ids as $id) {
                    if ($usedPointIds->contains($id)) {
                        $errors[$key][] = "Bodovanie {$id} je v koncepte použité viackrát.";
                    }
                    $usedPointIds->push($id);
                    $point = $pointsById->get($id);
                    if (! $point) {
                        $errors[$key][] = "Zdrojové bodovanie {$id} už neexistuje alebo nepatrí do Dekurzu.";
                        continue;
                    }
                    if ((int) $point->procedure_id !== (int) $entry->procedure_id) {
                        $errors[$key][] = 'Výkon záznamu sa nezhoduje so zdrojovým bodovaním.';
                    }
                    if (Carbon::parse($point->date)->toDateString() !== $date) {
                        $errors[$key][] = 'Dátum záznamu sa nezhoduje so zdrojovým bodovaním.';
                    }
                    $expectedQuantity += (int) $point->quantity;
                }

                if ((int) $entry->quantity !== $expectedQuantity) {
                    $errors[$key][] = 'Množstvo záznamu sa nezhoduje so zdrojovým bodovaním.';
                }
            }
        }

        foreach ($points as $point) {
            if (! $usedPointIds->contains((int) $point->id)) {
                $errors["scoring.{$point->id}"][] = 'Bodovanie nemá záznam Dekurzu.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $points;
    }
}
