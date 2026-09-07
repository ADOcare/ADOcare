<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;

class UserService
{
    public function __construct(private CompanyResourceUsageService $usage)
    {
    }

    public function create(array $data): User
    {
        // only administrators may set the global/system role
        if (isset($data['role_id']) && !auth()->user()?->hasGlobalRole('admin')) {
            unset($data['role_id']);
        }

        // Don't manually hash - let the model's hashed cast handle it
        // The 'pin' cast in User model will automatically hash the value

        $companyId = $data['company_id'] ?? null;
        $actor = auth()->user();

        // Only platform staff may place an account in another Company - a browser-supplied
        // company_id must never move an account (or its seat usage) outside the actor's own.
        if (!$actor?->hasGlobalRole('superadmin')) {
            $companyId = $actor?->company_id;
            $data['company_id'] = $companyId;
        }

        if (!$companyId) {
            $companyId = $actor?->company_id;
            $data['company_id'] = $companyId;
        }

        $branches = $data['branches'] ?? null;
        unset($data['branches']);

        $company = Company::query()->find((int) $companyId);
        $feature = $this->seatFeatureFor($data['role_id'] ?? null);

        $user = $company
            ? $this->usage->createWithinLimit($company, $feature, fn () => User::create($data))
            : User::create($data);

        if ($branches && is_array($branches)) {
            $sync = [];
            foreach ($branches as $b) {
                if (empty($b['branch_id'])) continue;

                $sync[(int) $b['branch_id']] = [
                    'working_time' => $b['working_time'] ?? null,
                    'role_id' => $b['role_id'] ?? null,
                ];
            }
            if (!empty($sync)) $user->branches()->sync($sync);
        }

        return $user->fresh()->load(['branches', 'role']);
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['role_id']) && !auth()->user()?->hasGlobalRole('admin')) {
            unset($data['role_id']);
        }

        // Don't manually hash - let the model's hashed cast handle it
        // The 'pin' cast in User model will automatically hash the value

        $branches = $data['branches'] ?? null;
        unset($data['branches']);

        $targetCompanyId = (int) ($data['company_id'] ?? $user->company_id);
        $movingCompany = $targetCompanyId !== (int) $user->company_id;
        $targetFeature = $this->seatFeatureFor(
            array_key_exists('role_id', $data) ? $data['role_id'] : $user->role_id
        );
        // Promotion consumes a manager slot even though no account is added.
        $changingSeatType = $targetFeature !== $this->seatFeatureFor($user->role_id);

        $company = ($movingCompany || $changingSeatType)
            ? Company::query()->find($targetCompanyId)
            : null;

        if ($company) {
            $this->usage->createWithinLimit($company, $targetFeature, fn () => $user->update($data));
        } else {
            $user->update($data);
        }

        if ($branches && is_array($branches)) {
            $sync = [];
            foreach ($branches as $b) {
                if (empty($b['branch_id'])) continue;

                $sync[(int) $b['branch_id']] = [
                    'working_time' => $b['working_time'] ?? null,
                    'role_id' => $b['role_id'] ?? null,
                ];
            }
            $user->branches()->sync($sync);
        }

        return $user->fresh()->load(['branches', 'role']);
    }

    /**
     * Managers and users are separate account types with separate plan allowances, so the
     * account's system role decides which allowance a new/promoted account draws from.
     */
    private function seatFeatureFor(mixed $roleId): string
    {
        if (!$roleId) {
            return 'users';
        }

        $position = Role::query()->whereKey($roleId)->value('position');

        return strtolower(trim((string) $position)) === 'manager' ? 'managers' : 'users';
    }
}