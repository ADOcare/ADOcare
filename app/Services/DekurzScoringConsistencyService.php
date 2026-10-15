<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class DekurzScoringConsistencyService
{
    public function authorizeDraftAccess(User $actor, Patient $patient, DekurzDraft $draft): void
    {
        $patient->loadMissing('branch');

        $isAssignedBranchUser = $patient->branch_id !== null
            && $actor->isInBranch((int) $patient->branch_id)
            && (int) $patient->nurse_id === (int) $actor->id;
        $isCompanyManager = $patient->branch?->company_id !== null
            && ($actor->hasGlobalRole('manager') || $actor->hasGlobalRole('admin'))
            && $actor->isInCompany((int) $patient->branch->company_id);

        if (!$isAssignedBranchUser && !$isCompanyManager) {
            throw new AuthorizationException('K tomuto pacientovi nemáte prístup.');
        }

        if ((int) $draft->patient_id !== (int) $patient->id) {
            throw ValidationException::withMessages([
                'patient_id' => 'Dekurz patrí inému pacientovi.',
            ]);
        }

        if ((int) $draft->branch_id !== (int) $patient->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'Pobočka dekurzu nezodpovedá pobočke pacienta.',
            ]);
        }
    }

    public function validatePoint(Patient $patient, DekurzDraft $draft, PatientPoint $point): void
    {
        $errors = [];

        if ((int) $point->patient_id !== (int) $patient->id) {
            $errors['patient_point_id'][] = "Bodovanie {$point->id} patrí inému pacientovi.";
        }

        if (!$point->date) {
            $errors['date'][] = "Bodovanie {$point->id} nemá dátum.";
        }

        if (!$point->procedure_id || !$point->procedure) {
            $errors['procedure_id'][] = "Bodovanie {$point->id} nemá platný výkon.";
        }

        if ((int) $point->quantity < 1) {
            $errors['quantity'][] = "Bodovanie {$point->id} nemá platné množstvo.";
        }

        if ((int) $point->branch_id !== (int) $draft->branch_id) {
            $errors['branch_id'][] = "Bodovanie {$point->id} patrí inej pobočke.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function validateEntry(DekurzEntry $entry, PatientPoint $point): void
    {
        $entry->loadMissing('day');
        $errors = [];

        if ((int) $entry->patient_point_snapshot_id !== (int) $point->id) {
            $errors['patient_point_id'][] = 'Zdrojové bodovanie záznamu Dekurzu sa nezhoduje.';
        }

        if (Carbon::parse($entry->day->date)->toDateString() !== Carbon::parse($point->date)->toDateString()) {
            $errors['date'][] = "Dátum bodovania {$point->id} sa nezhoduje s dňom Dekurzu.";
        }

        if ((int) $entry->procedure_id !== (int) $point->procedure_id) {
            $errors['procedure_id'][] = "Výkon bodovania {$point->id} sa nezhoduje so záznamom Dekurzu.";
        }

        if ((int) $entry->quantity !== (int) $point->quantity || (int) $entry->quantity < 1) {
            $errors['quantity'][] = "Množstvo bodovania {$point->id} sa nezhoduje so záznamom Dekurzu.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}