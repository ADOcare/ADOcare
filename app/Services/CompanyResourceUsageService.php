<?php

namespace App\Services;

use App\Exceptions\EntitlementLimitException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanySubscription;
use Illuminate\Support\Facades\DB;

/**
 * Counts what a Company actually uses and compares it against the plan allowance resolved
 * by {@see EntitlementService}.
 *
 * Entitlement != usage: the entitlement says "the plan allows X", this service answers
 * "the company currently uses Y". Feature keys are data owned by StudioKristian - the only
 * ADOCare-specific knowledge here is HOW to count each metered resource.
 *
 * Managers and Users are separate account types in ADOCare and therefore separate
 * allowances: a manager never consumes a user seat and vice versa.
 */
class CompanyResourceUsageService
{
    /** Feature keys ADOCare can actually meter. AI credits are deliberately absent - an
     *  allowance without consumption accounting must never be reported as usage. */
    public const METERED_FEATURES = ['users', 'managers', 'branches'];

    public function __construct(private CompanyAccessService $access)
    {
    }

    public function currentUsage(Company $company, string $feature): int
    {
        return match ($feature) {
            'users' => $this->userQuery($company)->count(),
            'managers' => $this->managerQuery($company)->count(),
            'branches' => Branch::query()->where('company_id', $company->id)->count(),
            default => 0,
        };
    }

    /**
     * Usage snapshot for every metered resource, e.g.
     * `['users' => ['usage' => 4, 'limit' => 5, 'over_limit' => false, ...], ...]`.
     */
    public function usageSnapshot(?Company $company): array
    {
        if (!$company) {
            return [];
        }

        $entitlements = $this->access->entitlements($company);
        $snapshot = [];

        foreach (self::METERED_FEATURES as $feature) {
            $usage = $entitlements->usage($feature, $this->currentUsage($company, $feature));
            $limit = $this->resolveLimit($company, $feature);

            $snapshot[$feature] = array_merge($usage, [
                'limit' => $limit,
                'unlimited' => $limit === null,
                'over_limit' => $limit !== null && $usage['usage'] > $limit,
                'can_add_more' => $limit === null || $usage['usage'] < $limit,
            ]);
        }

        return $snapshot;
    }

    /**
     * Runs `$create` only if adding one more of `$feature` stays within the plan allowance.
     *
     * The check and the insert share one transaction and take a row lock on the Company, so
     * two simultaneous requests cannot both pass a check that only one of them may win.
     *
     * @template T
     * @param  \Closure(): T  $create
     * @return T
     *
     * @throws EntitlementLimitException
     */
    public function createWithinLimit(Company $company, string $feature, \Closure $create): mixed
    {
        return DB::transaction(function () use ($company, $feature, $create) {
            // Serialises concurrent creations for this Company; the lock is released with the
            // transaction, so it only ever holds for the duration of one create.
            Company::query()->whereKey($company->id)->lockForUpdate()->first();

            $this->assertCanAdd($company, $feature);

            return $create();
        });
    }

    /**
     * @throws EntitlementLimitException
     */
    public function assertCanAdd(Company $company, string $feature, int $adding = 1): void
    {
        $limit = $this->resolveLimit($company, $feature);

        if ($limit === null) {
            return;
        }

        $current = $this->currentUsage($company, $feature);

        if ($current + $adding > $limit) {
            throw new EntitlementLimitException(
                $this->limitMessage($feature, $limit),
                $feature,
                $limit,
                $current,
            );
        }
    }

    /**
     * The plan entitlement is authoritative. Companies with no `users` entitlement (not yet on
     * a StudioKristian-priced plan) keep the legacy tier/override seat cap so nothing regresses.
     */
    public function resolveLimit(Company $company, string $feature): ?int
    {
        $entitlements = $this->access->entitlements($company);

        if ($entitlements->get($feature) !== null) {
            return $entitlements->limit($feature);
        }

        return $feature === 'users' ? CompanySubscription::effectiveUsersLimit($company) : null;
    }

    /**
     * Users are every company account that is not a manager. Superadmins are platform staff
     * and are counted as neither.
     */
    private function userQuery(Company $company)
    {
        return User::query()
            ->where('company_id', $company->id)
            ->whereDoesntHave('role', fn ($role) => $role->whereIn('position', ['manager', 'superadmin']));
    }

    private function managerQuery(Company $company)
    {
        return User::query()
            ->where('company_id', $company->id)
            ->whereHas('role', fn ($role) => $role->where('position', 'manager'));
    }

    private function limitMessage(string $feature, int $limit): string
    {
        return match ($feature) {
            'users' => "Spoločnosť dosiahla limit používateľov pre aktuálne predplatné ({$limit}).",
            'managers' => "Spoločnosť dosiahla limit manažérov pre aktuálne predplatné ({$limit}).",
            'branches' => "Spoločnosť dosiahla limit pobočiek pre aktuálne predplatné ({$limit}).",
            default => "Spoločnosť dosiahla limit pre '{$feature}' ({$limit}).",
        };
    }
}
