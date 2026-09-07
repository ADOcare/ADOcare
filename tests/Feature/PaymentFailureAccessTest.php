<?php

namespace Tests\Feature;

use App\Enums\AccessState;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 3A.2 - failed payments, past_due, grace period and payment recovery.
 *
 * StudioKristian owns every billing fact here (status, payment_status, payment_failed_at,
 * grace_period_ends_at). ADOCare only translates them into application access and never
 * recomputes the grace window from a hardcoded number of days.
 */
class PaymentFailureAccessTest extends TestCase
{
    use RefreshDatabase;

    private function configureBilling(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);
        config(['app.url' => 'https://adocare.test']);
    }

    private function ensureManagerRole(): int
    {
        if (!Role::where('position', 'manager')->exists()) {
            Role::create(['position' => 'manager', 'scope' => 'company']);
        }

        return (int) Role::where('position', 'manager')->value('id');
    }

    private function fakeBilling(array $subscriptions = [], ?array $trial = null, array $extra = []): void
    {
        $this->configureBilling();

        Http::fake(array_merge([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => $subscriptions,
                'trial' => $trial,
                'payments' => [],
                'invoices' => [],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response(['data' => $trial ?? []], 200),
        ], $extra));
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

    private function subscription(array $overrides = []): array
    {
        return array_merge([
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
            'entitlements' => ['users' => ['type' => 'limit', 'value' => 10]],
        ], $overrides);
    }

    private function pastDue(?string $graceEndsAt): array
    {
        return $this->subscription([
            'status' => 'past_due',
            'payment_status' => 'failed',
            'payment_failed_at' => now()->subDays(2)->toIso8601String(),
            'grace_period_ends_at' => $graceEndsAt,
            'payment_action_required' => true,
        ]);
    }

    // ------------------------------------------------------------- access resolution

    public function test_active_subscription_is_full_access(): void
    {
        $this->fakeBilling([$this->subscription()]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::FULL, $access['state']);
        $this->assertNull($access['reason']);
    }

    public function test_past_due_during_grace_keeps_full_access(): void
    {
        $this->fakeBilling([$this->pastDue(now()->addDays(5)->toIso8601String())]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::FULL, $access['state']);
        $this->assertSame('past_due', $access['billing_state']);
        $this->assertSame('payment_failed_grace_period', $access['reason']);
        $this->assertTrue(app(CompanyAccessService::class)->allowsMutations($company));
    }

    public function test_past_due_after_grace_becomes_read_only(): void
    {
        $this->fakeBilling([$this->pastDue(now()->subMinute()->toIso8601String())]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::READ_ONLY, $access['state']);
        $this->assertSame('payment_failed_grace_expired', $access['reason']);
    }

    public function test_past_due_exactly_at_grace_end_becomes_read_only(): void
    {
        $graceEndsAt = now()->addDays(7)->startOfSecond();
        $this->fakeBilling([$this->pastDue($graceEndsAt->toIso8601String())]);
        $company = $this->companyWithBilling();

        // Grace is inclusive of "now < end" only - at the exact boundary it has expired.
        $this->travelTo($graceEndsAt);

        $this->assertSame(AccessState::READ_ONLY, app(CompanyAccessService::class)->state($company));

        $this->travelBack();
    }

    public function test_past_due_grace_expiry_is_evaluated_against_server_time(): void
    {
        $graceEndsAt = now()->addDays(3)->startOfSecond();
        $this->fakeBilling([$this->pastDue($graceEndsAt->toIso8601String())]);
        $company = $this->companyWithBilling();

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($company));

        $this->travelTo($graceEndsAt->copy()->addSecond());
        cache()->flush();

        $this->assertSame(AccessState::READ_ONLY, app(CompanyAccessService::class)->state($company));

        $this->travelBack();
    }

    /**
     * StudioKristian only omits grace_period_ends_at when no payment failure is recorded, so
     * there is no evidence the customer ran out of time - documented fail-open behavior.
     */
    public function test_past_due_without_grace_period_end_keeps_full_access(): void
    {
        $this->fakeBilling([$this->pastDue(null)]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::FULL, $access['state']);
        $this->assertSame('payment_failed_grace_period', $access['reason']);
    }

    public function test_past_due_keeps_the_plan_entitlements(): void
    {
        $this->fakeBilling([$this->pastDue(now()->addDays(2)->toIso8601String())]);
        $company = $this->companyWithBilling();

        $this->assertSame(10, app(CompanyAccessService::class)->entitlements($company)->limit('users'));
    }

    public function test_recovered_payment_returns_to_full_access(): void
    {
        $recovered = false;
        $this->configureBilling();
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => function () use (&$recovered) {
                return Http::response([
                    'subscriptions' => [$recovered ? $this->subscription() : $this->pastDue(now()->subDay()->toIso8601String())],
                    'trial' => null,
                    'payments' => [],
                    'invoices' => [],
                ], 200);
            },
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response(['data' => []], 200),
        ]);

        $company = $this->companyWithBilling();

        $this->assertSame(AccessState::READ_ONLY, app(CompanyAccessService::class)->state($company));

        // StudioKristian reports the recovery; ADOCare simply reads it again.
        $recovered = true;
        cache()->flush();

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($company->fresh()));
    }

    public function test_past_due_does_not_cancel_a_scheduled_cancellation(): void
    {
        $subscription = $this->pastDue(now()->addDays(4)->toIso8601String());
        $subscription['cancel_at_period_end'] = true;
        $this->fakeBilling([$subscription]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::FULL, $access['state']);
        $this->assertTrue($access['current']['subscription']['cancel_at_period_end']);
    }

    public function test_past_due_paid_subscription_outranks_an_active_trial(): void
    {
        $this->fakeBilling(
            [$this->pastDue(now()->subDay()->toIso8601String())],
            ['status' => 'active', 'ends_at' => now()->addDays(9)->toIso8601String()],
        );
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        // The paid relationship stays authoritative - the trial must not paper over it.
        $this->assertSame(AccessState::READ_ONLY, $access['state']);
        $this->assertSame('payment_failed_grace_expired', $access['reason']);
    }

    public function test_expired_trial_still_resolves_to_read_only(): void
    {
        $this->fakeBilling([], ['status' => 'expired', 'ends_at' => now()->subDay()->toIso8601String()]);
        $company = $this->companyWithBilling();

        $access = app(CompanyAccessService::class)->resolve($company);

        $this->assertSame(AccessState::READ_ONLY, $access['state']);
        $this->assertSame('trial_expired', $access['reason']);
    }

    public function test_active_trial_still_resolves_to_full(): void
    {
        $this->fakeBilling([], ['status' => 'active', 'ends_at' => now()->addDays(5)->toIso8601String()]);
        $company = $this->companyWithBilling();

        $this->assertSame(AccessState::FULL, app(CompanyAccessService::class)->state($company));
    }

    // ------------------------------------------------------------- enforcement / API

    public function test_past_due_within_grace_allows_mutations_and_billing(): void
    {
        $this->fakeBilling([$this->pastDue(now()->addDays(5)->toIso8601String())]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        $this->actingAs($manager)->getJson('/api/v1/my-company')->assertStatus(200);
        $this->actingAs($manager)->patchJson('/api/v1/my-company', ['name' => 'Stále funguje'])->assertStatus(200);
        $this->actingAs($manager)->getJson('/api/v1/billing/subscription')->assertStatus(200);
    }

    public function test_past_due_after_grace_blocks_mutations_but_keeps_reads_and_billing(): void
    {
        $this->fakeBilling([$this->pastDue(now()->subDay()->toIso8601String())]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        $this->actingAs($manager)->getJson('/api/v1/my-company')->assertStatus(200);

        $this->actingAs($manager)
            ->patchJson('/api/v1/my-company', ['name' => 'Nedovolené'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'READ_ONLY')
            ->assertJsonPath('reason', 'payment_failed_grace_expired');

        // Recovery paths stay open.
        $this->actingAs($manager)->getJson('/api/v1/billing/subscription')
            ->assertStatus(200)
            ->assertJsonPath('data.access.state', 'read_only');
    }

    public function test_access_endpoint_exposes_payment_context_for_the_warning(): void
    {
        $graceEndsAt = now()->addDays(5)->startOfSecond();
        $this->fakeBilling([$this->pastDue($graceEndsAt->toIso8601String())]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        $this->actingAs($manager)->getJson('/api/v1/access')
            ->assertStatus(200)
            ->assertJsonPath('data.state', 'full')
            ->assertJsonPath('data.reason', 'payment_failed_grace_period')
            ->assertJsonPath('data.payment.status', 'past_due')
            ->assertJsonPath('data.payment.payment_status', 'failed')
            ->assertJsonPath('data.payment.payment_action_required', true)
            ->assertJsonPath('data.payment.grace_period_ends_at', $graceEndsAt->toIso8601String())
            ->assertJsonStructure(['data' => ['payment' => ['payment_failed_at', 'current_period_end', 'server_time']]]);
    }

    public function test_billing_page_read_reflects_recovery_without_waiting_for_the_cache(): void
    {
        $recovered = false;
        $this->configureBilling();
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => function () use (&$recovered) {
                return Http::response([
                    'subscriptions' => [$recovered ? $this->subscription() : $this->pastDue(now()->subDay()->toIso8601String())],
                    'trial' => null,
                    'payments' => [],
                    'invoices' => [],
                ], 200);
            },
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response(['data' => []], 200),
        ]);

        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        // Cache says past_due with an expired grace window...
        $this->assertSame(AccessState::READ_ONLY, app(CompanyAccessService::class)->state($company));

        // ... but StudioKristian now reports the payment recovered. The billing page read is
        // authoritative and primes the cache, so access flips without waiting out the TTL.
        $recovered = true;

        $this->actingAs($manager)->getJson('/api/v1/billing/subscription')
            ->assertStatus(200)
            ->assertJsonPath('data.access.state', 'full');
    }

    // ------------------------------------------------------------- payment method portal

    public function test_company_can_open_the_payment_method_portal(): void
    {
        $this->fakeBilling([$this->pastDue(now()->addDay()->toIso8601String())], null, [
            'billing.studiokristian.test/api/v1/billing/customer/payment-method' => Http::response([
                'url' => 'https://billing.stripe.com/p/session/test_123',
            ], 200),
        ]);
        $company = $this->companyWithBilling();
        $manager = $this->managerFor($company);

        $resp = $this->actingAs($manager)->postJson('/api/v1/billing/payment-method', []);

        $resp->assertStatus(200)
            ->assertJsonPath('data.portal_url', 'https://billing.stripe.com/p/session/test_123');

        Http::assertSent(function ($request) use ($company) {
            if (!str_contains($request->url(), '/customer/payment-method')) {
                return false;
            }

            return $request['return_url'] === 'https://adocare.test/billing'
                && $request->hasHeader('X-Billing-Customer-Token', $company->studiokristian_customer_token);
        });
    }

    public function test_return_url_is_built_from_the_application_url_not_the_browser(): void
    {
        $this->fakeBilling([$this->subscription()], null, [
            'billing.studiokristian.test/api/v1/billing/customer/payment-method' => Http::response([
                'url' => 'https://billing.stripe.com/p/session/test_123',
            ], 200),
        ]);
        $manager = $this->managerFor($this->companyWithBilling());

        $this->actingAs($manager)->postJson('/api/v1/billing/payment-method', [
            'return_url' => 'https://evil.example/steal',
            'return_path' => '/settings/subscriptions',
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/customer/payment-method')) {
                return false;
            }

            return $request['return_url'] === 'https://adocare.test/settings/subscriptions';
        });
    }

    public function test_absolute_or_traversing_return_paths_are_rejected(): void
    {
        $this->fakeBilling([$this->subscription()]);
        $manager = $this->managerFor($this->companyWithBilling());

        // Absolute URLs, protocol-relative URLs and non-rooted paths are all refused before
        // anything is sent upstream.
        foreach (['https://evil.example/steal', '//evil.example', 'settings/billing'] as $path) {
            $this->actingAs($manager)
                ->postJson('/api/v1/billing/payment-method', ['return_path' => $path])
                ->assertStatus(422);
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/customer/payment-method'));
    }

    public function test_payment_method_portal_is_scoped_to_the_authenticated_company(): void
    {
        $this->fakeBilling([$this->subscription()], null, [
            'billing.studiokristian.test/api/v1/billing/customer/payment-method' => Http::response([
                'url' => 'https://billing.stripe.com/p/session/test_123',
            ], 200),
        ]);

        $companyA = $this->companyWithBilling(['studiokristian_customer_token' => 'token-a']);
        $companyB = $this->companyWithBilling(['studiokristian_customer_token' => 'token-b']);
        $managerA = $this->managerFor($companyA);

        // Even when company B's id is supplied by the client, A's own credential is used.
        $this->actingAs($managerA)->postJson('/api/v1/billing/payment-method', [
            'company_id' => $companyB->id,
        ])->assertStatus(200);

        Http::assertSent(fn ($request) => !str_contains($request->url(), '/customer/payment-method')
            || $request->hasHeader('X-Billing-Customer-Token', 'token-a'));
    }

    public function test_payment_method_portal_requires_provisioned_billing(): void
    {
        $this->fakeBilling([]);
        $company = $this->companyWithBilling(['studiokristian_customer_token' => null]);
        $manager = $this->managerFor($company);

        $this->actingAs($manager)->postJson('/api/v1/billing/payment-method', [])->assertStatus(422);
    }

    public function test_studiokristian_errors_are_surfaced_cleanly_without_leaking_secrets(): void
    {
        $this->fakeBilling([$this->subscription()], null, [
            'billing.studiokristian.test/api/v1/billing/customer/payment-method' => Http::response([
                'message' => 'No billing customer is available.',
            ], 422),
        ]);
        $manager = $this->managerFor($this->companyWithBilling());

        $resp = $this->actingAs($manager)->postJson('/api/v1/billing/payment-method', []);

        $resp->assertStatus(422);
        $body = $resp->getContent();
        $this->assertStringNotContainsString('project-token', $body);
        $this->assertStringNotContainsString('customer-token', $body);
        $this->assertStringNotContainsString('cus_', $body);
    }

    public function test_payment_method_portal_stays_available_while_read_only(): void
    {
        $this->fakeBilling([$this->pastDue(now()->subDay()->toIso8601String())], null, [
            'billing.studiokristian.test/api/v1/billing/customer/payment-method' => Http::response([
                'url' => 'https://billing.stripe.com/p/session/test_123',
            ], 200),
        ]);
        $manager = $this->managerFor($this->companyWithBilling());

        $this->actingAs($manager)->postJson('/api/v1/billing/payment-method', [])->assertStatus(200);
    }
}
