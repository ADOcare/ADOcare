<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\User;

class PatientPointPolicy extends BasePolicy
{
    /**
     * Determine whether the user can create a patient point for the given patient.
     */
    public function create(User $user, Patient $patient): bool
    {
        // Superadmin handled by Gate::before

        // Managers can create for any patient in their company
        if ($this->isManager($user) && $user->isInCompany($patient->branch->company_id)) {
            return true;
        }

        // Allow any user assigned to the patient's branch to create points.
        return $user->isInBranch((int) $patient->branch_id);
    }

    public function view(User $user, PatientPoint $point): bool
    {
        // Managers can view points for patients in their company
        if ($this->isManager($user)) {
            return $this->managerCanAccessPoint($user, $point);
        }

        // Allow branch-assigned users to view points in their branch.
        $patient = $point->patient;

        return $patient ? $user->isInBranch((int) $patient->branch_id) : false;
    }

    public function update(User $user, PatientPoint $point): bool
    {
        // Managers can update points in their company
        if ($this->isManager($user)) {
            return $this->managerCanAccessPoint($user, $point);
        }

        // Allow branch-assigned users to update points in their branch.
        $patient = $point->patient;

        return $patient ? $user->isInBranch((int) $patient->branch_id) : false;
    }

    public function delete(User $user, PatientPoint $point): bool
    {
        // Only managers (company) or branch-assigned users may delete
        if ($this->isManager($user)) {
            return $this->managerCanAccessPoint($user, $point);
        }

        $patient = $point->patient;

        return $patient ? $user->isInBranch((int) $patient->branch_id) : false;
    }

    /**
     * Whether the user is allowed to force synchronous bulk deletes for this resource.
     * Accepts either a model instance or a class string as second parameter.
     */
    public function forceBulkDelete(User $user, $model = null): bool
    {
        // Restrict to admins and super-admins by default
        return $this->isAdmin($user);
    }

    private function managerCanAccessPoint(User $user, PatientPoint $point): bool
    {
        $companyId = $point->branch?->company_id;

        return $this->isManager($user)
            && $companyId !== null
            && $user->isInCompany((int) $companyId);
    }
}
