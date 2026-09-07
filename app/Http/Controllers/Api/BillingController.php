<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StudioKristianBillingException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CompanyAccessService;
use App\Services\StudioKristianBillingService;
use App\Support\CompanySubscription;
use Illuminate\Http\Request;

/**
 * Consumer-facing billing endpoints backed by the StudioKristian Billing API.
 * ADOCare never talks to Stripe directly - this controller only ever
 * delegates to StudioKristianBillingService.
 */
class BillingController extends Controller
{
    public function __construct(private StudioKristianBillingService $billing)
    {
    }

    /**
     * List the plans/prices available for the ADOCare SaaS Project.
     */
    public function plans()
    {
        try {
            return $this->success($this->billing->getPlans(), 'Plans retrieved');
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }
    }

    /**
     * Current billing/subscription state for the authenticated user's Company: the
     * application-managed trial, StudioKristian's paid subscriptions, payments and invoices,
     * plus the resolved effective/current billing state (paid subscription takes precedence
     * over the trial). Fetched in a single request to StudioKristian - subscriptions, trial,
     * payments and invoices are all bundled in the one `/customer/subscriptions` response.
     */
    public function subscription(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        try {
            $payload = $this->buildBillingPayload($company);
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }

        return $this->success($payload, 'Billing state retrieved');
    }

    /**
     * Change the Company's active subscription to a different price - upgrades apply
     * immediately, downgrades are scheduled by StudioKristian for the end of the current
     * billing period. Never used to create a new subscription (see `checkout()` for that).
     */
    public function changeSubscription(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        $validated = $request->validate([
            'plan_price_id' => ['required', 'integer'],
        ]);

        if (!$company->hasBillingCustomerToken()) {
            return $this->error('Táto spoločnosť ešte nemá priradené fakturačné údaje StudioKristian.', 422);
        }

        try {
            if (!$this->billing->isValidPlanPriceId((int) $validated['plan_price_id'])) {
                return $this->error('Zvolená cena balíka nie je platná.', 422);
            }

            $this->billing->changeSubscription($company, (int) $validated['plan_price_id']);
            CompanySubscription::forgetRemoteCache($company);

            // Authoritative state only - never assume the requested plan is now active,
            // StudioKristian may have scheduled it instead of applying it immediately.
            $payload = $this->buildBillingPayload($company);
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }

        return $this->success($payload, 'Subscription changed');
    }

    /**
     * Schedule the Company's active subscription to cancel at the end of the current billing
     * period - never an immediate cancellation.
     */
    public function cancelSubscription(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        try {
            $this->billing->cancelSubscription($company);
            CompanySubscription::forgetRemoteCache($company);

            $payload = $this->buildBillingPayload($company);
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }

        return $this->success($payload, 'Subscription cancellation scheduled');
    }

    /**
     * Reverse a scheduled end-of-period cancellation.
     */
    public function resumeSubscription(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        try {
            $this->billing->resumeSubscription($company);
            CompanySubscription::forgetRemoteCache($company);

            $payload = $this->buildBillingPayload($company);
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }

        return $this->success($payload, 'Subscription resumed');
    }

    /**
     * @throws StudioKristianBillingException
     */
    private function buildBillingPayload(Company $company): array
    {
        $payload = [
            'trial' => CompanySubscription::localTrialState($company),
            'billing_provisioned' => $company->hasBillingCustomerToken(),
            'subscriptions' => [],
            'payments' => [],
            'invoices' => [],
        ];

        if ($company->hasBillingCustomerToken()) {
            $snapshot = $this->billing->getCustomerBillingSnapshot($company);

            $payload['trial'] = CompanySubscription::trialStateFromSnapshot($company, $snapshot['trial']);
            $payload['subscriptions'] = $snapshot['subscriptions'];
            $payload['payments'] = $snapshot['payments'];
            $payload['invoices'] = $snapshot['invoices'];

            // This read is authoritative and just happened - reuse it for access resolution so
            // a payment recovery (past_due -> active) is reflected immediately rather than
            // waiting out the access cache, and without issuing the same request twice.
            CompanySubscription::primeSubscriptionsCache($company, $snapshot['subscriptions']);
        }

        $payload['current'] = CompanySubscription::resolveCurrentState($payload['trial'], $payload['subscriptions']);

        // A fresh, direct read just proved the real state - never let the (separately cached)
        // access-control view linger stale relative to what we just confirmed, e.g. right
        // after a checkout StudioKristian has already synced but the 60s cache hasn't expired.
        if ($payload['current']['type'] === 'subscription') {
            CompanySubscription::forgetRemoteCache($company);
        }

        // Application access is resolved from the authoritative billing state, never assumed
        // by the caller - the frontend refreshes its access context from this same payload.
        $payload['access'] = app(CompanyAccessService::class)->toArray($company);

        return $payload;
    }

    /**
     * Open a Stripe-hosted Billing Portal session (via StudioKristian) so the customer can
     * update the payment method behind a failed payment. The return URL is built from this
     * application's own configured URL - a browser-supplied destination is never forwarded
     * to Stripe, and no Stripe identifiers ever reach the client.
     */
    public function paymentMethodPortal(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        if (!$company->hasBillingCustomerToken()) {
            return $this->error('Táto spoločnosť ešte nemá priradené fakturačné údaje StudioKristian.', 422);
        }

        $validated = $request->validate([
            // Only a relative in-app path may be requested; the origin is always ours.
            // The negative lookahead rejects protocol-relative paths such as //evil.example.
            'return_path' => ['sometimes', 'string', 'max:200', 'regex:/^\/(?!\/)[A-Za-z0-9\-._~\/]*$/'],
        ]);

        try {
            $session = $this->billing->createPaymentMethodPortalSession(
                $company,
                $this->buildReturnUrl($validated['return_path'] ?? '/billing'),
            );
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }

        if (!$session['portal_url']) {
            return $this->error('Nepodarilo sa otvoriť správu platobnej metódy. Skúste to prosím znova.', 502);
        }

        return $this->success($session, 'Payment method portal session created');
    }

    /**
     * Stripe's Billing Portal only accepts https return URLs, so the app URL must be https
     * in any environment where this flow is used.
     */
    private function buildReturnUrl(string $path): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Start a Stripe Checkout session via StudioKristian for the chosen plan price.
     */
    public function checkout(Request $request)    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        $validated = $request->validate([
            'plan_price_id' => ['required', 'integer'],
            'success_url' => ['required', 'string'],
            'cancel_url' => ['required', 'string'],
        ]);

        if (!$company->hasBillingCustomerToken()) {
            return $this->error('Táto spoločnosť ešte nemá priradené fakturačné údaje StudioKristian.', 422);
        }

        try {
            if (!$this->billing->isValidPlanPriceId((int) $validated['plan_price_id'])) {
                return $this->error('Zvolená cena balíka nie je platná.', 422);
            }

            $session = $this->billing->createCheckoutSession(
                $company,
                (int) $validated['plan_price_id'],
                $validated['success_url'],
                $validated['cancel_url'],
            );
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }

        return $this->success($session, 'Checkout session created');
    }
}
