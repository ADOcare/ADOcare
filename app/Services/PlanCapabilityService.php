<?php

namespace App\Services;

use App\Exceptions\EntitlementRequiredException;
use App\Models\Company;

/**
 * Gate for boolean plan capabilities (`data_migration`, `customization`, `support`, ...).
 *
 * Capabilities are feature-level: a missing capability blocks exactly the feature that needs
 * it and never downgrades the Company's global access state (FULL/READ_ONLY/BLOCKED). Feature
 * keys are passed in by the calling module - none are hardcoded here.
 *
 * Usage from a feature module:
 *   app(PlanCapabilityService::class)->authorize($company, 'data_migration');
 */
class PlanCapabilityService
{
    public function __construct(private CompanyAccessService $access)
    {
    }

    public function allows(?Company $company, string $feature): bool
    {
        if (!$company) {
            return false;
        }

        return $this->access->entitlements($company)->has($feature);
    }

    /**
     * @throws EntitlementRequiredException
     */
    public function authorize(?Company $company, string $feature): void
    {
        if ($this->allows($company, $feature)) {
            return;
        }

        throw new EntitlementRequiredException($this->message($feature), $feature);
    }

    /**
     * Every boolean capability the current plan exposes, e.g.
     * `['data_migration' => true, 'customization' => false, 'support' => true]`.
     */
    public function capabilities(?Company $company): array
    {
        if (!$company) {
            return [];
        }

        $entitlements = $this->access->entitlements($company);
        $capabilities = [];

        foreach ($entitlements->all() as $feature => $entitlement) {
            if (strtolower((string) ($entitlement['type'] ?? '')) === 'boolean') {
                $capabilities[$feature] = $entitlements->has($feature);
            }
        }

        return $capabilities;
    }

    private function message(string $feature): string
    {
        return match ($feature) {
            'data_migration' => 'Migrácia dát nie je súčasťou vášho aktuálneho balíka.',
            'customization' => 'Prispôsobenie nie je súčasťou vášho aktuálneho balíka.',
            default => "Funkcia '{$feature}' nie je súčasťou vášho aktuálneho balíka.",
        };
    }
}
