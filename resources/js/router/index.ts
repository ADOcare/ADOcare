import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAccessStore } from '@/stores/access'
import nures from './nurse';
import managerRoutes from './manager';
import superadminRoutes from './superadmin';
import generalRoutes from './general';
import publicRoutes from './public';
import onboardingRoutes from './onboarding';
import { computed } from 'vue';
import { isSubscriptionExpired } from '@/utils/subscription';
import { needsCompanyOnboarding } from '@/utils/onboarding';

// flag used by App.vue to show the error page without changing the URL
import { ref } from 'vue'
export const navigationAccessError = ref(false)

const routes = [
    ...generalRoutes,
    ...publicRoutes,
    ...nures,
    ...managerRoutes,
    ...superadminRoutes,
    ...onboardingRoutes,
];

const router = createRouter({
    history: createWebHistory(),
    routes,
})

// Small navigation helpers placed on the router instance so callers can use
// `router.dashboard()` and `router.manager()` instead of remembering paths.

const dashbordRouteName = computed(() => {
    const auth = useAuthStore();
    if (auth.isManager)
        return 'manager-dashboard';

    if (auth.isSuperadmin)
        return 'superadmin-dashboard';

    return 'dashboard';
});


router.dashboard = async () => {
    await router.push({ name: dashbordRouteName.value });
};

router.beforeEach(async (to, _from, next) => {
    const auth = useAuthStore();

    if (to.meta.title) document.title = `${to.meta.title} | adocare`;
    else document.title = 'adocare';

    try {
        await auth.waitUntilInitialized();
    } catch {
        await auth.clearAuth?.();
    }

    if (to.meta.requiresAuth !== false && !auth.isAuthenticated) {
        return next({ name: 'login', query: { redirect: to.fullPath } });
    }

    // Only a BLOCKED company loses normal application access. A READ_ONLY company (expired
    // trial/subscription) stays in the app - its data remains viewable, only mutations are
    // rejected, and the billing page is its way back to full access.
    const access = useAccessStore()
    const isBlocked = access.context
        ? access.isBlocked
        : isSubscriptionExpired(auth.user?.company ?? null, auth.currentRole)

    if (auth.isAuthenticated && auth.currentRole !== 'superadmin' && isBlocked) {
        if (to.name !== 'subscription-expired') {
            return next({ name: 'subscription-expired' });
        }
    }

    if (to.name === 'dashboard' && dashbordRouteName.value !== 'dashboard') {
        return next({ name: dashbordRouteName.value });
    }

    // Managers whose Company is still missing the basic legal/company details get routed
    // into onboarding first. The rest of setup (branch, etc.) is resumable and non-blocking,
    // so it does not gate normal dashboard access here.
    const isOnboardingRoute = typeof to.name === 'string' && to.name.startsWith('onboarding');
    if (
        auth.isAuthenticated &&
        auth.isManager &&
        needsCompanyOnboarding(auth.user?.company) &&
        !isOnboardingRoute
    ) {
        return next({ name: 'onboarding' });
    }

    if (to.meta.roles && (!auth.currentRole || !(to.meta.roles as string[]).includes(auth.currentRole))) {
        navigationAccessError.value = true
        return next(false)
    }

    // Superadmin: keep currently active company synced to URL
    if (auth.isSuperadmin && to.params.companyId) {
        const cid = Number(to.params.companyId)
        if (!Number.isNaN(cid)) {
            auth.setCurrentCompanyId(cid)
        }
    }



    return next();
});


export default router
