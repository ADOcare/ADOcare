<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyAccessService;
use App\Services\CompanyResourceUsageService;
use App\Services\PlanCapabilityService;
use Illuminate\Http\Request;

/**
 * Central access context for the frontend.
 *
 * Deliberately registered outside the `subscription.active` gate: a read-only or blocked
 * Company must still be able to read its own access state in order to render the correct
 * UI and find its way back to the billing page.
 */
class AccessController extends Controller
{
    public function __construct(
        private CompanyAccessService $access,
        private CompanyResourceUsageService $usage,
        private PlanCapabilityService $capabilities,
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $company = $user?->company;

        if ($user?->hasGlobalRole('superadmin')) {
            return $this->success([
                'state' => 'full',
                'billing_state' => 'staff',
                'reason' => null,
                'full_access' => true,
                'read_only' => false,
                'blocked' => false,
                'mutations_allowed' => true,
                'entitlements' => [],
                'usage' => [],
                'capabilities' => [],
            ], 'Access state retrieved');
        }

        $payload = $this->access->toArray($company);
        $payload['usage'] = $this->usage->usageSnapshot($company);
        $payload['capabilities'] = $this->capabilities->capabilities($company);

        return $this->success($payload, 'Access state retrieved');
    }
}
