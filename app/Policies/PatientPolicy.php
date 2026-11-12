<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;

class PatientPolicy extends BasePolicy
{
    public function view(User $user, Patient $patient): bool
    {
        // Managers and admins can view any patient in their company
        if ($this->isManager($user) && $user->isInCompany($patient->branch->company_id)) {
            return true;
        }

        // A nurse can view a patient if assigned to the same branch and assigned to the patient
        if ($user->isInBranch($patient->branch_id)) {
            // check nurse assignment (patient->nurse_id or visit pivot) — fallback to allowing branch nurses to view
            return $patient->nurse_id !== null && (int) $patient->nurse_id === (int) $user->id;
        }

        return false;
    }

    public function create(User $user, $branchId): bool
    {
        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            return false;
        }

        if (! $user->isInCompany((int) $branch->company_id)) {
            return false;
        }

        return $user->isInBranch((int) $branch->id) || $this->isManager($user);
    }

    public function update(User $user, Patient $patient): bool
    {
        // Managers/admins may update patients in same company
        if ($this->isManager($user) && $user->isInCompany($patient->branch->company_id)) {
            return true;
        }

        // Nurses may update only patients assigned to them
        if ($user->isInBranch($patient->branch_id)) {
            return $patient->nurse_id !== null && (int) $patient->nurse_id === (int) $user->id;
        }

        return false;
    }

    public function manageAssignments(User $user, Patient $patient): bool
    {
        return $this->isManager($user)
            && $user->isInCompany((int) $patient->branch->company_id);
    }

    public function correctHistory(User $user, Patient $patient): bool
    {
        return $this->isManager($user)
            && $user->isInCompany((int) $patient->branch->company_id);
    }

    public function delete(User $user, Patient $patient): bool
    {
        // Only managers/admins in the same company can delete
        return $this->isManager($user) && $user->isInCompany($patient->branch->company_id);
    }
}
