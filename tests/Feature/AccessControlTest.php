<?php

namespace Tests\Feature;

use App\Enums\AccessState;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 2 - access & entitlement enforcement.
 *
 * Covers the access-state model (FULL / READ_ONLY / BLOCKED), backend mutation
 * enforcement, the generic entitlement engine and plan-limit enforcement.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function configureBilling(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);
    }

    private function ensureManagerRole(): int
    {
        if (!Role::where('position', 'manager')->exists()) {
            Role::create(['position' => 'manager', 'scope' => 'company']);
        }

        return (int) Role::where('position', 'manager')->value('id');
    }

    /**
     * Fakes StudioKristian's customer endpoints. Both the bundled snapshot and the separate
     * trial endpoint have to be faked - the access resolver reads the trial through
     * CompanySubscription::trialState().
     */
    private function fakeBilling(array $subscriptions = [], ?array $trial = null): void
    {
        $this->configureBilling();

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => $subscriptions,
                'trial' => $trial,
                'payments' => [],
                'invoices' => [],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([
                'data' => $trial ?? [],
            ], 200),
        ]);
    }

    private function companyWithBilling(array $attributes = []): Company
    {
        return Company::factory()->create(array_merge([
            'status' => 'active',
            'subscription_status' => null,
            'studiokristian_customer_token' => 'customer-token',
        ], $attributes));
    }

    private function managerFor(Company $company): User
    {
        return User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $this->ensureManagerRole(),
        ]);
    }

    private function activeSubscription(array $entitlements = []): array
    {
        return [
            'id' => 1,
            'status' => 'active',
            'plan' => ['id' => 3, 'name' => 'Adocare Pro'],
            'price' => ['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly'],
            'cancel_at_period_end' => false,
            'scheduled_change' => null,
            'payment_status' => 'paid',
            'payment_failed_at' => null,
            'grace_period_ends_at' => null,
            'payment_action_required' => false,
            'current_period_start' => now()->subDays(3)->toIso8601String(),
            'current_period_end' => now()->addDays(27)->toIso8601String(),
            'entitlements' => $entitlements,
        ];
    }

    /**
     * A subscription in StudioKristian's payment-failure state. `grace_period_ends_at` is
     * authoritative upstream (project-configurable); ADOCare only compares it to server time.
     */
    private function pastDueSubscription(?string $graceEndsAt, array $entitlements = []): array
    {
        return array_merge($this->activeSubscription($entitlements), [
            'status' => 'past_due',
            'payment_status' => 'failed',
            'payment_failed_at' => now()->subDays(2)->toIso8601String(),
            'grace_period_ends_at' => $graceEndsAt,
            'payment_action_required' => true,
        ]);
    }

    // ---------------------------------------------------------------- access states

    public function test_active_paid_subscription_resolves_to_full_access(): void
    {
        $this->fakeBilling([$this->activeSubscription()]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::FULL, $access['state']);
        $this->assertTrue(app(CompanyAccessService::class)->allowsMutations($company));
    }

    public function test_active_trial_resolves_to_full_access(): void
    {
        $this->fakeBilling([], [
            'status' => 'active',
            'started_at' => now()->toIso8601String(),
            'ends_at' => now()->addDays(10)->toIso8601String(),
        ]);
        $company = $this->companyWithBilling();

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($company));
    }

    public function test_paid_subscription_wins_over_an_active_trial(): void
    {
        $this->fakeBilling([$this->activeSubscription(['users' => ['type' => 'limit', 'value' => 10]])], [
            'status' => 'active',
            'ends_at' => now()->addDays(3)->toIso8601String(),
        ]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::FULL, $access['state']);
        $this->assertSame('active', $access['billing_state']);
        // Entitlements come from the paid plan, not from the trial.
        $this->assertSame(10, $access['entitlements']->limit('users'));
    }

    public function test_scheduled_cancellation_keeps_full_access_until_the_period_ends(): void
    {
        $subscription = $this->activeSubscription();
        $subscription['cancel_at_period_end'] = true;
        $subscription['canceled_at'] = now()->toIso8601String();
        $this->fakeBilling([$subscription]);
        $company = $this->companyWithBilling();

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($company));
    }

    public function test_scheduled_downgrade_keeps_full_access_and_current_entitlements(): void
    {
        $subscription = $this->activeSubscription(['users' => ['type' => 'limit', 'value' => 10]]);
        $subscription['scheduled_change'] = [
            'plan' => ['id' => 1, 'name' => 'Start'],
            'price' => ['id' => 10, 'amount' => 1900, 'currency' => 'EUR', 'interval' => 'monthly'],
            'effective_at' => now()->addDays(20)->toIso8601String(),
        ];
        $this->fakeBilling([$subscription]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::FULL, $access['state']);
        // The scheduled (smaller) plan must NOT apply before StudioKristian activates it.
        $this->assertSame(10, $access['entitlements']->limit('users'));
    }

    public function test_expired_trial_resolves_to_read_only(): void
    {
        $this->fakeBilling([], ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::READ_ONLY, $access['state']);
        $this->assertSame('trial_expired', $access['reason']);
        $this->assertFalse(app(CompanyAccessService::class)->allowsMutations($company));
    }

    public function test_lapsed_subscription_resolves_to_read_only_not_blocked(): void
    {
        $cancelled = $this->activeSubscription();
        $cancelled['status'] = 'canceled';
        $this->fakeBilling([$cancelled]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::READ_ONLY, $access['state']);
        $this->assertSame('subscription_expired', $access['reason']);
    }

    public function test_company_without_any_billing_relationship_is_blocked(): void
    {
        $company = Company::factory()->create(['status' => 'active', 'subscription_status' => null]);

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::BLOCKED, $access['state']);
        $this->assertSame('no_subscription', $access['reason']);
    }

    public function test_onboarding_company_keeps_full_access(): void
    {
        $company = Company::factory()->create(['status' => 'onboarding', 'subscription_status' => null]);

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($company));
    }

    public function test_user_without_a_company_is_not_gated_by_company_billing_state(): void
    {
        // Phase 1 contract: access is company-scoped, so a user with no Company attached is
        // not restricted here (record-level policies still apply).
        $user = User::factory()->create();

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($user->company));

        // The access gate lets the request through (validation may still reject the payload).
        $status = $this->actingAs($user)->getJson('/api/v1/dekurz/last?patient_id=1')->status();
        $this->assertNotContains($status, [402, 403]);
    }

    public function test_billing_outage_fails_open_instead_of_locking_the_company_out(): void
    {
        $this->configureBilling();
        Http::fake([
            'billing.studiokristian.test/*' => Http::response(['message' => 'boom'], 500),
        ]);

        $company = $this->companyWithBilling([
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->addDays(5),
        ]);

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::FULL, $access['state']);
        $this->assertSame('billing_unavailable', $access['reason']);
    }

    // ------------------------------------------------------- read-only enforcement

    public function test_read_only_company_can_still_read_but_cannot_mutate(): void
    {
        $this->fakeBilling([], ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        $this->actingAs($manager)->getJson('/api/v1/my-company')->assertStatus(200);

        $this->actingAs($manager)
            ->patchJson('/api/v1/my-company', ['name' => 'Zmena'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'READ_ONLY')
            ->assertJsonPath('access_state', 'read_only');
    }

    public function test_read_only_company_can_still_delete_nothing_but_data_is_untouched(): void
    {
        $this->fakeBilling([], ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);
        $colleague = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($manager)
            ->deleteJson("/api/v1/users/{$colleague->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'READ_ONLY');

        $this->assertDatabaseHas('users', ['id' => $colleague->id, 'deleted_at' => null]);
    }

    public function test_read_only_company_can_still_reach_billing_and_renew(): void
    {
        $this->fakeBilling([], ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()]);
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [['id' => 1, 'name' => 'Pro', 'prices' => [['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly']]]],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()],
                'payments' => [],
                'invoices' => [],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response(['data' => []], 200),
            'billing.studiokristian.test/api/v1/billing/checkout' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ], 201),
        ]);

        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        // Billing/renewal endpoints are never gated by the access state.
        $this->actingAs($manager)->getJson('/api/v1/billing/plans')->assertStatus(200);
        $this->actingAs($manager)->getJson('/api/v1/billing/subscription')->assertStatus(200);
        $this->actingAs($manager)->postJson('/api/v1/billing/checkout', [
            'plan_price_id' => 17,
            'success_url' => 'https://adocare.test/billing/success',
            'cancel_url' => 'https://adocare.test/billing/cancel',
        ])->assertStatus(200);
    }

    public function test_read_only_company_can_still_use_post_based_exports(): void
    {
        $this->fakeBilling([], ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        // Marked `subscription.active:read` - a POST that only reads/exports existing data.
        // Any status other than the read-only 403 proves the access gate let it through.
        $resp = $this->actingAs($manager)->postJson('/api/v1/documents/check-exists', [
            'type' => 'proposal',
            'date' => now()->toDateString(),
        ]);

        $this->assertNotSame(403, $resp->status());
    }

    public function test_blocked_company_cannot_reach_the_protected_api_but_billing_still_works(): void
    {
        $this->configureBilling();
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response(['data' => []], 200),
        ]);

        $company = Company::factory()->create(['status' => 'active', 'subscription_status' => null]);
        $manager = $this->managerFor($company);

        $this->actingAs($manager)
            ->getJson('/api/v1/my-company')
            ->assertStatus(402)
            ->assertJsonPath('code', 'ACCESS_BLOCKED')
            ->assertJsonPath('access_state', 'blocked');

        $this->actingAs($manager)->getJson('/api/v1/billing/plans')->assertStatus(200);
    }

    public function test_superadmin_is_never_restricted_by_a_company_billing_state(): void
    {
        if (!Role::where('position', 'superadmin')->exists()) {
            Role::create(['position' => 'superadmin', 'scope' => 'global']);
        }

        $company = Company::factory()->create(['status' => 'active', 'subscription_status' => null]);
        $superadmin = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'superadmin')->value('id'),
        ]);

        $this->actingAs($superadmin)->getJson('/api/v1/my-company')->assertStatus(200);
    }

    // ------------------------------------------------------------ access context API

    public function test_access_endpoint_exposes_state_entitlements_and_usage(): void
    {
        $this->fakeBilling([$this->activeSubscription([
            'users' => ['type' => 'limit', 'value' => 2, 'unit' => 'people'],
            'branches' => ['type' => 'unlimited'],
        ])]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);
        // Three users against a 2-user allowance; the manager is a separate account type.
        User::factory()->count(3)->create(['company_id' => $company->id]);

        $resp = $this->actingAs($manager)->getJson('/api/v1/access');

        $resp->assertStatus(200)
            ->assertJsonPath('data.state', 'full')
            ->assertJsonPath('data.mutations_allowed', true)
            ->assertJsonPath('data.entitlements.users.value', 2)
            ->assertJsonPath('data.entitlements.branches.type', 'unlimited')
            ->assertJsonPath('data.usage.users.limit', 2)
            ->assertJsonPath('data.usage.users.usage', 3)
            ->assertJsonPath('data.usage.users.over_limit', true)
            ->assertJsonPath('data.usage.users.can_add_more', false)
            ->assertJsonPath('data.usage.managers.usage', 1)
            ->assertJsonPath('data.usage.branches.unlimited', true);
    }

    public function test_access_endpoint_is_reachable_while_read_only(): void
    {
        $this->fakeBilling([], ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        $this->actingAs($manager)->getJson('/api/v1/access')
            ->assertStatus(200)
            ->assertJsonPath('data.state', 'read_only')
            ->assertJsonPath('data.mutations_allowed', false);
    }

    // ------------------------------------------------------------------ entitlements

    public function test_entitlement_engine_handles_every_generic_type(): void
    {
        $entitlements = EntitlementService::fromArray([
            'priority_support' => ['type' => 'boolean', 'value' => true],
            'disabled_feature' => ['type' => 'boolean', 'value' => false],
            'users' => ['type' => 'limit', 'value' => 10, 'unit' => 'people'],
            'branches' => ['type' => 'unlimited'],
            'a_totally_project_specific_thing' => ['type' => 'custom', 'value' => ['x' => 1]],
        ]);

        $this->assertTrue($entitlements->has('priority_support'));
        $this->assertFalse($entitlements->has('disabled_feature'));

        $this->assertSame(10, $entitlements->limit('users'));
        $this->assertSame('people', $entitlements->unit('users'));
        $this->assertFalse($entitlements->isUnlimited('users'));
        $this->assertTrue($entitlements->allows('users', 10));
        $this->assertFalse($entitlements->allows('users', 11));

        $this->assertTrue($entitlements->isUnlimited('branches'));
        $this->assertNull($entitlements->limit('branches'));
        $this->assertTrue($entitlements->allows('branches', 999999));

        $this->assertTrue($entitlements->has('a_totally_project_specific_thing'));
        $this->assertSame(['x' => 1], $entitlements->custom('a_totally_project_specific_thing'));
    }

    public function test_missing_optional_feature_never_breaks_or_blocks(): void
    {
        // A project that simply does not define `ai_credits` (or anything else) must work.
        $entitlements = EntitlementService::fromArray([]);

        $this->assertFalse($entitlements->has('ai_credits'));
        $this->assertNull($entitlements->limit('ai_credits'));
        $this->assertNull($entitlements->get('ai_credits'));
        $this->assertFalse($entitlements->isUnlimited('ai_credits'));
        $this->assertTrue($entitlements->allows('ai_credits', 5000));
        $this->assertFalse($entitlements->isOverLimit('ai_credits', 5000));
    }

    public function test_entitlements_are_read_from_the_active_plan_without_hardcoding_keys(): void
    {
        $this->fakeBilling([$this->activeSubscription([
            'ai_credits' => ['type' => 'limit', 'value' => 50000, 'unit' => 'credits'],
            'data_migration' => ['type' => 'boolean', 'value' => true],
        ])]);
        $company = $this->companyWithBilling();

        $entitlements = app(CompanyAccessService::class)->entitlements($company);

        $this->assertSame(50000, $entitlements->limit('ai_credits'));
        $this->assertTrue($entitlements->has('data_migration'));
        // Entitlement allowance is not a usage balance - nothing here consumes credits.
        $this->assertSame(50000, $entitlements->usage('ai_credits', 0)['limit']);
    }

    // ------------------------------------------------------------------- plan limits

    public function test_user_creation_is_allowed_below_the_plan_seat_limit(): void
    {
        $this->fakeBilling([$this->activeSubscription(['users' => ['type' => 'limit', 'value' => 3]])]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        $resp = $this->actingAs($manager)->postJson('/api/v1/users', [
            'first_name' => 'Nový',
            'last_name' => 'Kolega',
            'email' => 'novy@example.com',
            'login' => 'novy',
            'pin' => '1234',
            'company_id' => $company->id,
        ]);

        $this->assertContains($resp->status(), [200, 201], 'Creating a user below the limit must be allowed.');
    }

    public function test_user_creation_is_rejected_exactly_at_the_plan_seat_limit(): void
    {
        $this->fakeBilling([$this->activeSubscription(['users' => ['type' => 'limit', 'value' => 1]])]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);
        // The manager does not occupy a user seat - a real user has to fill it.
        User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/users', [
            'first_name' => 'Nový',
            'last_name' => 'Kolega',
            'email' => 'novy@example.com',
            'login' => 'novy',
            'pin' => '1234',
            'company_id' => $company->id,
        ]);

        $resp->assertStatus(403)
            ->assertJsonPath('code', 'ENTITLEMENT_LIMIT_REACHED')
            ->assertJsonPath('feature', 'users')
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('current', 1);
    }

    public function test_unlimited_seat_entitlement_never_blocks_user_creation(): void
    {
        $this->fakeBilling([$this->activeSubscription(['users' => ['type' => 'unlimited']])]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);
        User::factory()->count(5)->create(['company_id' => $company->id]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/users', [
            'first_name' => 'Ďalší',
            'last_name' => 'Kolega',
            'email' => 'dalsi@example.com',
            'login' => 'dalsi',
            'pin' => '1234',
            'company_id' => $company->id,
        ]);

        $this->assertContains($resp->status(), [200, 201]);
    }

    public function test_downgrade_below_current_usage_keeps_existing_users_and_only_blocks_growth(): void
    {
        // Plan now allows 2 seats, but the company already has 4 users from a bigger plan.
        $this->fakeBilling([$this->activeSubscription(['users' => ['type' => 'limit', 'value' => 2]])]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);
        User::factory()->count(3)->create(['company_id' => $company->id]);

        $existingIds = User::where('company_id', $company->id)->pluck('id');
        $this->assertCount(4, $existingIds);

        $resp = $this->actingAs($manager)->postJson('/api/v1/users', [
            'first_name' => 'Piaty',
            'last_name' => 'Kolega',
            'email' => 'piaty@example.com',
            'login' => 'piaty',
            'pin' => '1234',
            'company_id' => $company->id,
        ]);

        $resp->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_LIMIT_REACHED');

        // Nothing was deleted, deactivated or otherwise touched by the downgrade.
        foreach ($existingIds as $id) {
            $this->assertDatabaseHas('users', ['id' => $id, 'deleted_at' => null]);
        }

        // ... and the over-limit condition is reported generically.
        $usage = app(CompanyAccessService::class)->entitlements($company)->usage('users', 4);
        $this->assertTrue($usage['over_limit']);
        $this->assertSame(2, $usage['limit']);
        $this->assertSame(4, $usage['usage']);
    }

    public function test_plan_without_a_seat_entitlement_falls_back_to_the_legacy_limit(): void
    {
        $this->fakeBilling([$this->activeSubscription([])]);
        $company = $this->companyWithBilling(['subscription_users_limit_override' => 1]);
        $manager = $this->managerFor($company);
        User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/users', [
            'first_name' => 'Nový',
            'last_name' => 'Kolega',
            'email' => 'novy@example.com',
            'login' => 'novy',
            'pin' => '1234',
            'company_id' => $company->id,
        ]);

        $resp->assertStatus(403)->assertJsonPath('limit', 1);
    }

    // ---------------------------------------------------------------------- security

    public function test_access_state_is_resolved_per_company_and_cannot_be_borrowed(): void
    {
        $this->configureBilling();
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => function ($request) {
                return $request->hasHeader('X-Billing-Customer-Token', 'token-a')
                    ? Http::response(['subscriptions' => [$this->activeSubscription()], 'trial' => null, 'payments' => [], 'invoices' => []], 200)
                    : Http::response(['subscriptions' => [], 'trial' => ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()], 'payments' => [], 'invoices' => []], 200);
            },
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response(['data' => []], 200),
        ]);

        $companyA = $this->companyWithBilling(['studiokristian_customer_token' => 'token-a']);
        $companyB = $this->companyWithBilling(['studiokristian_customer_token' => 'token-b']);

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($companyA));
        $this->assertSame(AccessState::READ_ONLY, app(CompanyAccessService::class)->state($companyB));
    }

    public function test_browser_cannot_select_another_company_to_escape_read_only(): void
    {
        $this->fakeBilling([], ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()]);
        $readOnlyCompany = $this->companyWithBilling();
        $manager = $this->managerFor($readOnlyCompany);
        $otherCompany = Company::factory()->create(['status' => 'active']);

        // Company id supplied by the client is irrelevant - access is resolved from the session.
        $this->actingAs($manager)
            ->patchJson('/api/v1/my-company', ['name' => 'Hack', 'company_id' => $otherCompany->id])
            ->assertStatus(403)
            ->assertJsonPath('code', 'READ_ONLY');
    }

    // ------------------------------------------------------- subscription change flow

    public function test_changing_plan_refreshes_entitlements_from_the_authoritative_response(): void
    {
        $this->configureBilling();

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [['id' => 3, 'name' => 'Pro', 'prices' => [['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly']]]],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscription/change' => Http::response([
                'data' => $this->activeSubscription(['users' => ['type' => 'limit', 'value' => 25]]),
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [$this->activeSubscription(['users' => ['type' => 'limit', 'value' => 25]])],
                'trial' => null,
                'payments' => [],
                'invoices' => [],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response(['data' => []], 200),
        ]);

        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        // Warm the cache with the OLD entitlements so we prove invalidation actually happens.
        cache()->put("studiokristian_billing:subscriptions:{$company->id}", [
            $this->activeSubscription(['users' => ['type' => 'limit', 'value' => 2]]),
        ], 60);

        $resp = $this->actingAs($manager)->postJson('/api/v1/billing/subscription/change', [
            'plan_price_id' => 17,
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('data.access.state', 'full')
            ->assertJsonPath('data.access.entitlements.users.value', 25);

        $this->assertSame(25, app(CompanyAccessService::class)->entitlements($company->fresh())->limit('users'));
    }
}
