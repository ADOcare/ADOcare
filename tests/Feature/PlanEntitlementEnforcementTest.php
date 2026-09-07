<?php

namespace Tests\Feature;

use App\Enums\AccessState;
use App\Exceptions\EntitlementRequiredException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\CompanyResourceUsageService;
use App\Services\PlanCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4 - complete plan entitlement enforcement.
 *
 * Resource limits (users/managers/branches), boolean capabilities (data_migration/
 * customization/support) and the AI allowance (ai_credits) all come from StudioKristian.
 * No plan name or plan number is hardcoded in ADOCare - the fixtures below mirror the real
 * commercial plans only so the tests exercise realistic values.
 */
class PlanEntitlementEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** Entitlement payloads exactly as StudioKristian publishes them for the real plans. */
    private const PLANS = [
        'start' => [
            'users' => ['type' => 'limit', 'value' => 2, 'unit' => 'people'],
            'managers' => ['type' => 'limit', 'value' => 1, 'unit' => 'people'],
            'branches' => ['type' => 'limit', 'value' => 1, 'unit' => 'branches'],
            'ai_credits' => ['type' => 'limit', 'value' => 500, 'unit' => 'credits'],
            'data_migration' => ['type' => 'boolean', 'value' => false],
            'customization' => ['type' => 'boolean', 'value' => false],
            'support' => ['type' => 'boolean', 'value' => false],
        ],
        'growth' => [
            'users' => ['type' => 'limit', 'value' => 5, 'unit' => 'people'],
            'managers' => ['type' => 'limit', 'value' => 1, 'unit' => 'people'],
            'branches' => ['type' => 'limit', 'value' => 3, 'unit' => 'branches'],
            'ai_credits' => ['type' => 'limit', 'value' => 2500, 'unit' => 'credits'],
            'data_migration' => ['type' => 'boolean', 'value' => true],
            'customization' => ['type' => 'boolean', 'value' => false],
            'support' => ['type' => 'boolean', 'value' => true],
        ],
        'pro' => [
            'users' => ['type' => 'limit', 'value' => 25, 'unit' => 'people'],
            'managers' => ['type' => 'limit', 'value' => 3, 'unit' => 'people'],
            'branches' => ['type' => 'limit', 'value' => 10, 'unit' => 'branches'],
            'ai_credits' => ['type' => 'limit', 'value' => 10000, 'unit' => 'credits'],
            'data_migration' => ['type' => 'boolean', 'value' => true],
            'customization' => ['type' => 'boolean', 'value' => true],
            'support' => ['type' => 'boolean', 'value' => true],
        ],
    ];

    private function ensureRoles(): void
    {
        foreach ([['manager', 'company'], ['nurse', 'branch'], ['superadmin', 'global']] as [$position, $scope]) {
            if (!Role::where('position', $position)->exists()) {
                Role::create(['position' => $position, 'scope' => $scope]);
            }
        }
    }

    private function roleId(string $position): int
    {
        $this->ensureRoles();

        return (int) Role::where('position', $position)->value('id');
    }

    /** Mutable plan key so a test can switch plans the way StudioKristian would. */
    private string $activePlan = 'start';

    private array $planOverrides = [];

    /**
     * Registers ONE stub whose response follows $this->activePlan. Calling Http::fake() again
     * would not override an earlier stub for the same URL, so plan switches are modelled here.
     */
    private function fakePlan(string $plan, array $overrides = []): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $this->activePlan = $plan;
        $this->planOverrides = $overrides;

        if ($this->billingFaked) {
            return;
        }

        $this->billingFaked = true;

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => function () {
                return Http::response([
                    'subscriptions' => [$this->subscriptionPayload()],
                    'trial' => null,
                    'payments' => [],
                    'invoices' => [],
                ], 200);
            },
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response(['data' => []], 200),
        ]);
    }

    private bool $billingFaked = false;

    private function subscriptionPayload(): array
    {
        return array_merge([
            'id' => 1,
            'status' => 'active',
            'plan' => ['id' => 1, 'name' => ucfirst($this->activePlan)],
            'price' => ['id' => 10, 'amount' => 1900, 'currency' => 'EUR', 'interval' => 'monthly'],
            'cancel_at_period_end' => false,
            'scheduled_change' => null,
            'payment_status' => 'paid',
            'payment_failed_at' => null,
            'grace_period_ends_at' => null,
            'payment_action_required' => false,
            'entitlements' => self::PLANS[$this->activePlan],
        ], $this->planOverrides);
    }

    private function company(): Company
    {
        return Company::factory()->create([
            'status' => 'active',
            'subscription_status' => null,
            'studiokristian_customer_token' => 'customer-token',
        ]);
    }

    private function manager(Company $company): User
    {
        return User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('manager'),
        ]);
    }

    private function seedUsers(Company $company, int $count): void
    {
        User::factory()->count($count)->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('nurse'),
        ]);
    }

    private function seedBranches(Company $company, int $count): void
    {
        Branch::factory()->count($count)->create(['company_id' => $company->id]);
    }

    private function createUserRequest(Company $company, string $login, ?int $roleId = null): array
    {
        return array_filter([
            'first_name' => 'Nov\u00fd',
            'last_name' => 'Kolega',
            'email' => "{$login}@example.com",
            'login' => $login,
            'pin' => '1234',
            'company_id' => $company->id,
            'role_id' => $roleId,
        ], fn ($v) => $v !== null);
    }

    private function branchPayload(string $code): array
    {
        return [
            'code' => $code,
            'identificator' => $code,
            'address' => 'Hlavn\u00e1 1',
            'city' => 'Bratislava',
            'psc' => '81101',
        ];
    }

    // ------------------------------------------------------------------ entitlements

    /** @dataProvider planMatrix */
    public function test_plan_entitlements_are_read_from_studiokristian(string $plan, array $expected): void
    {
        $this->fakePlan($plan);
        $company = $this->company();

        $entitlements = app(CompanyAccessService::class)->entitlements($company);

        $this->assertSame($expected['users'], $entitlements->limit('users'));
        $this->assertSame($expected['managers'], $entitlements->limit('managers'));
        $this->assertSame($expected['branches'], $entitlements->limit('branches'));
        $this->assertSame($expected['ai_credits'], $entitlements->limit('ai_credits'));
        $this->assertSame($expected['data_migration'], $entitlements->has('data_migration'));
        $this->assertSame($expected['customization'], $entitlements->has('customization'));
        $this->assertSame($expected['support'], $entitlements->has('support'));
    }

    public static function planMatrix(): array
    {
        return [
            'Start' => ['start', ['users' => 2, 'managers' => 1, 'branches' => 1, 'ai_credits' => 500, 'data_migration' => false, 'customization' => false, 'support' => false]],
            'Growth' => ['growth', ['users' => 5, 'managers' => 1, 'branches' => 3, 'ai_credits' => 2500, 'data_migration' => true, 'customization' => false, 'support' => true]],
            'Pro' => ['pro', ['users' => 25, 'managers' => 3, 'branches' => 10, 'ai_credits' => 10000, 'data_migration' => true, 'customization' => true, 'support' => true]],
        ];
    }

    // ------------------------------------------------------------------- users limit

    /** @dataProvider userLimits */
    public function test_user_creation_is_rejected_at_the_plan_user_limit(string $plan, int $limit): void
    {
        $this->fakePlan($plan);
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedUsers($company, $limit);

        $resp = $this->actingAs($manager)->postJson('/api/v1/users', $this->createUserRequest($company, 'over'));

        $resp->assertStatus(403)
            ->assertJsonPath('code', 'ENTITLEMENT_LIMIT_REACHED')
            ->assertJsonPath('feature', 'users')
            ->assertJsonPath('limit', $limit)
            ->assertJsonPath('current', $limit);
    }

    public static function userLimits(): array
    {
        return ['Start 2' => ['start', 2], 'Growth 5' => ['growth', 5], 'Pro 25' => ['pro', 25]];
    }

    public function test_user_creation_is_allowed_below_the_limit(): void
    {
        $this->fakePlan('growth');
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedUsers($company, 4);

        $resp = $this->actingAs($manager)->postJson('/api/v1/users', $this->createUserRequest($company, 'fifth'));

        $this->assertContains($resp->status(), [200, 201]);
        $this->assertSame(5, app(CompanyResourceUsageService::class)->currentUsage($company, 'users'));
    }

    public function test_managers_do_not_consume_user_seats(): void
    {
        // Start allows 2 users + 1 manager = 3 accounts in total.
        $this->fakePlan('start');
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedUsers($company, 2);

        $usage = app(CompanyResourceUsageService::class);

        $this->assertSame(2, $usage->currentUsage($company, 'users'));
        $this->assertSame(1, $usage->currentUsage($company, 'managers'));

        $snapshot = $usage->usageSnapshot($company);
        $this->assertFalse($snapshot['users']['over_limit']);
        $this->assertFalse($snapshot['managers']['over_limit']);

        // The third user is refused, but the existing manager is untouched by that limit.
        $this->actingAs($manager)
            ->postJson('/api/v1/users', $this->createUserRequest($company, 'third'))
            ->assertStatus(403)
            ->assertJsonPath('feature', 'users');
    }

    // ---------------------------------------------------------------- managers limit

    /** @dataProvider managerLimits */
    public function test_manager_creation_is_rejected_at_the_plan_manager_limit(string $plan, int $limit): void
    {
        $this->fakePlan($plan);
        $company = $this->company();
        User::factory()->count($limit)->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('manager'),
        ]);
        $superadmin = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('superadmin'),
        ]);

        $resp = $this->actingAs($superadmin)->postJson(
            '/api/v1/users',
            $this->createUserRequest($company, 'newmanager', $this->roleId('manager'))
        );

        $resp->assertStatus(403)
            ->assertJsonPath('code', 'ENTITLEMENT_LIMIT_REACHED')
            ->assertJsonPath('feature', 'managers')
            ->assertJsonPath('limit', $limit);
    }

    public static function managerLimits(): array
    {
        return ['Start 1' => ['start', 1], 'Growth 1' => ['growth', 1], 'Pro 3' => ['pro', 3]];
    }

    public function test_promoting_a_user_to_manager_consumes_a_manager_slot(): void
    {
        $this->fakePlan('start'); // managers = 1
        $company = $this->company();
        $this->manager($company);
        $superadmin = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('superadmin'),
        ]);
        $nurse = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('nurse'),
        ]);

        $this->actingAs($superadmin)
            ->patchJson("/api/v1/users/{$nurse->id}", ['role_id' => $this->roleId('manager')])
            ->assertStatus(403)
            ->assertJsonPath('feature', 'managers');

        $this->assertSame($this->roleId('nurse'), (int) $nurse->fresh()->role_id);
    }

    public function test_promotion_is_allowed_when_a_manager_slot_is_free(): void
    {
        $this->fakePlan('pro'); // managers = 3
        $company = $this->company();
        $this->manager($company);
        $superadmin = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('superadmin'),
        ]);
        $nurse = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('nurse'),
        ]);

        $this->actingAs($superadmin)
            ->patchJson("/api/v1/users/{$nurse->id}", ['role_id' => $this->roleId('manager')])
            ->assertStatus(200);

        $this->assertSame(2, app(CompanyResourceUsageService::class)->currentUsage($company, 'managers'));
    }

    // ---------------------------------------------------------------- branches limit

    /** @dataProvider branchLimits */
    public function test_branch_creation_is_rejected_at_the_plan_branch_limit(string $plan, int $limit): void
    {
        $this->fakePlan($plan);
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedBranches($company, $limit);

        $resp = $this->actingAs($manager)->postJson('/api/v1/branches', $this->branchPayload('EXTRA'));

        $resp->assertStatus(403)
            ->assertJsonPath('code', 'ENTITLEMENT_LIMIT_REACHED')
            ->assertJsonPath('feature', 'branches')
            ->assertJsonPath('limit', $limit)
            ->assertJsonPath('current', $limit);
    }

    public static function branchLimits(): array
    {
        return ['Start 1' => ['start', 1], 'Growth 3' => ['growth', 3], 'Pro 10' => ['pro', 10]];
    }

    public function test_branch_creation_is_allowed_below_the_limit(): void
    {
        $this->fakePlan('growth');
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedBranches($company, 2);

        $this->actingAs($manager)->postJson('/api/v1/branches', $this->branchPayload('THIRD'))->assertStatus(201);

        $this->assertSame(3, app(CompanyResourceUsageService::class)->currentUsage($company, 'branches'));
    }

    // ---------------------------------------------------------- boolean capabilities

    public function test_boolean_capabilities_are_resolved_per_plan(): void
    {
        $capabilities = app(PlanCapabilityService::class);

        $this->fakePlan('start');
        $start = $this->company();
        $this->assertFalse($capabilities->allows($start, 'data_migration'));
        $this->assertFalse($capabilities->allows($start, 'customization'));
        $this->assertFalse($capabilities->allows($start, 'support'));

        cache()->flush();
        $this->fakePlan('pro');
        $pro = $this->company();
        $this->assertTrue($capabilities->allows($pro, 'data_migration'));
        $this->assertTrue($capabilities->allows($pro, 'customization'));
        $this->assertTrue($capabilities->allows($pro, 'support'));
    }

    public function test_capability_guard_rejects_a_feature_missing_from_the_plan(): void
    {
        $this->fakePlan('start');
        $company = $this->company();

        $this->expectException(EntitlementRequiredException::class);

        app(PlanCapabilityService::class)->authorize($company, 'data_migration');
    }

    public function test_capability_guard_error_is_structured(): void
    {
        $this->fakePlan('start');
        $company = $this->company();

        try {
            app(PlanCapabilityService::class)->authorize($company, 'data_migration');
            $this->fail('Expected EntitlementRequiredException.');
        } catch (EntitlementRequiredException $e) {
            $response = $e->render(request());
            $this->assertSame(403, $response->getStatusCode());
            $payload = $response->getData(true);
            $this->assertSame('ENTITLEMENT_REQUIRED', $payload['code']);
            $this->assertSame('data_migration', $payload['feature']);
        }
    }

    public function test_capability_guard_allows_a_feature_included_in_the_plan(): void
    {
        $this->fakePlan('growth');
        $company = $this->company();

        app(PlanCapabilityService::class)->authorize($company, 'data_migration');

        $this->assertTrue(true, 'No exception is thrown when the plan includes the capability.');
    }

    public function test_boolean_capabilities_never_change_the_global_access_state(): void
    {
        $this->fakePlan('start'); // everything false
        $company = $this->company();
        $manager = $this->manager($company);

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($company));

        // Ordinary mutations stay available - capabilities are feature-level only.
        $this->actingAs($manager)->patchJson('/api/v1/my-company', ['name' => 'Stále funguje'])->assertStatus(200);
    }

    public function test_support_capability_is_exposed_without_restricting_access(): void
    {
        $this->fakePlan('growth'); // support = true
        $company = $this->company();
        $manager = $this->manager($company);

        $this->actingAs($manager)->getJson('/api/v1/access')
            ->assertStatus(200)
            ->assertJsonPath('data.capabilities.support', true)
            ->assertJsonPath('data.state', 'full');
    }

    // ------------------------------------------------------------------- ai credits

    /** @dataProvider aiAllowances */
    public function test_ai_credits_allowance_is_available_without_any_usage_accounting(string $plan, int $allowance): void
    {
        $this->fakePlan($plan);
        $company = $this->company();

        $entitlements = app(CompanyAccessService::class)->entitlements($company);

        $this->assertSame($allowance, $entitlements->limit('ai_credits'));
        $this->assertSame('credits', $entitlements->unit('ai_credits'));

        // The allowance must never be reported as a remaining balance, and ADOCare must not
        // meter it - AI consumption belongs to a later phase.
        $usage = app(CompanyResourceUsageService::class)->usageSnapshot($company);
        $this->assertArrayNotHasKey('ai_credits', $usage);
        $this->assertNotContains('ai_credits', CompanyResourceUsageService::METERED_FEATURES);
        $this->assertSame(0, app(CompanyResourceUsageService::class)->currentUsage($company, 'ai_credits'));
    }

    public static function aiAllowances(): array
    {
        return ['Start' => ['start', 500], 'Growth' => ['growth', 2500], 'Pro' => ['pro', 10000]];
    }

    // --------------------------------------------------------------------- downgrade

    public function test_downgrade_keeps_all_data_and_only_blocks_further_growth(): void
    {
        // The company grew on Pro...
        $this->fakePlan('pro');
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedUsers($company, 15);
        User::factory()->count(2)->create(['company_id' => $company->id, 'role_id' => $this->roleId('manager')]);
        $this->seedBranches($company, 5);

        $userIds = User::where('company_id', $company->id)->pluck('id');
        $branchIds = Branch::where('company_id', $company->id)->pluck('id');

        // ... and StudioKristian now reports the активated Start plan.
        cache()->flush();
        $this->fakePlan('start');

        $usage = app(CompanyResourceUsageService::class)->usageSnapshot($company);

        $this->assertSame(15, $usage['users']['usage']);
        $this->assertSame(2, $usage['users']['limit']);
        $this->assertTrue($usage['users']['over_limit']);

        $this->assertSame(3, $usage['managers']['usage']);
        $this->assertSame(1, $usage['managers']['limit']);
        $this->assertTrue($usage['managers']['over_limit']);

        $this->assertSame(5, $usage['branches']['usage']);
        $this->assertSame(1, $usage['branches']['limit']);
        $this->assertTrue($usage['branches']['over_limit']);

        // Nothing was deleted, demoted or deactivated.
        $this->assertCount(18, User::where('company_id', $company->id)->get());
        foreach ($userIds as $id) {
            $this->assertDatabaseHas('users', ['id' => $id, 'deleted_at' => null]);
        }
        foreach ($branchIds as $id) {
            $this->assertDatabaseHas('branches', ['id' => $id]);
        }
        $this->assertSame(3, app(CompanyResourceUsageService::class)->currentUsage($company, 'managers'));

        // Growth beyond the new limits is refused.
        $this->actingAs($manager)->postJson('/api/v1/users', $this->createUserRequest($company, 'another'))
            ->assertStatus(403)->assertJsonPath('feature', 'users');
        $this->actingAs($manager)->postJson('/api/v1/branches', $this->branchPayload('ANOTHER'))
            ->assertStatus(403)->assertJsonPath('feature', 'branches');

        // Existing data is still readable.
        $this->actingAs($manager)->getJson('/api/v1/my-company/branches')->assertStatus(200);
    }

    public function test_scheduled_downgrade_does_not_apply_the_new_limits_early(): void
    {
        // Pro is active, Start is only scheduled.
        $this->fakePlan('pro', [
            'scheduled_change' => [
                'plan' => ['id' => 1, 'name' => 'Start'],
                'price' => ['id' => 10, 'amount' => 1900, 'currency' => 'EUR', 'interval' => 'monthly'],
                'effective_at' => now()->addDays(20)->toIso8601String(),
            ],
        ]);
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedUsers($company, 15);

        $usage = app(CompanyResourceUsageService::class)->usageSnapshot($company);

        $this->assertSame(25, $usage['users']['limit'], 'The scheduled plan must not apply before it activates.');
        $this->assertFalse($usage['users']['over_limit']);

        $this->actingAs($manager)
            ->postJson('/api/v1/users', $this->createUserRequest($company, 'sixteenth'))
            ->assertStatus(201);
    }

    public function test_plan_change_updates_limits_after_authoritative_refresh(): void
    {
        $this->fakePlan('growth');
        $company = $this->company();
        $this->assertSame(5, app(CompanyResourceUsageService::class)->resolveLimit($company, 'users'));

        cache()->flush();
        $this->fakePlan('pro');

        $this->assertSame(25, app(CompanyResourceUsageService::class)->resolveLimit($company, 'users'));
    }

    // ------------------------------------------------------------- access interaction

    public function test_read_only_blocks_creation_even_when_limits_allow_it(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()],
                'payments' => [],
                'invoices' => [],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response(['data' => []], 200),
        ]);

        $company = $this->company();
        $manager = $this->manager($company);

        $this->actingAs($manager)
            ->postJson('/api/v1/branches', $this->branchPayload('ANY'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'READ_ONLY');
    }

    // -------------------------------------------------------------------- usage API

    public function test_usage_endpoint_reports_all_metered_resources(): void
    {
        $this->fakePlan('growth');
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedUsers($company, 4);
        $this->seedBranches($company, 2);

        $this->actingAs($manager)->getJson('/api/v1/access')
            ->assertStatus(200)
            ->assertJsonPath('data.usage.users.usage', 4)
            ->assertJsonPath('data.usage.users.limit', 5)
            ->assertJsonPath('data.usage.users.over_limit', false)
            ->assertJsonPath('data.usage.managers.usage', 1)
            ->assertJsonPath('data.usage.managers.limit', 1)
            ->assertJsonPath('data.usage.managers.can_add_more', false)
            ->assertJsonPath('data.usage.branches.usage', 2)
            ->assertJsonPath('data.usage.branches.limit', 3)
            ->assertJsonPath('data.entitlements.ai_credits.value', 2500)
            ->assertJsonPath('data.capabilities.data_migration', true)
            ->assertJsonPath('data.capabilities.customization', false)
            ->assertJsonMissingPath('data.usage.ai_credits');
    }

    // ---------------------------------------------------------------------- security

    public function test_usage_and_limits_are_scoped_to_the_authenticated_company(): void
    {
        $this->fakePlan('start');
        $companyA = $this->company();
        $managerA = $this->manager($companyA);
        $this->seedUsers($companyA, 2);

        $companyB = $this->company();
        $this->seedUsers($companyB, 1);

        // A client-supplied company_id must not place the account in another Company, so the
        // request is charged against - and refused by - Company A's own exhausted allowance.
        $this->actingAs($managerA)
            ->postJson('/api/v1/users', $this->createUserRequest($companyB, 'foreign'))
            ->assertStatus(403)
            ->assertJsonPath('feature', 'users');

        $this->assertSame(1, app(CompanyResourceUsageService::class)->currentUsage($companyB, 'users'));
    }

    public function test_browser_cannot_raise_its_own_limit(): void
    {
        $this->fakePlan('start');
        $company = $this->company();
        $manager = $this->manager($company);
        $this->seedUsers($company, 2);

        // Client-supplied plan/limit/usage fields are ignored - entitlements come from billing.
        $this->actingAs($manager)->postJson('/api/v1/users', array_merge(
            $this->createUserRequest($company, 'sneaky'),
            ['users' => 99, 'limit' => 99, 'plan' => 'pro', 'entitlements' => ['users' => ['type' => 'unlimited']]],
        ))->assertStatus(403)->assertJsonPath('limit', 2);
    }

    // ------------------------------------------------------------------- concurrency

    public function test_limit_check_and_creation_share_one_locked_transaction(): void
    {
        $this->fakePlan('start'); // users = 2
        $this->fakePlan('start');
        $company = $this->company();
        $this->seedUsers($company, 1);

        $usage = app(CompanyResourceUsageService::class);

        // Two sequential attempts through the guarded path: the first fills the last seat,
        // the second is refused - the check and insert are never separated by other work.
        $usage->createWithinLimit($company, 'users', fn () => User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $this->roleId('nurse'),
        ]));

        $this->assertSame(2, $usage->currentUsage($company, 'users'));

        try {
            $usage->createWithinLimit($company, 'users', fn () => User::factory()->create([
                'company_id' => $company->id,
                'role_id' => $this->roleId('nurse'),
            ]));
            $this->fail('The third user must not be created.');
        } catch (\App\Exceptions\EntitlementLimitException $e) {
            $this->assertSame('users', $e->feature);
        }

        $this->assertSame(2, $usage->currentUsage($company, 'users'));
    }
}
