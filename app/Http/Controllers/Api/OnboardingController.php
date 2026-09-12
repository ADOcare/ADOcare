<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StudioKristianBillingException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\OnboardingService;
use App\Services\StudioKristianBillingService;
use App\Support\CompanySubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Mail\GenericEmail;

class OnboardingController extends Controller
{
    public function __construct(
        private OnboardingService $onboarding,
        private StudioKristianBillingService $billing,
    ) {
    }

    /**
     * Current onboarding checklist + trial state for the authenticated Company.
     */
    public function status(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        return $this->success([
            'status' => $company->status,
            'steps' => $this->onboarding->steps($company),
            'complete' => $this->onboarding->isComplete($company),
            'trial' => CompanySubscription::trialState($company),
            'billing_provisioned' => $company->hasBillingCustomerToken(),
        ], 'Onboarding state retrieved');
    }

    /**
     * Save the compact onboarding Company form, then - without any further user
     * interaction - provision the StudioKristian billing customer and start the
     * application trial. The Company is saved even if billing/trial fails afterwards, and
     * a StudioKristian outage never blocks registration: the user still proceeds into the
     * application with a pending/problém billing state and a retry path (see
     * `provisionBilling()`/`startTrial()`), instead of getting stuck on this step.
     */
    public function saveCompany(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ico' => ['required', 'string', 'max:32'],
            'dic' => ['required', 'string', 'max:32'],
            'ic_dph' => ['nullable', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'psc' => ['required', 'string', 'max:32'],
        ]);

        $company->update($validated);

        $wasAlreadyProvisioned = $company->hasBillingCustomerToken();
        $billingError = null;

        try {
            $this->provisionBillingOrFail($company);

            // A fresh provisioning call already sent the billing profile inline. For a Company
            // that was already provisioned, the form above is exactly where real billing
            // fields (name/ico/dic/...) were just edited - push them now so StudioKristian/
            // Stripe reflect the real Company identity, repairing an existing customer in place.
            if ($wasAlreadyProvisioned) {
                $this->billing->syncBillingProfile($company);
            }
        } catch (StudioKristianBillingException $e) {
            $billingError = $e->getMessage();
            Log::warning('Billing customer provisioning failed during Company onboarding.', [
                'company_id' => $company->id,
                'message' => $e->getMessage(),
            ]);
        }

        $trial = CompanySubscription::localTrialState($company->fresh());

        if (!$billingError) {
            try {
                $trial = $this->startTrialOrFail($company, null);
            } catch (StudioKristianBillingException $e) {
                $billingError = $e->getMessage();
                Log::warning('Trial start failed during Company onboarding.', [
                    'company_id' => $company->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $company = $company->fresh();

        return $this->success([
            'company' => $company,
            'trial' => $trial,
            'billing_provisioned' => $company->hasBillingCustomerToken(),
            'billing_error' => $billingError,
        ], 'Company saved');
    }

    /**
     * (Re)attempt provisioning a StudioKristian Customer Credential for the Company.
     * Idempotent - a no-op if one already exists. Surfaces the real StudioKristian error
     * instead of a generic failure so the onboarding UI can show something actionable.
     */
    public function provisionBilling(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        try {
            $this->provisionBillingOrFail($company);
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }

        return $this->success(['billing_provisioned' => true], 'Billing customer provisioned');
    }

    /**
     * Start the application trial via StudioKristian (never a Stripe trial). StudioKristian
     * resolves the Company/Project from the Customer Credential and owns trial duration/credits.
     */
    public function startTrial(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        if (!$company->hasBillingCustomerToken()) {
            return $this->error('Najprv je potrebné nastaviť fakturačné údaje StudioKristian.', 422);
        }

        $validated = $request->validate([
            'plan_price_id' => ['nullable', 'integer'],
        ]);

        try {
            $trial = $this->startTrialOrFail($company, $validated['plan_price_id'] ?? null);
        } catch (StudioKristianBillingException $e) {
            return $this->error($e->getMessage(), $e->status());
        }

        return $this->success($trial, 'Trial started');
    }

    /**
     * Idempotent - a no-op if the Company already has a Customer Credential.
     *
     * @throws StudioKristianBillingException
     */
    private function provisionBillingOrFail(Company $company): void
    {
        if ($company->hasBillingCustomerToken()) {
            return;
        }

        $token = $this->billing->provisionCustomerCredential($company);

        $company->update(['studiokristian_customer_token' => $token]);
    }

    /**
     * Idempotent - a no-op (returns the existing local state) if we already locally know a
     * trial/subscription is active, so a repeat submission never makes a network call at
     * all. Otherwise calls StudioKristian's own `POST /customer/trial` directly - that
     * endpoint is itself idempotent (returns the existing trial instead of restarting it),
     * so no separate "does a trial already exist" remote lookup is needed beforehand.
     * Never marks the trial active locally unless StudioKristian confirmed it.
     *
     * @throws StudioKristianBillingException
     */
    private function startTrialOrFail(Company $company, ?int $planPriceId): array
    {
        if (CompanySubscription::isLocallyKnownActive($company)) {
            return CompanySubscription::localTrialState($company);
        }

        if ($planPriceId && !$this->billing->isValidPlanPriceId($planPriceId)) {
            throw new StudioKristianBillingException('Zvolená cena balíka nie je platná.', 422);
        }

        $trial = $this->billing->startTrial($company);

        $this->cacheTrialLocally($company, $trial, $planPriceId);
        // A previous read may have cached "no trial yet" - drop it so any other request
        // right after this one reflects what we just started instead of stale state.
        CompanySubscription::forgetRemoteCache($company);
        $company = $company->fresh();
        $this->sendTrialStartedEmail($company);

        // Built from local state (just written from StudioKristian's own response above) -
        // no extra remote round trip needed to report back what we already know.
        return CompanySubscription::localTrialState($company);
    }

    /**
     * Mirror StudioKristian's trial response into the local cached fields (display/legacy
     * compatibility only - StudioKristian remains the source of truth, see
     * CompanySubscription::trialState()).
     */
    private function cacheTrialLocally(Company $company, array $trial, ?int $planPriceId): void
    {
        $fallbackDays = (int) config('services.adocare_trial.days', 14);

        $endsAt = $trial['ends_at'] ?? $trial['trial_end'] ?? null;
        $startedAt = $trial['started_at'] ?? $trial['trial_start'] ?? null;

        $company->update([
            'subscription_status' => 'trial',
            'subscription_started_at' => $startedAt ? Carbon::parse($startedAt) : now(),
            'subscription_ends_at' => $endsAt ? Carbon::parse($endsAt) : now()->addDays($fallbackDays),
            'selected_plan_price_id' => $planPriceId,
        ]);
    }

    private function sendTrialStartedEmail(Company $company): void
    {
        $recipient = $company->representative?->email ?: $company->email;

        if (!$recipient) {
            return;
        }

        Mail::to($recipient)->send(new GenericEmail(
            'Skúšobné obdobie bolo aktivované - ' . ($company->name ?: 'spoločnosť'),
            [
                'subject' => 'Skúšobné obdobie bolo aktivované',
                'companyName' => $company->name,
                'items' => [[
                    'title' => 'Skúšobné obdobie',
                    'message' => sprintf(
                        'Skúšobné obdobie je aktívne do %s.',
                        optional($company->subscription_ends_at)->format('d.m.Y')
                    ),
                ]],
            ],
            'emails.subscription_notifications'
        ));
    }

    /**
     * Mark onboarding complete once every required step is satisfied.
     */
    public function complete(Request $request)
    {
        $company = $request->user()?->company;

        if (!$company) {
            return $this->notFound('Ku aktuálnemu používateľovi nie je priradená žiadna spoločnosť');
        }

        try {
            $company = $this->onboarding->complete($company);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(['status' => $company->status], 'Onboarding completed');
    }
}
