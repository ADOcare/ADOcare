<?php

namespace App\Http\Middleware;

use App\Enums\AccessState;
use App\Services\CompanyAccessService;
use App\Support\CompanySubscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Central application-access gate (alias: `subscription.active`).
 *
 * It never inspects a subscription status directly - it asks {@see CompanyAccessService}
 * for the resolved access state and enforces it:
 *
 *   FULL      -> everything passes
 *   READ_ONLY -> reads pass, application mutations are rejected with a structured 403
 *   BLOCKED   -> nothing in the protected API passes (402, as in Phase 1)
 *
 * Billing, onboarding and authentication routes are registered outside this middleware, so
 * login/logout, the billing page, invoices, payments and renewal always stay reachable.
 *
 * A route that uses a mutating HTTP verb but is semantically a read/search/export can opt
 * out of the mutation check with the `read` parameter:
 *   ->withoutMiddleware('subscription.active')->middleware('subscription.active:read')
 */
class EnsureCompanySubscriptionActive
{
    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        $user = Auth::user();

        if (!$user || $user->hasGlobalRole('superadmin')) {
            return $next($request);
        }

        $company = $user->company;

        // A Company still going through onboarding has not been billed yet by design -
        // it must not be blocked by a paid-subscription check before it can even reach
        // /onboarding/billing to provision StudioKristian and start its trial.
        if ($company?->isOnboarding()) {
            return $next($request);
        }

        $access = app(CompanyAccessService::class)->resolve($company);

        if ($access['state'] === AccessState::BLOCKED) {
            return response()->json([
                'message' => CompanySubscription::subscriptionExpiredMessage($company),
                'code' => 'ACCESS_BLOCKED',
                'access_state' => AccessState::BLOCKED->value,
                'reason' => $access['reason'],
                // Kept for backward compatibility with the existing frontend 402 handling.
                'subscription_expired' => true,
            ], 402);
        }

        if ($access['state'] === AccessState::READ_ONLY && $this->isMutation($request, $mode)) {
            return response()->json([
                'message' => 'Táto akcia nie je dostupná, kým je účet v režime len na čítanie. Obnovte predplatné, aby ste mohli pokračovať. Vaše dáta zostávajú v bezpečí.',
                'code' => 'READ_ONLY',
                'access_state' => AccessState::READ_ONLY->value,
                'reason' => $access['reason'],
            ], 403);
        }

        $request->attributes->set('company_access_state', $access['state']);

        return $next($request);
    }

    /**
     * Reads are never blocked. Everything else counts as an application mutation unless the
     * route explicitly declared itself a read (search/preview/export endpoints that use POST
     * for their request body).
     */
    private function isMutation(Request $request, ?string $mode): bool
    {
        if ($mode === 'read') {
            return false;
        }

        return !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }
}