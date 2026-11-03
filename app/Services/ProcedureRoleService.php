<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcedureRoleService
{
    public function scopeForUser(Builder $query, User $user, ?int $branchId): Builder
    {
        if ($this->canUseAllProcedures($user)) {
            return $query;
        }

        $roleId = $this->branchRoleId($user, $branchId);
        if (! $roleId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('roles', fn (Builder $roles) => $roles->whereKey($roleId));
    }

    public function ensureAllowed(User $user, int $procedureId, int $branchId, string $field = 'procedure_id'): void
    {
        if ($this->canUseAllProcedures($user)) {
            return;
        }

        $roleId = $this->branchRoleId($user, $branchId);
        $allowed = $roleId && DB::table('procedure_role')
            ->where('procedure_id', $procedureId)
            ->where('role_id', $roleId)
            ->exists();

        if (! $allowed) {
            throw ValidationException::withMessages([
                $field => 'Tento výkon nie je povolený pre vašu rolu v danej pobočke.',
            ]);
        }
    }

    private function canUseAllProcedures(User $user): bool
    {
        return $user->hasGlobalRole('manager') || $user->hasGlobalRole('superadmin');
    }

    private function branchRoleId(User $user, ?int $branchId): ?int
    {
        if (! $branchId) {
            return null;
        }

        $assignment = DB::table('user_branches')
            ->where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->first();
        if (! $assignment) {
            return null;
        }

        if ($assignment->role_id) {
            return (int) $assignment->role_id;
        }

        if ($user->role_id) {
            $isBranchRole = DB::table('roles')
                ->where('id', $user->role_id)
                ->where('scope', 'branch')
                ->exists();
            if ($isBranchRole) {
                return (int) $user->role_id;
            }
        }

        $nurseRoleId = DB::table('roles')->where('position', 'nurse')->value('id');

        return $nurseRoleId ? (int) $nurseRoleId : null;
    }
}
