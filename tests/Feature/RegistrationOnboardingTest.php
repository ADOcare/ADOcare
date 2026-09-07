<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function ensureManagerRole(): void
    {
        if (!Role::where('position', 'manager')->exists()) {
            Role::create(['position' => 'manager', 'scope' => 'company']);
        }
    }

    public function test_new_user_can_register_and_creates_company_and_manager(): void
    {
        $this->ensureManagerRole();
        Http::fake();

        $resp = $this->postJson('/api/auth/register-company', [
            'first_name' => 'Jana',
            'last_name' => 'Nová',
            'email' => 'jana@example.com',
            'pin' => '1234',
            'pin_confirmation' => '1234',
            'company_name' => 'Nová Firma s.r.o.',
        ]);

        $resp->assertStatus(201);
        $this->assertNotEmpty($resp->json('data.token'));

        $user = User::where('email', 'jana@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->company_id);

        $company = Company::find($user->company_id);
        $this->assertEquals('Nová Firma s.r.o.', $company->name);
        $this->assertEquals('onboarding', $company->status);
        $this->assertEquals($user->id, $company->representative_id);
        $this->assertTrue($user->hasGlobalRole('manager'));
    }

    public function test_registration_provisions_the_billing_customer_exactly_once_with_correct_context(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $this->ensureManagerRole();

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer-credentials' => Http::response([
                'data' => ['id' => 8, 'token' => 'brand-new-customer-token', 'already_provisioned' => false],
            ], 201),
        ]);

        $resp = $this->postJson('/api/auth/register-company', [
            'first_name' => 'Jana',
            'last_name' => 'Nová',
            'email' => 'jana3@example.com',
            'pin' => '1234',
            'pin_confirmation' => '1234',
            'company_name' => 'Provisioned Firma s.r.o.',
        ]);

        $resp->assertStatus(201);

        $company = User::where('email', 'jana3@example.com')->first()->company;

        $this->assertTrue($company->hasBillingCustomerToken());

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($company) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/v1/billing/customer-credentials')
                && $request->hasHeader('Authorization', 'Bearer project-token')
                // The Company context sent to StudioKristian is ADOCare's own id - never an
                // arbitrary/foreign value and never a Stripe or project id.
                && $request['external_reference'] === (string) $company->id
                // The technical credential label is NOT the Company's legal billing identity -
                // that travels separately as billing_profile.name.
                && $request['name'] === "Company {$company->id} billing session"
                && $request['billing_profile']['name'] === $company->name;
        });
    }

    public function test_onboarding_provision_billing_endpoint_is_idempotent(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'onboarding',
            'studiokristian_customer_token' => 'already-have-a-token',
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        Http::fake();

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/billing/provision');

        $resp->assertStatus(200);
        $this->assertTrue($resp->json('data.billing_provisioned'));
        // Already provisioned - must not call StudioKristian again (no duplicate credential/customer).
        Http::assertNothingSent();
    }

    public function test_onboarding_provision_billing_surfaces_real_studiokristian_error(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'onboarding',
            'studiokristian_customer_token' => null,
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer-credentials' => Http::response([], 403),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/billing/provision');

        // The real upstream status must be surfaced, not a generic hardcoded 502.
        $resp->assertStatus(403);
        $this->assertFalse($company->fresh()->hasBillingCustomerToken());
    }

    /**
     * @param int $trialPrechecks how many "not active yet" precheck responses to queue before
     *                            the eventual "active" response (1 per onboarding submission).
     */
    private function fakeProvisionAndTrial(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        // Starting a trial calls StudioKristian's own idempotent POST directly - no
        // "does a trial already exist" precheck GET, no subscriptions lookup.
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer-credentials' => Http::response([
                'data' => ['id' => 8, 'token' => 'brand-new-customer-token', 'already_provisioned' => false],
            ], 201),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([
                'data' => ['active' => true, 'ends_at' => now()->addDays(14)->toDateString()],
            ], 200),
        ]);
    }

    public function test_onboarding_company_form_saves_only_required_fields_and_activates_trial(): void
    {
        $this->fakeProvisionAndTrial();
        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding', 'studiokristian_customer_token' => null]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/company', [
            'name' => 'Ordinácia s.r.o.',
            'ico' => '12345678',
            'dic' => '2023456789',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'psc' => '81101',
        ]);

        $resp->assertStatus(200);
        $this->assertTrue($resp->json('data.trial.active'));

        $company->refresh();
        $this->assertEquals('Ordinácia s.r.o.', $company->name);
        $this->assertEquals('12345678', $company->ico);
        $this->assertEquals('2023456789', $company->dic);
        $this->assertNull($company->ic_dph);
        $this->assertTrue($company->hasBillingCustomerToken());
        $this->assertEquals('trial', $company->subscription_status);
    }

    public function test_onboarding_company_form_sends_real_billing_profile_not_credential_label(): void
    {
        $this->fakeProvisionAndTrial();
        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding', 'studiokristian_customer_token' => null]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $this->actingAs($manager)->postJson('/api/v1/onboarding/company', [
            'name' => 'Ordinácia s.r.o.',
            'ico' => '12345678',
            'dic' => '2023456789',
            'ic_dph' => 'SK2023456789',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'psc' => '81101',
        ])->assertStatus(200);

        Http::assertSent(function ($request) use ($company) {
            if (!str_contains($request->url(), '/api/v1/billing/customer-credentials')) {
                return true;
            }

            // The technical credential label must never be confused with the Company's
            // real legal/billing identity - that only travels via billing_profile.
            return $request['name'] === "Company {$company->id} billing session"
                && $request['billing_profile']['name'] === 'Ordinácia s.r.o.'
                && $request['billing_profile']['ico'] === '12345678'
                && $request['billing_profile']['dic'] === '2023456789'
                && $request['billing_profile']['ic_dph'] === 'SK2023456789'
                && $request['billing_profile']['address']['line1'] === 'Hlavná 1'
                && $request['billing_profile']['address']['city'] === 'Bratislava'
                && $request['billing_profile']['address']['postal_code'] === '81101';
        });
    }

    public function test_onboarding_repeat_submission_repairs_already_provisioned_billing_profile(): void
    {
        $this->fakeProvisionAndTrial();
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer-credentials' => Http::response([
                'data' => ['id' => 8, 'name' => 'Company 55 billing session', 'already_provisioned' => true],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([
                'data' => ['status' => 'active', 'ends_at' => now()->addDays(14)->toIso8601String()],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/profile' => Http::response(['data' => ['updated' => true]], 200),
        ]);
        $this->ensureManagerRole();
        // Already provisioned before this session - mirrors the real "Company 29 billing
        // session" repair scenario: the credential exists, the profile needs fixing.
        $company = Company::factory()->create([
            'status' => 'onboarding',
            'studiokristian_customer_token' => 'already-have-a-token',
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $this->actingAs($manager)->postJson('/api/v1/onboarding/company', [
            'name' => 'Real Legal Name s.r.o.',
            'ico' => '12345678',
            'dic' => '2023456789',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'psc' => '81101',
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/api/v1/billing/customer/profile')
                && $request->hasHeader('X-Billing-Customer-Token', 'already-have-a-token')
                && $request['name'] === 'Real Legal Name s.r.o.';
        });
    }

    public function test_onboarding_company_form_accepts_optional_ic_dph(): void
    {
        $this->fakeProvisionAndTrial();
        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding', 'studiokristian_customer_token' => null]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/company', [
            'name' => 'Ordinácia s.r.o.',
            'ico' => '12345678',
            'dic' => '2023456789',
            'ic_dph' => 'SK2023456789',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'psc' => '81101',
        ]);

        $resp->assertStatus(200);
        $this->assertEquals('SK2023456789', $company->fresh()->ic_dph);
    }

    public function test_onboarding_company_form_requires_the_mandatory_fields(): void
    {
        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding']);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/company', [
            'name' => 'Ordinácia s.r.o.',
            // ico/dic/address/city/psc missing
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['ico', 'dic', 'address', 'city', 'psc']);
    }

    public function test_onboarding_company_form_redirects_to_dashboard_flow_does_not_restart_trial_on_repeat(): void
    {
        $this->fakeProvisionAndTrial();
        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding', 'studiokristian_customer_token' => null]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $payload = [
            'name' => 'Ordinácia s.r.o.',
            'ico' => '12345678',
            'dic' => '2023456789',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'psc' => '81101',
        ];

        $this->actingAs($manager)->postJson('/api/v1/onboarding/company', $payload)->assertStatus(200);
        $this->actingAs($manager)->postJson('/api/v1/onboarding/company', $payload)->assertStatus(200);

        // Provisioning and the actual trial-start POST must each happen exactly once across
        // both submissions, however many read-only precheck calls happen in between.
        $recorded = collect(Http::recorded());
        $provisionCalls = $recorded->filter(fn ($pair) => $pair[0]->method() === 'POST'
            && str_contains($pair[0]->url(), '/api/v1/billing/customer-credentials'));
        $trialStartCalls = $recorded->filter(fn ($pair) => $pair[0]->method() === 'POST'
            && str_contains($pair[0]->url(), '/api/v1/billing/customer/trial'));

        $this->assertCount(1, $provisionCalls);
        $this->assertCount(1, $trialStartCalls);
    }

    public function test_saving_company_settings_syncs_billing_profile_when_already_provisioned(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/profile' => Http::response(['data' => ['updated' => true]], 200),
        ]);

        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'active',
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->addDays(5),
            'studiokristian_customer_token' => 'existing-customer-token',
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $this->actingAs($manager)->patchJson('/api/v1/my-company', [
            'name' => 'Updated Legal Name s.r.o.',
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/api/v1/billing/customer/profile')
                && $request->hasHeader('X-Billing-Customer-Token', 'existing-customer-token')
                && $request['name'] === 'Updated Legal Name s.r.o.';
        });
    }

    public function test_saving_company_settings_does_not_call_studiokristian_when_not_yet_provisioned(): void
    {
        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'active',
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->addDays(5),
            'studiokristian_customer_token' => null,
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        Http::fake();

        $this->actingAs($manager)->patchJson('/api/v1/my-company', [
            'name' => 'Some Name s.r.o.',
        ])->assertStatus(200);

        Http::assertNothingSent();
    }

    public function test_onboarding_company_form_does_not_block_on_provisioning_outage(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer-credentials' => Http::response([], 500),
        ]);

        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding', 'studiokristian_customer_token' => null]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/company', [
            'name' => 'Ordinácia s.r.o.',
            'ico' => '12345678',
            'dic' => '2023456789',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'psc' => '81101',
        ]);

        // A billing outage must never block the user from finishing onboarding/registration.
        $resp->assertStatus(200);
        $this->assertNotEmpty($resp->json('data.billing_error'));

        $company->refresh();
        // The Company details are saved (no need to re-type them on retry)...
        $this->assertEquals('Ordinácia s.r.o.', $company->name);
        // ...but billing/trial must not be falsely marked as active.
        $this->assertFalse($company->hasBillingCustomerToken());
        $this->assertNotEquals('trial', $company->subscription_status);
    }

    public function test_onboarding_company_form_does_not_block_on_trial_start_outage(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer-credentials' => Http::response([
                'data' => ['id' => 8, 'token' => 'brand-new-customer-token', 'already_provisioned' => false],
            ], 201),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([], 500),
        ]);

        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding', 'studiokristian_customer_token' => null]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/company', [
            'name' => 'Ordinácia s.r.o.',
            'ico' => '12345678',
            'dic' => '2023456789',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'psc' => '81101',
        ]);

        // A trial-start outage must never block the user from finishing onboarding/registration.
        $resp->assertStatus(200);
        $this->assertNotEmpty($resp->json('data.billing_error'));

        $company->refresh();
        // Billing was provisioned successfully...
        $this->assertTrue($company->hasBillingCustomerToken());
        // ...but the trial must not be marked active since StudioKristian never confirmed it.
        $this->assertNotEquals('trial', $company->subscription_status);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->ensureManagerRole();
        User::factory()->create(['email' => 'dupe@example.com']);

        $resp = $this->postJson('/api/auth/register-company', [
            'first_name' => 'Jana',
            'last_name' => 'Nová',
            'email' => 'dupe@example.com',
            'pin' => '1234',
            'pin_confirmation' => '1234',
            'company_name' => 'Nová Firma s.r.o.',
        ]);

        $resp->assertStatus(422);
        $this->assertEquals(0, Company::count());
    }

    public function test_registration_is_transactional_when_manager_role_missing(): void
    {
        // No manager role seeded - registration must fail without leaving an orphaned Company/User.
        $resp = $this->postJson('/api/auth/register-company', [
            'first_name' => 'Jana',
            'last_name' => 'Nová',
            'email' => 'jana2@example.com',
            'pin' => '1234',
            'pin_confirmation' => '1234',
            'company_name' => 'Nová Firma s.r.o.',
        ]);

        $resp->assertStatus(422);
        $this->assertEquals(0, Company::count());
        $this->assertEquals(0, User::where('email', 'jana2@example.com')->count());
    }

    public function test_onboarding_status_reflects_real_company_state(): void
    {
        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding']);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->getJson('/api/v1/onboarding/status');

        $resp->assertStatus(200);
        $this->assertEquals('onboarding', $resp->json('data.status'));
        $this->assertFalse($resp->json('data.complete'));

        $slugs = collect($resp->json('data.steps'))->pluck('slug');
        $this->assertTrue($slugs->contains('company'));
        $this->assertTrue($slugs->contains('billing'));
        $this->assertTrue($slugs->contains('branch'));
    }

    public function test_existing_company_without_status_is_treated_as_active(): void
    {
        $company = Company::factory()->create();
        // Simulate a pre-existing row created before this migration (raw null status).
        \DB::table('company')->where('id', $company->id)->update(['status' => null]);

        $this->assertEquals('active', $company->fresh()->status);
    }

    public function test_start_trial_uses_studiokristian_not_stripe(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'onboarding',
            'studiokristian_customer_token' => 'customer-token',
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        // Starting a trial calls StudioKristian's own idempotent POST directly - no
        // precheck GET, no subscriptions lookup, exactly one outgoing request.
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([
                'data' => ['active' => true, 'ends_at' => now()->addDays(14)->toDateString()],
            ], 200),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/billing/start-trial', []);

        $resp->assertStatus(200);
        $this->assertTrue($resp->json('data.active'));

        $company->refresh();
        $this->assertEquals('trial', $company->subscription_status);
        $this->assertNotNull($company->subscription_ends_at);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/billing/customer/trial')
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer project-token')
                && $request->hasHeader('X-Billing-Customer-Token', 'customer-token')
                && empty(json_decode($request->body() ?: '{}', true));
        });
    }

    public function test_start_trial_requires_billing_customer_token_first(): void
    {
        Http::fake();

        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'onboarding',
            'subscription_status' => null,
            'studiokristian_customer_token' => null,
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/billing/start-trial', []);

        $resp->assertStatus(422);
        Http::assertNothingSent();
        $this->assertNull($company->fresh()->subscription_status);
    }

    public function test_start_trial_billing_outage_does_not_fake_success(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'onboarding',
            'studiokristian_customer_token' => 'customer-token',
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        // Explicitly mocked upstream failure - not a real/accidental DNS resolution failure.
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([], 500),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/billing/start-trial', []);

        $resp->assertStatus(500);
        $this->assertNull($company->fresh()->subscription_status);
        Http::assertSentCount(1);
    }

    public function test_start_trial_connection_failure_returns_clear_error_not_a_hang(): void
    {
        // A DNS/connection failure must become a clear, fast error - not an uncaught
        // exception and not a request that hangs for the full request timeout.
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'onboarding',
            'studiokristian_customer_token' => 'customer-token',
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host.');
        });

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/billing/start-trial', []);

        $resp->assertStatus(503);
        $this->assertNull($company->fresh()->subscription_status);
    }

    public function test_onboarding_cannot_complete_with_missing_steps(): void
    {
        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'onboarding']);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->postJson('/api/v1/onboarding/complete');

        $resp->assertStatus(422);
        $this->assertEquals('onboarding', $company->fresh()->status);
    }

    public function test_unauthorized_user_cannot_modify_another_companys_data(): void
    {
        $this->ensureManagerRole();
        $ownCompany = Company::factory()->create(['subscription_status' => 'trial']);
        $otherCompany = Company::factory()->create(['name' => 'Other Co']);
        $manager = User::factory()->create([
            'company_id' => $ownCompany->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        // Managers only ever operate on their own company via v1/my-company - there is no
        // endpoint that accepts an arbitrary company_id from the browser for this purpose.
        $resp = $this->actingAs($manager)->patchJson('/api/v1/my-company', [
            'name' => 'Hijacked name',
        ]);

        $resp->assertStatus(200);
        $this->assertEquals($ownCompany->id, $manager->fresh()->company_id);
        $this->assertEquals('Other Co', $otherCompany->fresh()->name);
    }

    public function test_my_company_does_not_return_402_during_onboarding(): void
    {
        $this->ensureManagerRole();
        // Mirrors what RegistrationService actually produces: onboarding status, no
        // trial/subscription yet - this used to 402 before the fix.
        $company = Company::factory()->create(['status' => 'onboarding', 'subscription_status' => null]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->getJson('/api/v1/my-company');

        $resp->assertStatus(200);
    }

    public function test_active_trial_company_can_access_protected_routes(): void
    {
        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'active',
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->addDays(5),
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->getJson('/api/v1/my-company');

        $resp->assertStatus(200);
    }

    public function test_active_paid_subscription_company_can_access_protected_routes(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [['id' => 1, 'status' => 'active']],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([
                'data' => ['active' => false],
            ], 200),
        ]);

        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'active',
            'subscription_status' => null,
            'studiokristian_customer_token' => 'customer-token',
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->getJson('/api/v1/my-company');

        $resp->assertStatus(200);
    }

    /**
     * Phase 2 deliberately softened this case: an expired trial used to hard-block every
     * request (402). It is now READ_ONLY - the company keeps access to its own data and only
     * loses the ability to mutate it.
     */
    public function test_expired_trial_without_paid_subscription_becomes_read_only(): void
    {
        $this->ensureManagerRole();
        $company = Company::factory()->create([
            'status' => 'active',
            'subscription_status' => 'trial',
            'subscription_ends_at' => now()->subDay(),
        ]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $this->actingAs($manager)->getJson('/api/v1/my-company')->assertStatus(200);

        $this->actingAs($manager)
            ->patchJson('/api/v1/my-company', ['name' => 'Nový názov'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'READ_ONLY')
            ->assertJsonPath('access_state', 'read_only');
    }

    public function test_existing_active_company_without_billing_state_is_still_restricted(): void
    {
        // Guards against onboarding-only bypass leaking into normal enforcement.
        $this->ensureManagerRole();
        $company = Company::factory()->create(['status' => 'active', 'subscription_status' => null]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'role_id' => Role::where('position', 'manager')->value('id'),
        ]);

        $resp = $this->actingAs($manager)->getJson('/api/v1/my-company');

        $resp->assertStatus(402);
    }
}
