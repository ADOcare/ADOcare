<?php

namespace App\Services;

/**
 * Generic entitlement engine.
 *
 * Consumes the entitlement structure StudioKristian returns for a plan/subscription
 * (`{feature_key: {type, value?, unit?}}`) and answers questions about it. It is
 * deliberately feature-agnostic: no ADOCare feature key, plan name or project-specific
 * assumption may ever be hardcoded here. A feature that a SaaS Project simply does not
 * define is treated as "no restriction known", never as a denial.
 *
 * Entitlement types (defined by StudioKristian's SaasFeature/SaasPlanFeature model):
 *   boolean   -> {type: 'boolean', value: true|false}
 *   limit     -> {type: 'limit', value: 10, unit?: 'people'}
 *   unlimited -> {type: 'unlimited'}
 *   custom    -> {type: 'custom', value?: mixed}   (project-specific, not interpreted here)
 *
 * IMPORTANT: an entitlement is an ALLOWANCE, not a usage balance. `limit('ai_credits')`
 * means "this plan allows N credits", never "N credits are left". Usage/consumption
 * tracking belongs to the resource that owns it, not here.
 */
class EntitlementService
{
    /**
     * @param  array<string, array{type?: string, value?: mixed, unit?: string|null}>  $entitlements
     */
    public function __construct(private array $entitlements = [])
    {
    }

    /**
     * @param  array<string, array>|null  $entitlements
     */
    public static function fromArray(?array $entitlements): self
    {
        return new self(is_array($entitlements) ? $entitlements : []);
    }

    /**
     * Raw entitlement entry, or null when this plan/project does not define the feature.
     *
     * @return array{type?: string, value?: mixed, unit?: string|null}|null
     */
    public function get(string $feature): ?array
    {
        $entitlement = $this->entitlements[$feature] ?? null;

        return is_array($entitlement) ? $entitlement : null;
    }

    /**
     * Whether the feature is granted at all. Boolean features are granted only when their
     * value is true; limit/unlimited/custom features are granted by virtue of being defined.
     */
    public function has(string $feature): bool
    {
        $entitlement = $this->get($feature);

        if ($entitlement === null) {
            return false;
        }

        return match ($this->type($feature)) {
            'boolean' => (bool) ($entitlement['value'] ?? false),
            'limit', 'unlimited', 'custom' => true,
            default => false,
        };
    }

    public function type(string $feature): ?string
    {
        $type = $this->get($feature)['type'] ?? null;

        return $type === null ? null : strtolower(trim((string) $type));
    }

    public function isUnlimited(string $feature): bool
    {
        return $this->type($feature) === 'unlimited';
    }

    /**
     * Numeric allowance for a `limit` feature. Returns null for unlimited features and for
     * features this plan/project does not define - in both cases there is no numeric cap
     * to enforce.
     */
    public function limit(string $feature): ?int
    {
        $entitlement = $this->get($feature);

        if ($entitlement === null || $this->type($feature) !== 'limit') {
            return null;
        }

        return isset($entitlement['value']) ? (int) $entitlement['value'] : null;
    }

    public function unit(string $feature): ?string
    {
        return $this->get($feature)['unit'] ?? null;
    }

    /**
     * Raw value of a `custom` entitlement. Interpretation is intentionally left to the
     * project-specific caller - this engine never assumes a meaning for it.
     */
    public function custom(string $feature): mixed
    {
        return $this->type($feature) === 'custom' ? ($this->get($feature)['value'] ?? null) : null;
    }

    /**
     * Whether reaching `$desiredValue` of a limited resource is still within the allowance.
     * Unlimited features and features with no defined limit always allow.
     */
    public function allows(string $feature, int $desiredValue): bool
    {
        $limit = $this->limit($feature);

        if ($limit === null) {
            return true;
        }

        return $desiredValue <= $limit;
    }

    /**
     * Whether existing usage already exceeds the allowance - which happens legitimately
     * after a downgrade. Being over the limit never removes access to existing data; it
     * only stops further growth (see `allows()`).
     */
    public function isOverLimit(string $feature, int $currentUsage): bool
    {
        $limit = $this->limit($feature);

        return $limit !== null && $currentUsage > $limit;
    }

    /**
     * Generic usage snapshot for a limited resource, suitable for API/UI consumption.
     *
     * @return array{feature: string, usage: int, limit: int|null, unlimited: bool, over_limit: bool, can_add_more: bool, unit: string|null}
     */
    public function usage(string $feature, int $currentUsage): array
    {
        $limit = $this->limit($feature);

        return [
            'feature' => $feature,
            'usage' => $currentUsage,
            'limit' => $limit,
            'unlimited' => $limit === null,
            'over_limit' => $this->isOverLimit($feature, $currentUsage),
            'can_add_more' => $this->allows($feature, $currentUsage + 1),
            'unit' => $this->unit($feature),
        ];
    }

    /**
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->entitlements;
    }

    public function isEmpty(): bool
    {
        return $this->entitlements === [];
    }
}
