import { defineStore } from 'pinia';
import api from '@/services/api';

export type AccessStateValue = 'full' | 'read_only' | 'blocked';

export interface Entitlement {
    type: 'boolean' | 'limit' | 'unlimited' | 'custom' | string;
    value?: number | boolean | string | null;
    unit?: string | null;
}

export interface EntitlementUsage {
    feature: string;
    usage: number;
    limit: number | null;
    unlimited: boolean;
    over_limit: boolean;
    can_add_more: boolean;
    unit: string | null;
}

export interface PaymentContext {
    status: string | null;
    payment_status: string | null;
    payment_failed_at: string | null;
    grace_period_ends_at: string | null;
    payment_action_required: boolean;
    current_period_start: string | null;
    current_period_end: string | null;
    server_time: string | null;
}

export interface AccessContext {
    state: AccessStateValue;
    billing_state: string;
    reason: string | null;
    full_access: boolean;
    read_only: boolean;
    blocked: boolean;
    mutations_allowed: boolean;
    entitlements: Record<string, Entitlement>;
    usage?: Record<string, EntitlementUsage>;
    capabilities?: Record<string, boolean>;
    payment?: PaymentContext | null;
}

/**
 * Central frontend representation of the resolved application access state.
 *
 * The backend is authoritative - this store only mirrors what `GET /v1/access` (and the
 * billing endpoints) return so the UI can reflect it. It is never mutated locally to
 * "unlock" anything; every change of state comes from a fresh authoritative response.
 */
export const useAccessStore = defineStore('access', {
    state: () => ({
        context: null as AccessContext | null,
        loading: false,
    }),
    getters: {
        accessState: (state): AccessStateValue => state.context?.state ?? 'full',
        // Default to permissive until loaded: the backend enforces access regardless, and a
        // not-yet-loaded context must never make the whole UI look disabled.
        canMutate: (state): boolean => state.context?.mutations_allowed ?? true,
        isReadOnly: (state): boolean => state.context?.read_only ?? false,
        isBlocked: (state): boolean => state.context?.blocked ?? false,
        restrictionReason: (state): string | null => state.context?.reason ?? null,
        entitlements: (state): Record<string, Entitlement> => state.context?.entitlements ?? {},
        usage: (state): Record<string, EntitlementUsage> => state.context?.usage ?? {},
        capabilities: (state): Record<string, boolean> => state.context?.capabilities ?? {},
        payment: (state): PaymentContext | null => state.context?.payment ?? null,

        // Payment-failure states. Whether the grace period has expired is decided by the
        // backend against server time - never recomputed here from the browser clock.
        isPaymentPastDue: (state): boolean => state.context?.payment?.status === 'past_due',
        isInPaymentGracePeriod: (state): boolean => state.context?.reason === 'payment_failed_grace_period',
        isReadOnlyDueToPayment: (state): boolean => state.context?.reason === 'payment_failed_grace_expired',
        paymentActionRequired: (state): boolean => Boolean(state.context?.payment?.payment_action_required),
    },
    actions: {
        async load() {
            this.loading = true;
            try {
                this.context = await api.fetchEntity<AccessContext>('/v1/access');
            } catch {
                // Never block the app on this - the backend still enforces access itself.
                this.context = null;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Adopt the access block returned alongside an authoritative billing response
         * (plan change / cancel / resume), so the UI reflects the new state immediately
         * without an extra round trip - and without ever guessing it locally.
         */
        applyFromBillingResponse(access?: AccessContext | null) {
            if (!access) return;
            this.context = { ...access, usage: this.context?.usage, capabilities: this.context?.capabilities };

            // A plan change moves limits and capabilities, so re-resolve them authoritatively.
            void this.load();
        },

        /** Usage snapshot for a metered plan feature, e.g. `users`. */
        usageFor(feature: string): EntitlementUsage | null {
            return this.context?.usage?.[feature] ?? null;
        },

        /** Whether the plan includes a boolean capability, e.g. `data_migration`. */
        can(feature: string): boolean {
            return this.context?.capabilities?.[feature] === true;
        },

        /** True when the plan meters this resource and the allowance is already used up. */
        atLimit(feature: string): boolean {
            const usage = this.context?.usage?.[feature];

            return usage ? !usage.can_add_more : false;
        },

        /**
         * Opens the Stripe-hosted Billing Portal. ADOCare asks its own backend, which builds
         * the return URL and resolves the Stripe customer through StudioKristian - the browser
         * never sees or supplies any billing identifier.
         */
        async openPaymentMethodPortal(returnPath = '/billing'): Promise<void> {
            const { data } = await api.post('/v1/billing/payment-method', { return_path: returnPath });
            const url = data?.data?.portal_url;

            if (typeof url !== 'string' || !url.startsWith('https://')) {
                throw new Error('Nepodarilo sa otvoriť správu platobnej metódy.');
            }

            window.location.href = url;
        },

        clear() {
            this.context = null;
        },
    },
});

export default useAccessStore;
