<?php

namespace App\Services;

use App\Exceptions\StudioKristianBillingException;
use App\Models\Company;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server-side client for the StudioKristian generic SaaS Billing API.
 *
 * StudioKristian is the source of truth for plans, prices, Stripe objects,
 * subscriptions, invoices and billing state for the ADOCare SaaS Project.
 * This is the only place in ADOCare allowed to talk to that API - controllers
 * and frontend code must never call StudioKristian directly.
 */
class StudioKristianBillingService
{
    private string $baseUrl;
    private ?string $projectToken;
    private int $timeout;
    private int $connectTimeout;
    private int $plansCacheTtl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.studiokristian_billing.base_url'), '/');
        $this->projectToken = config('services.studiokristian_billing.project_token');
        $this->timeout = (int) config('services.studiokristian_billing.timeout', 10);
        $this->connectTimeout = (int) config('services.studiokristian_billing.connect_timeout', 5);
        $this->plansCacheTtl = (int) config('services.studiokristian_billing.plans_cache_ttl', 300);
    }

    /**
     * Fetch the active plans and prices configured for the ADOCare SaaS Project.
     */
    public function getPlans(): array
    {
        return Cache::remember('studiokristian_billing:plans', $this->plansCacheTtl, function () {
            $response = $this->send(fn () => $this->client()->get('/api/v1/billing/plans'));

            return $this->handle($response)['data'] ?? [];
        });
    }

    /**
     * Whether the given internal plan_price_id belongs to a currently active plan/price
     * for this SaaS Project. Used to reject arbitrary/foreign ids before calling Checkout.
     */
    public function isValidPlanPriceId(int $planPriceId): bool
    {
        foreach ($this->getPlans() as $plan) {
            foreach ($plan['prices'] ?? [] as $price) {
                if ((int) ($price['id'] ?? 0) === $planPriceId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Fetch the current billing/subscription state for a Company.
     * StudioKristian is authoritative here - do not let local data override it.
     */
    public function getCustomerSubscriptions(Company $company): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->get('/api/v1/billing/customer/subscriptions'));

        // Unlike most billing endpoints, this one is not wrapped in a "data" key - it
        // returns {subscriptions, trial, payments, invoices} directly (see BillingController::customer()).
        return $this->handle($response)['subscriptions'] ?? [];
    }

    /**
     * Full billing snapshot for a Company in a single request - subscriptions, trial,
     * payments and invoices are all returned by the same `/customer/subscriptions` response,
     * so the billing page never needs more than one round trip to StudioKristian.
     *
     * @return array{subscriptions: array, trial: ?array, payments: array, invoices: array}
     */
    public function getCustomerBillingSnapshot(Company $company): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->get('/api/v1/billing/customer/subscriptions'));

        $data = $this->handle($response);

        return [
            'subscriptions' => $data['subscriptions'] ?? [],
            'trial' => $data['trial'] ?? null,
            'payments' => $data['payments'] ?? [],
            'invoices' => $data['invoices'] ?? [],
        ];
    }

    /**
     * Standalone payment history fetch (also available bundled in
     * `getCustomerBillingSnapshot()`) - useful for refreshing just the payment list.
     */
    public function getCustomerPayments(Company $company): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->get('/api/v1/billing/customer/payments'));

        return $this->handle($response)['data'] ?? [];
    }

    /**
     * Standalone invoice history fetch (also available bundled in
     * `getCustomerBillingSnapshot()`) - useful for refreshing just the invoice list.
     */
    public function getCustomerInvoices(Company $company): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->get('/api/v1/billing/customer/invoices'));

        return $this->handle($response)['data'] ?? [];
    }

    /**
     * Start the application trial for this Company's StudioKristian Customer Credential.
     * StudioKristian resolves the Company/SaaS Project from the credentials and determines
     * trial duration/credit allowance from the SaaS Project configuration - ADOCare sends
     * nothing else (no dates, no duration, no Stripe anything). Not a Stripe trial.
     */
    public function startTrial(Company $company): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->post('/api/v1/billing/customer/trial'));

        return $this->handle($response)['data'] ?? [];
    }

    /**
     * Fetch the current trial state from StudioKristian (source of truth once a Customer
     * Credential exists).
     */
    public function getTrialState(Company $company): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->get('/api/v1/billing/customer/trial'));

        return $this->handle($response)['data'] ?? [];
    }

    /**
     * Start a Stripe Checkout session for the given internal plan price.
     *
     * @param  int  $planPriceId  StudioKristian internal plan_price_id (never a Stripe price id).
     * @return array{checkout_url: ?string, session_id: ?string}
     */
    public function createCheckoutSession(Company $company, int $planPriceId, string $successUrl, string $cancelUrl): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->withHeaders(['Idempotency-Key' => $this->checkoutIdempotencyKey($company, $planPriceId)])
            ->post('/api/v1/billing/checkout', array_filter([
                'plan_price_id' => $planPriceId,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $company->email,
            ])));

        // Unlike every other billing endpoint, StudioKristian's checkout response is not
        // wrapped in a "data" key - it returns {id, url} directly (see docs/billing-api.md).
        $data = $this->handle($response);

        return [
            'checkout_url' => $data['url'] ?? null,
            'session_id' => $data['id'] ?? null,
        ];
    }

    /**
     * Open a Stripe-hosted Billing Portal session so the customer can update their payment
     * method. StudioKristian resolves the Stripe Customer from the credential pair - ADOCare
     * never sees or sends a Stripe Customer id, and never talks to Stripe itself.
     *
     * @param  string  $returnUrl  Absolute https URL built by ADOCare, never taken from the browser.
     * @return array{portal_url: ?string}
     */
    public function createPaymentMethodPortalSession(Company $company, string $returnUrl): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->post('/api/v1/billing/customer/payment-method', [
                'return_url' => $returnUrl,
            ]));

        // Like /checkout, this endpoint returns {url} at the top level - no "data" wrapper.
        $data = $this->handle($response);

        return ['portal_url' => $data['url'] ?? null];
    }

    /**
     * Provision a new StudioKristian Customer Credential for a Company that doesn't have
     * one yet. Self-service - authenticated with only the Project Credential, no
     * StudioKristian admin session required. Idempotent per Company: StudioKristian keys
     * the credential by (project, external_reference) and never mints a second one for the
     * same `external_reference`.
     */
    public function provisionCustomerCredential(Company $company, ?string $name = null): string
    {
        $response = $this->send(fn () => $this->client()->post('/api/v1/billing/customer-credentials', [
            'external_reference' => (string) $company->id,
            'name' => $name ?? "Company {$company->id} billing session",
            'billing_profile' => $this->billingProfilePayload($company),
        ]));

        $data = $this->handle($response)['data'] ?? [];
        $token = $data['token'] ?? null;

        if ($token) {
            return $token;
        }

        if ($data['already_provisioned'] ?? false) {
            // StudioKristian already has a live credential for this Company but the plaintext
            // token was never (or no longer) stored locally - it cannot be retrieved again.
            throw new StudioKristianBillingException(
                'Táto spoločnosť má vo StudioKristian už priradené fakturačné údaje, ale token sa nedá znovu získať. Kontaktujte podporu.',
                409
            );
        }

        throw new StudioKristianBillingException('StudioKristian nevrátil platný token.', 502);
    }

    /**
     * Push the Company's current billing identity (legal name, billing email/phone/address,
     * ICO/DIC/IC DPH) to StudioKristian - never just the technical credential label. Repairs
     * the existing StudioKristian Company/Stripe Customer in place (no new one is created).
     * Best-effort: a StudioKristian outage here must never block a Company settings save.
     */
    public function syncBillingProfile(Company $company): bool
    {
        if (!$company->hasBillingCustomerToken()) {
            return false;
        }

        try {
            $response = $this->send(fn () => $this->client($this->customerToken($company))
                ->patch('/api/v1/billing/customer/profile', $this->billingProfilePayload($company)));

            // handle() throws on a non-2xx response - a failed sync must never be reported
            // as a success (that previously masked a real Stripe-side update failure).
            $this->handle($response);

            return true;
        } catch (StudioKristianBillingException $e) {
            Log::warning('Failed to sync Company billing profile to StudioKristian.', [
                'company_id' => $company->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The actual Company billing identity - this is what must represent the customer
     * everywhere downstream (StudioKristian Company, Stripe Customer, invoices). Never
     * confuse this with the technical credential label passed as `name` above.
     */
    private function billingProfilePayload(Company $company): array
    {
        $address = array_filter([
            'line1' => $company->address,
            'city' => $company->city,
            'postal_code' => $company->psc,
            // ADOCare is currently a Slovakia-only product - no separate country column exists.
            'country' => 'SK',
        ]);

        return array_filter([
            'name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $address ?: null,
            'ico' => $company->ico,
            'dic' => $company->dic,
            'ic_dph' => $company->ic_dph,
        ]);
    }

    /**
     * Change the Company's active subscription to a different price. StudioKristian decides
     * whether this applies immediately (upgrade, prorated by Stripe) or is scheduled for the
     * end of the current billing period (downgrade) - ADOCare never computes proration or
     * scheduling itself, it only asks for the desired price and reports back what happened.
     */
    public function changeSubscription(Company $company, int $planPriceId): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->withHeaders(['Idempotency-Key' => $this->subscriptionActionIdempotencyKey($company, 'change', (string) $planPriceId)])
            ->post('/api/v1/billing/customer/subscription/change', ['plan_price_id' => $planPriceId]));

        return $this->handle($response)['data'] ?? [];
    }

    /**
     * Schedule the Company's active subscription to cancel at the end of the current billing
     * period - never an immediate cancellation.
     */
    public function cancelSubscription(Company $company): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->withHeaders(['Idempotency-Key' => $this->subscriptionActionIdempotencyKey($company, 'cancel')])
            ->post('/api/v1/billing/customer/subscription/cancel'));

        return $this->handle($response)['data'] ?? [];
    }

    /**
     * Reverse a scheduled end-of-period cancellation.
     */
    public function resumeSubscription(Company $company): array
    {
        $response = $this->send(fn () => $this->client($this->customerToken($company))
            ->withHeaders(['Idempotency-Key' => $this->subscriptionActionIdempotencyKey($company, 'resume')])
            ->post('/api/v1/billing/customer/subscription/resume'));

        return $this->handle($response)['data'] ?? [];
    }

    /**
     * Reuse the same Idempotency-Key for repeated checkout attempts within a short window,
     * so accidental double-clicks/retries don't create multiple Checkout Sessions.
     */
    private function checkoutIdempotencyKey(Company $company, int $planPriceId): string
    {
        return Cache::remember(
            "studiokristian_billing:checkout_idempotency_key:{$company->id}:{$planPriceId}",
            300,
            fn () => (string) Str::uuid()
        );
    }

    /**
     * Same double-click/retry protection as checkout, for subscription-changing actions.
     */
    private function subscriptionActionIdempotencyKey(Company $company, string $action, string $suffix = ''): string
    {
        return Cache::remember(
            "studiokristian_billing:subscription_action_idempotency_key:{$company->id}:{$action}:{$suffix}",
            300,
            fn () => (string) Str::uuid()
        );
    }

    private function customerToken(Company $company): string
    {
        if (!$company->hasBillingCustomerToken()) {
            throw new StudioKristianBillingException(
                'Táto spoločnosť ešte nemá priradené fakturačné údaje StudioKristian.',
                422
            );
        }

        return (string) $company->studiokristian_customer_token;
    }

    /**
     * Runs an outgoing HTTP call and turns a DNS/connection/timeout failure into a clear,
     * fast-failing exception instead of an uncaught `ConnectionException` (or a request that
     * hangs for the full timeout on every retry, which is what makes a broken connection feel
     * like it "freezes" the app on a single-threaded dev server).
     */
    private function send(\Closure $call): \Illuminate\Http\Client\Response
    {
        try {
            return $call();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new StudioKristianBillingException('Fakturačná služba StudioKristian je momentálne nedostupná (chyba pripojenia).', 503);
        }
    }

    private function client(?string $customerToken = null): PendingRequest
    {
        if (!$this->baseUrl || !$this->projectToken) {
            throw new StudioKristianBillingException('Fakturačná služba StudioKristian nie je nakonfigurovaná.', 500);
        }

        $client = Http::baseUrl($this->baseUrl)
            ->withToken($this->projectToken)
            ->acceptJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);

        if ($customerToken !== null) {
            $client = $client->withHeaders(['X-Billing-Customer-Token' => $customerToken]);
        }

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(\Illuminate\Http\Client\Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $status = $response->status();

        // Surface StudioKristian's own validation message when it gives one (e.g. a
        // rejected checkout redirect URL) instead of guessing a generic reason for a 422.
        if ($status === 422) {
            $realMessage = $response->json('message');

            if (is_string($realMessage) && $realMessage !== '') {
                throw new StudioKristianBillingException($realMessage, $status);
            }
        }

        $message = match (true) {
            $status === 401 => 'Neplatné prihlasovacie údaje pre fakturačnú službu.',
            $status === 403 => 'Prístup k fakturačným údajom bol zamietnutý.',
            $status === 404 => 'Požadovaný fakturačný zdroj nebol nájdený.',
            $status === 422 => 'Zvolený balík alebo cena nie je platná.',
            $status >= 500 => 'Fakturačná služba je momentálne nedostupná. Skúste to prosím neskôr.',
            default => 'Nastala chyba pri komunikácii s fakturačnou službou.',
        };

        throw new StudioKristianBillingException($message, $status);
    }
}
