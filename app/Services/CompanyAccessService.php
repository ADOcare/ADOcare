<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanySubscription;
use Illuminate\Support\Carbon;

/**
 * The single authoritative answer to "what is this Company currently allowed to do?".
 *
 * Billing state (what subscription exists) and access state (what the application permits)
 * are deliberately separate concepts: StudioKristian owns the former, ADOCare owns the
 * latter. Nothing outside this service may derive application access from a raw
 * subscription status - controllers, middleware, policies and the frontend all consume the
 * resolved state produced here.
 *
 * Resolution rules:
 *   active paid subscription            -> FULL
 *   active application trial            -> FULL
 *   cancellation scheduled at period end-> FULL   (StudioKristian keeps status `active`
 *                                                  until the period genuinely ends, so no
 *                                                  special-casing is needed or wanted here)
 *   downgrade scheduled                 -> FULL   (with the CURRENT plan's entitlements -
 *                                                  the scheduled plan only takes effect once
 *                                                  StudioKristian's webhook activates it)
 *   expired trial / lapsed subscription -> READ_ONLY
 *   no billing relationship at all      -> BLOCKED
 */
class CompanyAccessService
{
    /**
     * @return array{
     *     state: AccessState,
     *     billing_state: string,
     *     reason: string|null,
     *     entitlements: EntitlementService,
     *     current: array|null
     * }
     */
    public function resolve(?Company $company): array
    {
        if (!$company) {
            // Access is company-scoped. A user that is not attached to a Company has no
            // company-owned data to gate here (record-level policies still apply), and
            // Phase 1 treated this the same way - it must not become a lockout.
            return $this->result(AccessState::FULL, 'none', null);
        }

        $resolved = $this->resolveBillingBackedState($company);

        // Companies still going through onboarding have not been billed yet by design and
        // keep the access they had in Phase 1 - the onboarding/billing flow is what grants
        // them a trial in the first place. Their plan entitlements (if a plan is already
        // active) are still resolved, so limits stay consistent across the whole lifecycle.
        if ($company->isOnboarding() && $resolved['state'] !== AccessState::FULL) {
            return $this->result(AccessState::FULL, 'onboarding', null, $resolved['entitlements'], $resolved['current']);
        }

        return $resolved;
    }

    private function resolveBillingBackedState(Company $company): array
    {
        $trial = CompanySubscription::trialState($company);
        $subscriptions = CompanySubscription::cachedSubscriptions($company);

        if ($subscriptions === null) {
            // StudioKristian is unreachable. Failing closed here would lock paying customers
            // out of their own data during an upstream outage, so access falls back to the
            // legacy local billing state exactly like Phase 1's availability check did.
            return CompanySubscription::hasActiveSubscription($company)
                ? $this->result(AccessState::FULL, 'unknown', 'billing_unavailable')
                : $this->result(AccessState::READ_ONLY, 'unknown', 'billing_unavailable');
        }

        $current = CompanySubscription::resolveCurrentState($trial, $subscriptions);

        // A past_due subscription is still the Company's real billing relationship, so it
        // outranks any still-running trial. StudioKristian owns the grace window; ADOCare
        // only compares it against server time.
        if ($current['type'] !== 'subscription') {
            $pastDue = $this->findPastDueSubscription($subscriptions);

            if ($pastDue) {
                return $this->resolvePastDue($pastDue);
            }
        }

        return match ($current['type']) {
            'subscription' => $this->result(
                AccessState::FULL,
                'active',
                null,
                EntitlementService::fromArray($current['subscription']['entitlements'] ?? []),
                $current
            ),
            'trial' => $this->result(AccessState::FULL, 'trial', null, null, $current),
            'expired_trial' => $this->result(AccessState::READ_ONLY, 'expired', 'trial_expired', null, $current),
            default => $this->resolveWithoutCurrentBilling($company, $current),
        };
    }

    /**
     * @param  array<int, array>  $subscriptions
     */
    private function findPastDueSubscription(array $subscriptions): ?array
    {
        return collect($subscriptions)->first(
            fn ($subscription) => strtolower(trim((string) ($subscription['status'] ?? ''))) === 'past_due'
        );
    }

    /**
     * Payment failed but the customer keeps working until StudioKristian's grace window ends.
     * The window itself (`grace_period_ends_at`) is authoritative and project-configurable
     * upstream - it is never recomputed from payment_failed_at + a hardcoded number of days.
     */
    private function resolvePastDue(array $subscription): array
    {
        $entitlements = EntitlementService::fromArray($subscription['entitlements'] ?? []);
        $current = ['type' => 'subscription', 'subscription' => $subscription];
        $graceEndsAt = $this->parseTimestamp($subscription['grace_period_ends_at'] ?? null);

        // Missing grace window: StudioKristian only omits it when no payment failure is
        // recorded, so there is no evidence the customer ran out of time. Keep them working
        // (fail open) rather than locking them out on incomplete upstream data.
        if ($graceEndsAt === null || now()->lt($graceEndsAt)) {
            return $this->result(
                AccessState::FULL,
                'past_due',
                'payment_failed_grace_period',
                $entitlements,
                $current
            );
        }

        return $this->result(
            AccessState::READ_ONLY,
            'past_due',
            'payment_failed_grace_expired',
            $entitlements,
            $current
        );
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function state(?Company $company): AccessState
    {
        return $this->resolve($company)['state'];
    }

    public function hasFullAccess(?Company $company): bool
    {
        return $this->state($company) === AccessState::FULL;
    }

    public function isReadOnly(?Company $company): bool
    {
        return $this->state($company) === AccessState::READ_ONLY;
    }

    public function isBlocked(?Company $company): bool
    {
        return $this->state($company) === AccessState::BLOCKED;
    }

    /**
     * Whether ordinary application mutations (create/edit/delete/generate/send/upload) are
     * currently permitted. Only FULL access allows them.
     */
    public function allowsMutations(?Company $company): bool
    {
        return $this->hasFullAccess($company);
    }

    /**
     * Machine-readable reason the Company is restricted, or null when it is not.
     */
    public function restrictionReason(?Company $company): ?string
    {
        return $this->resolve($company)['reason'];
    }

    public function entitlements(?Company $company): EntitlementService
    {
        return $this->resolve($company)['entitlements'];
    }

    /**
     * Access is always resolved for the authenticated user's own Company. Superadmins are
     * platform staff and are never subject to a customer's billing state.
     */
    public function resolveForUser(?User $user): array
    {
        if ($user?->hasGlobalRole('superadmin')) {
            return $this->result(AccessState::FULL, 'staff', null);
        }

        return $this->resolve($user?->company);
    }

    /**
     * Serialisable projection for API responses / the frontend access context.
     */
    public function toArray(?Company $company): array
    {
        $resolved = $this->resolve($company);

        return [
            'state' => $resolved['state']->value,
            'billing_state' => $resolved['billing_state'],
            'reason' => $resolved['reason'],
            'full_access' => $resolved['state'] === AccessState::FULL,
            'read_only' => $resolved['state'] === AccessState::READ_ONLY,
            'blocked' => $resolved['state'] === AccessState::BLOCKED,
            'mutations_allowed' => $resolved['state'] === AccessState::FULL,
            'entitlements' => $resolved['entitlements']->all(),
            'payment' => $this->paymentContext($resolved['current']),
        ];
    }

    /**
     * Payment/grace facts exactly as StudioKristian reports them, for display only - the
     * FULL vs READ_ONLY decision above is the single source of truth and is never recomputed
     * by the client.
     */
    private function paymentContext(?array $current): ?array
    {
        $subscription = $current['subscription'] ?? null;

        if (!is_array($subscription)) {
            return null;
        }

        return [
            'status' => $subscription['status'] ?? null,
            'payment_status' => $subscription['payment_status'] ?? null,
            'payment_failed_at' => $subscription['payment_failed_at'] ?? null,
            'grace_period_ends_at' => $subscription['grace_period_ends_at'] ?? null,
            'payment_action_required' => (bool) ($subscription['payment_action_required'] ?? false),
            'current_period_start' => $subscription['current_period_start'] ?? null,
            'current_period_end' => $subscription['current_period_end'] ?? null,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * No active StudioKristian trial or subscription. Companies that never migrated to
     * StudioKristian still fall back to the legacy locally-tracked paid months so existing
     * customers keep working exactly as before.
     */
    private function resolveWithoutCurrentBilling(Company $company, array $current): array
    {
        if (!$company->hasBillingCustomerToken()) {
            if (CompanySubscription::hasActiveLegacySubscription($company)) {
                return $this->result(AccessState::FULL, 'active', null, null, $current);
            }

            // A legacy Company that was billed at some point but is not paid up right now is
            // treated as lapsed (read-only) rather than never-activated (blocked).
            if ($company->subscriptionPaidMonths()->exists()) {
                return $this->result(AccessState::READ_ONLY, 'expired', 'subscription_expired', null, $current);
            }

            return $this->result(AccessState::BLOCKED, 'none', 'no_subscription', null, $current);
        }

        // The Company has a StudioKristian billing relationship but nothing active right now
        // (subscription genuinely ended, or a trial converted and the subscription later
        // lapsed). Its data must stay readable and the renewal path must stay open.
        return $this->result(AccessState::READ_ONLY, 'expired', 'subscription_expired', null, $current);
    }

    private function result(
        AccessState $state,
        string $billingState,
        ?string $reason,
        ?EntitlementService $entitlements = null,
        ?array $current = null
    ): array {
        return [
            'state' => $state,
            'billing_state' => $billingState,
            'reason' => $reason,
            'entitlements' => $entitlements ?? EntitlementService::fromArray([]),
            'current' => $current,
        ];
    }
}
