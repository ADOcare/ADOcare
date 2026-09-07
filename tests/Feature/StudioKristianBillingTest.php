<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioKristianBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_are_retrieved_from_studiokristian(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Professional', 'prices' => [['id' => 20, 'amount' => 19, 'currency' => 'EUR', 'interval' => 'month']]],
                ],
            ], 200),
        ]);

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/plans');

        $resp->assertStatus(200);
        $this->assertEquals('Professional', $resp->json('data.0.name'));

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer project-token')
                && str_contains($request->url(), '/api/v1/billing/plans');
        });
    }

    public function test_active_studiokristian_trial_is_not_reported_as_expired(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        // Real StudioKristian BillingTrialResource shape: `status` is 'active'/'expired'/
        // 'converted', not an `active` boolean and not 'trialing'. Trial is bundled inside
        // the /customer/subscriptions response, not fetched via a separate /customer/trial call.
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => [
                    'status' => 'active',
                    'started_at' => now()->toIso8601String(),
                    'ends_at' => now()->addDays(14)->toIso8601String(),
                    'credit_allowance' => 100,
                    'credits_used' => 0,
                    'credits_remaining' => 100,
                ],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertTrue($resp->json('data.trial.active'));
        $this->assertFalse($resp->json('data.trial.expired'));
        $this->assertEquals(100, $resp->json('data.trial.credits'));
    }

    public function test_active_trial_with_no_paid_subscription_is_the_current_state(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => ['status' => 'active', 'ends_at' => now()->addDays(10)->toIso8601String()],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertEquals('trial', $resp->json('data.current.type'));
    }

    public function test_active_paid_subscription_takes_precedence_over_active_trial(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        // Real StudioKristian shape: an active paid subscription AND a trial that is still
        // marked "active" (its own conversion webhook may not have run yet) can coexist.
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [[
                    'id' => 4,
                    'status' => 'active',
                    'plan' => ['id' => 3, 'name' => 'Adocare Pro'],
                    'price' => ['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly'],
                ]],
                'trial' => ['status' => 'active', 'ends_at' => now()->addDays(10)->toIso8601String()],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertTrue($resp->json('data.trial.active'));
        $this->assertEquals('subscription', $resp->json('data.current.type'));
        $this->assertEquals('Adocare Pro', $resp->json('data.current.subscription.plan.name'));
    }

    public function test_expired_trial_without_paid_subscription_reports_expired_state(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => ['status' => 'expired', 'ends_at' => now()->subDays(2)->toIso8601String()],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertEquals('expired_trial', $resp->json('data.current.type'));
    }

    public function test_subscription_endpoint_displays_full_plan_price_and_period_details(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [[
                    'id' => 4,
                    'status' => 'active',
                    'current_period_start' => '2026-09-06T00:00:00+00:00',
                    'current_period_end' => '2026-10-06T00:00:00+00:00',
                    'canceled_at' => null,
                    'ended_at' => null,
                    'plan' => ['id' => 3, 'name' => 'Adocare Pro', 'slug' => 'adocare-pro'],
                    'price' => ['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly'],
                ]],
                'trial' => ['status' => 'converted'],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $sub = $resp->json('data.current.subscription');
        $this->assertEquals('Adocare Pro', $sub['plan']['name']);
        $this->assertEquals('active', $sub['status']);
        $this->assertEquals(4500, $sub['price']['amount']);
        $this->assertEquals('EUR', $sub['price']['currency']);
        $this->assertEquals('monthly', $sub['price']['interval']);
        $this->assertEquals('2026-09-06T00:00:00+00:00', $sub['current_period_start']);
        $this->assertEquals('2026-10-06T00:00:00+00:00', $sub['current_period_end']);
    }

    public function test_payments_are_returned_from_the_bundled_snapshot(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => null,
                'payments' => [[
                    'id' => 1,
                    'date' => '2026-09-06T07:51:10+00:00',
                    'amount' => 4500,
                    'currency' => 'EUR',
                    'status' => 'paid',
                    'payment_method' => null,
                    'invoice_id' => 3,
                ]],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertCount(1, $resp->json('data.payments'));
        $this->assertEquals(4500, $resp->json('data.payments.0.amount'));
        $this->assertEquals('paid', $resp->json('data.payments.0.status'));
    }

    public function test_empty_payment_history_is_a_graceful_empty_array(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => null,
                'payments' => [],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertEquals([], $resp->json('data.payments'));
    }

    public function test_invoices_are_returned_with_view_and_pdf_urls(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => null,
                'invoices' => [[
                    'id' => 3,
                    'number' => '2XPEHJ4A-0004',
                    'date' => '2026-09-06T07:51:09+00:00',
                    'amount_due' => 4500,
                    'amount_paid' => 4500,
                    'currency' => 'EUR',
                    'status' => 'paid',
                    'period_start' => '2026-09-06T07:51:09+00:00',
                    'period_end' => '2026-09-06T07:51:09+00:00',
                    'view_url' => 'https://invoice.stripe.com/i/acct_123/test_abc',
                    'pdf_url' => 'https://pay.stripe.com/invoice/acct_123/test_abc/pdf',
                ]],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertCount(1, $resp->json('data.invoices'));
        $this->assertEquals('2XPEHJ4A-0004', $resp->json('data.invoices.0.number'));
        $this->assertEquals('https://invoice.stripe.com/i/acct_123/test_abc', $resp->json('data.invoices.0.view_url'));
        $this->assertEquals('https://pay.stripe.com/invoice/acct_123/test_abc/pdf', $resp->json('data.invoices.0.pdf_url'));
    }

    public function test_empty_invoice_history_is_a_graceful_empty_array(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => null,
                'invoices' => [],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertEquals([], $resp->json('data.invoices'));
    }

    public function test_companies_cannot_see_each_others_billing_data(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [['id' => 1, 'status' => 'active', 'plan' => ['name' => 'Own Company Plan']]],
                'trial' => null,
            ], 200),
        ]);

        $ownCompany = Company::factory()->create(['studiokristian_customer_token' => 'own-token']);
        $otherCompany = Company::factory()->create(['studiokristian_customer_token' => 'other-token']);
        $user = User::factory()->create(['company_id' => $ownCompany->id]);

        // The Company is resolved from the authenticated session, never from any browser input.
        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription?company_id=' . $otherCompany->id);

        $resp->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Billing-Customer-Token', 'own-token')
                && !$request->hasHeader('X-Billing-Customer-Token', 'other-token');
        });
    }

    public function test_studiokristian_uppercase_active_status_is_still_recognized(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [['id' => 1, 'status' => 'ACTIVE', 'plan' => ['name' => 'Adocare Pro']]],
                'trial' => ['status' => 'converted'],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertEquals('subscription', $resp->json('data.current.type'));
    }

    public function test_no_subscription_and_no_trial_reports_none_state(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
                'trial' => null,
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertEquals('none', $resp->json('data.current.type'));
    }

    public function test_active_subscription_invalidates_cached_access_control_state(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [['id' => 1, 'status' => 'active']],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([
                'data' => ['status' => 'converted'],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        // Simulate a stale cached "no active subscription" access-control result from
        // right before checkout completed.
        \Illuminate\Support\Facades\Cache::put("studiokristian_billing:subscription_active:{$company->id}", false, 60);

        $this->actingAs($user)->getJson('/api/v1/billing/subscription')->assertStatus(200);

        $this->assertNull(\Illuminate\Support\Facades\Cache::get("studiokristian_billing:subscription_active:{$company->id}"));
    }

    public function test_customer_subscription_uses_project_and_customer_credentials(): void
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

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertTrue($resp->json('data.billing_provisioned'));
        $this->assertEquals('active', $resp->json('data.subscriptions.0.status'));

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer project-token')
                && $request->hasHeader('X-Billing-Customer-Token', 'customer-token');
        });
    }

    public function test_subscription_without_customer_token_reports_not_provisioned(): void
    {
        $company = Company::factory()->create(['studiokristian_customer_token' => null]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertFalse($resp->json('data.billing_provisioned'));
        $this->assertEquals([], $resp->json('data.subscriptions'));
    }

    public function test_checkout_sends_internal_plan_price_id_and_idempotency_key(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Professional', 'prices' => [['id' => 20, 'amount' => 19, 'currency' => 'EUR', 'interval' => 'month']]],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/checkout' => Http::response([
                'id' => 'cs_test_session',
                'url' => 'https://checkout.stripe.test/session',
            ], 201),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/checkout', [
            'plan_price_id' => 20,
            'success_url' => 'https://app.test/billing/success',
            'cancel_url' => 'https://app.test/billing/cancel',
        ]);

        $resp->assertStatus(200);
        $this->assertEquals('https://checkout.stripe.test/session', $resp->json('data.checkout_url'));
        $this->assertEquals('cs_test_session', $resp->json('data.session_id'));

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/api/v1/billing/checkout')) {
                return true;
            }

            return $request['plan_price_id'] === 20
                && $request->hasHeader('Idempotency-Key')
                && $request->hasHeader('Authorization', 'Bearer project-token')
                && $request->hasHeader('X-Billing-Customer-Token', 'customer-token')
                && !$request->hasHeader('Stripe-Secret')
                && !str_contains(json_encode($request->data()), 'trial');
        });
    }

    public function test_checkout_surfaces_studiokristians_real_validation_message(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Professional', 'prices' => [['id' => 20, 'amount' => 19, 'currency' => 'EUR', 'interval' => 'month']]],
                ],
            ], 200),
            // e.g. a non-https redirect url (as happens with a plain-http local dev origin) -
            // this is a real StudioKristian validation rejection, not an invalid plan/price.
            'billing.studiokristian.test/api/v1/billing/checkout' => Http::response([
                'message' => 'The success url field must start with one of the following: https://.',
                'errors' => ['success_url' => ['The success url field must start with one of the following: https://.']],
            ], 422),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/checkout', [
            'plan_price_id' => 20,
            'success_url' => 'http://127.0.0.1:8001/billing/success',
            'cancel_url' => 'http://127.0.0.1:8001/billing/cancel',
        ]);

        $resp->assertStatus(422);
        $this->assertEquals(
            'The success url field must start with one of the following: https://.',
            $resp->json('message')
        );
    }

    public function test_checkout_rejects_plan_price_id_not_in_studiokristian_catalog(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Professional', 'prices' => [['id' => 20, 'amount' => 19, 'currency' => 'EUR', 'interval' => 'month']]],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/checkout' => Http::response([], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        // 999999 is not one of the ids StudioKristian returned from /plans - e.g. a stray/foreign/stripe id.
        $resp = $this->actingAs($user)->postJson('/api/v1/billing/checkout', [
            'plan_price_id' => 999999,
            'success_url' => 'https://app.test/billing/success',
            'cancel_url' => 'https://app.test/billing/cancel',
        ]);

        $resp->assertStatus(422);

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/api/v1/billing/checkout');
        });
    }

    public function test_checkout_idempotency_key_is_stable_for_repeated_requests(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Professional', 'prices' => [['id' => 20, 'amount' => 19, 'currency' => 'EUR', 'interval' => 'month']]],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/checkout' => Http::response([
                'id' => 'cs_test_session',
                'url' => 'https://checkout.stripe.test/session',
            ], 201),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $payload = [
            'plan_price_id' => 20,
            'success_url' => 'https://app.test/billing/success',
            'cancel_url' => 'https://app.test/billing/cancel',
        ];

        $this->actingAs($user)->postJson('/api/v1/billing/checkout', $payload)->assertStatus(200);
        $this->actingAs($user)->postJson('/api/v1/billing/checkout', $payload)->assertStatus(200);

        $keys = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_contains($request->url(), '/api/v1/billing/checkout'))
            ->map(fn ($request) => $request->header('Idempotency-Key')[0] ?? null)
            ->values();

        $this->assertCount(2, $keys);
        $this->assertEquals($keys[0], $keys[1]);
    }

    public function test_checkout_without_customer_token_returns_error_without_calling_studiokristian(): void
    {
        Http::fake();

        $company = Company::factory()->create(['studiokristian_customer_token' => null]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/checkout', [
            'plan_price_id' => 20,
            'success_url' => 'https://app.test/billing/success',
            'cancel_url' => 'https://app.test/billing/cancel',
        ]);

        $resp->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_frontend_cannot_override_company_or_customer_credentials(): void
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

        $ownCompany = Company::factory()->create(['studiokristian_customer_token' => 'own-token']);
        $otherCompany = Company::factory()->create(['studiokristian_customer_token' => 'other-token']);
        $user = User::factory()->create(['company_id' => $ownCompany->id]);

        // Attempt to smuggle another company's id / a raw customer or Stripe token - none of these are read by the controller.
        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription?company_id=' . $otherCompany->id . '&customer_token=other-token&stripe_price_id=price_123');

        $resp->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Billing-Customer-Token', 'own-token')
                && !$request->hasHeader('X-Billing-Customer-Token', 'other-token');
        });
    }

    public function test_studiokristian_unavailable_returns_graceful_error_without_leaking_config(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([], 500),
        ]);

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/plans');

        $resp->assertStatus(500);
        $this->assertStringNotContainsString('project-token', $resp->getContent());
    }

    public function test_missing_studiokristian_configuration_returns_graceful_error(): void
    {
        config(['services.studiokristian_billing.base_url' => null]);
        config(['services.studiokristian_billing.project_token' => null]);

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/plans');

        $resp->assertStatus(500);
    }

    public function test_checkout_success_redirect_does_not_by_itself_mark_subscription_active(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        // Simulate the webhook not having been processed by StudioKristian yet.
        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/trial' => Http::response([
                'data' => ['active' => false],
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/subscription');

        $resp->assertStatus(200);
        $this->assertEquals([], $resp->json('data.subscriptions'));
    }

    public function test_upgrade_request_reaches_studiokristian_and_refreshes_subscription(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Rast', 'prices' => [['id' => 20, 'amount' => 3900, 'currency' => 'EUR', 'interval' => 'monthly']]],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscription/change' => Http::response([
                'data' => [
                    'id' => 4,
                    'status' => 'active',
                    'plan' => ['id' => 1, 'name' => 'Rast'],
                    'price' => ['id' => 20, 'amount' => 3900, 'currency' => 'EUR', 'interval' => 'monthly'],
                    'scheduled_change' => null,
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [[
                    'id' => 4,
                    'status' => 'active',
                    'plan' => ['id' => 1, 'name' => 'Rast'],
                    'price' => ['id' => 20, 'amount' => 3900, 'currency' => 'EUR', 'interval' => 'monthly'],
                ]],
                'trial' => null,
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/subscription/change', ['plan_price_id' => 20]);

        $resp->assertStatus(200);
        $this->assertEquals('subscription', $resp->json('data.current.type'));
        $this->assertEquals('Rast', $resp->json('data.current.subscription.plan.name'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/billing/customer/subscription/change')
                && $request['plan_price_id'] === 20
                && $request->hasHeader('Idempotency-Key');
        });
    }

    public function test_upgrade_failure_does_not_mutate_local_state(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Rast', 'prices' => [['id' => 20, 'amount' => 3900, 'currency' => 'EUR', 'interval' => 'monthly']]],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscription/change' => Http::response([
                'message' => 'Unable to change the subscription.',
            ], 422),
        ]);

        $company = Company::factory()->create([
            'studiokristian_customer_token' => 'customer-token',
            'subscription_status' => 'active',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/subscription/change', ['plan_price_id' => 20]);

        $resp->assertStatus(422);
        // The local Company row is never touched by a subscription change request.
        $this->assertEquals('active', $company->fresh()->subscription_status);
    }

    public function test_change_subscription_rejects_a_price_not_in_the_catalog(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response(['data' => []], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/subscription/change', ['plan_price_id' => 999]);

        $resp->assertStatus(422);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'subscription/change'));
    }

    public function test_downgrade_is_represented_as_a_scheduled_change(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Start', 'prices' => [['id' => 10, 'amount' => 1900, 'currency' => 'EUR', 'interval' => 'monthly']]],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscription/change' => Http::response([
                'data' => [
                    'id' => 4,
                    'status' => 'active',
                    'plan' => ['id' => 3, 'name' => 'Rast'],
                    'price' => ['id' => 20, 'amount' => 3900, 'currency' => 'EUR', 'interval' => 'monthly'],
                    'scheduled_change' => [
                        'plan' => ['id' => 1, 'name' => 'Start'],
                        'price' => ['id' => 10, 'amount' => 1900, 'currency' => 'EUR', 'interval' => 'monthly'],
                        'effective_at' => now()->addDays(20)->toIso8601String(),
                    ],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [[
                    'id' => 4,
                    'status' => 'active',
                    'plan' => ['id' => 3, 'name' => 'Rast'],
                    'price' => ['id' => 20, 'amount' => 3900, 'currency' => 'EUR', 'interval' => 'monthly'],
                    'scheduled_change' => [
                        'plan' => ['id' => 1, 'name' => 'Start'],
                        'price' => ['id' => 10, 'amount' => 1900, 'currency' => 'EUR', 'interval' => 'monthly'],
                        'effective_at' => now()->addDays(20)->toIso8601String(),
                    ],
                ]],
                'trial' => null,
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/subscription/change', ['plan_price_id' => 10]);

        $resp->assertStatus(200);
        // Current plan remains Rast until the period ends - never immediately shown as Start.
        $this->assertEquals('Rast', $resp->json('data.current.subscription.plan.name'));
        $this->assertEquals('Start', $resp->json('data.current.subscription.scheduled_change.plan.name'));
    }

    public function test_cancellation_is_scheduled_and_subscription_stays_active(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscription/cancel' => Http::response([
                'data' => [
                    'id' => 4,
                    'status' => 'active',
                    'cancel_at_period_end' => true,
                    'current_period_end' => '2026-10-06T00:00:00+00:00',
                    'plan' => ['id' => 3, 'name' => 'Adocare Pro'],
                    'price' => ['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly'],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [[
                    'id' => 4,
                    'status' => 'active',
                    'cancel_at_period_end' => true,
                    'current_period_end' => '2026-10-06T00:00:00+00:00',
                    'plan' => ['id' => 3, 'name' => 'Adocare Pro'],
                    'price' => ['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly'],
                ]],
                'trial' => null,
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/subscription/cancel');

        $resp->assertStatus(200);
        $this->assertEquals('active', $resp->json('data.current.subscription.status'));
        $this->assertTrue($resp->json('data.current.subscription.cancel_at_period_end'));
        $this->assertEquals('subscription', $resp->json('data.current.type'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'subscription/cancel')
            && $request->hasHeader('Idempotency-Key'));
    }

    public function test_resume_removes_scheduled_cancellation(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscription/resume' => Http::response([
                'data' => [
                    'id' => 4,
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                    'plan' => ['id' => 3, 'name' => 'Adocare Pro'],
                    'price' => ['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly'],
                ],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [[
                    'id' => 4,
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                    'plan' => ['id' => 3, 'name' => 'Adocare Pro'],
                    'price' => ['id' => 17, 'amount' => 4500, 'currency' => 'EUR', 'interval' => 'monthly'],
                ]],
                'trial' => null,
            ], 200),
        ]);

        $company = Company::factory()->create(['studiokristian_customer_token' => 'customer-token']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->postJson('/api/v1/billing/subscription/resume');

        $resp->assertStatus(200);
        $this->assertFalse($resp->json('data.current.subscription.cancel_at_period_end'));
    }

    public function test_companies_cannot_change_or_cancel_each_others_subscriptions(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer/subscription/cancel' => Http::response([
                'data' => ['id' => 1, 'status' => 'active', 'cancel_at_period_end' => true],
            ], 200),
            'billing.studiokristian.test/api/v1/billing/customer/subscriptions' => Http::response([
                'subscriptions' => [['id' => 1, 'status' => 'active', 'cancel_at_period_end' => true]],
                'trial' => null,
            ], 200),
        ]);

        Company::factory()->create(['studiokristian_customer_token' => 'other-token']);
        $ownCompany = Company::factory()->create(['studiokristian_customer_token' => 'own-token']);
        $user = User::factory()->create(['company_id' => $ownCompany->id]);

        $this->actingAs($user)->postJson('/api/v1/billing/subscription/cancel')->assertStatus(200);

        // The Company/customer token used is always resolved from the authenticated session -
        // there is no way to pass another company's id from the browser for this action.
        Http::assertSent(fn ($request) => $request->hasHeader('X-Billing-Customer-Token', 'own-token')
            && !$request->hasHeader('X-Billing-Customer-Token', 'other-token'));
    }

    public function test_plans_expose_entitlements_generically_without_hardcoding_features(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/plans' => Http::response([
                'data' => [[
                    'id' => 1,
                    'name' => 'Rast',
                    'prices' => [['id' => 20, 'amount' => 3900, 'currency' => 'EUR', 'interval' => 'monthly']],
                    'entitlements' => [
                        'users' => ['type' => 'limit', 'value' => 10, 'unit' => 'users'],
                        'branches' => ['type' => 'unlimited'],
                        'priority_support' => ['type' => 'boolean', 'value' => true],
                        'a_totally_new_project_specific_feature' => ['type' => 'custom'],
                    ],
                ]],
            ], 200),
        ]);

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/billing/plans');

        $resp->assertStatus(200);
        // ADOCare passes entitlements through as-is - it never hardcodes/filters feature keys.
        $this->assertEquals('limit', $resp->json('data.0.entitlements.users.type'));
        $this->assertEquals(10, $resp->json('data.0.entitlements.users.value'));
        $this->assertEquals('unlimited', $resp->json('data.0.entitlements.branches.type'));
        $this->assertTrue($resp->json('data.0.entitlements.priority_support.value'));
        $this->assertEquals('custom', $resp->json('data.0.entitlements.a_totally_new_project_specific_feature.type'));
    }

    public function test_provision_customer_credential_command_stores_token_on_company(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $company = Company::factory()->create(['studiokristian_customer_token' => null]);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer-credentials' => Http::response([
                'data' => ['token' => 'brand-new-customer-token', 'already_provisioned' => false],
            ], 201),
        ]);

        $this->artisan('billing:provision-customer', ['company_id' => $company->id])
            ->assertExitCode(0);

        $this->assertTrue($company->fresh()->hasBillingCustomerToken());

        Http::assertSent(function ($request) use ($company) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/v1/billing/customer-credentials')
                && $request['external_reference'] === (string) $company->id
                && $request->hasHeader('Authorization', 'Bearer project-token')
                && !$request->hasHeader('X-Billing-Customer-Token');
        });
    }

    public function test_provision_customer_credential_already_provisioned_without_token_is_a_clear_error(): void
    {
        config(['services.studiokristian_billing.base_url' => 'https://billing.studiokristian.test']);
        config(['services.studiokristian_billing.project_token' => 'project-token']);

        $company = Company::factory()->create(['studiokristian_customer_token' => null]);

        Http::fake([
            'billing.studiokristian.test/api/v1/billing/customer-credentials' => Http::response([
                'data' => ['id' => 8, 'already_provisioned' => true],
            ], 200),
        ]);

        $this->artisan('billing:provision-customer', ['company_id' => $company->id])
            ->assertExitCode(1);

        $this->assertFalse($company->fresh()->hasBillingCustomerToken());
    }

    public function test_superadmin_can_no_longer_manually_update_company_subscription(): void
    {
        $company = Company::factory()->create();
        $superadmin = User::factory()->create();

        $resp = $this->actingAs($superadmin)->putJson("/api/v1/companies/{$company->id}/subscription", [
            'subscription_status' => 'active',
        ]);

        $resp->assertStatus(405);
    }

    public function test_subscription_tiers_no_longer_accept_write_requests(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->postJson('/api/v1/subscription-tiers', [
            'name' => 'New Tier',
        ]);

        $resp->assertStatus(405);
    }
}

