import type { RouteRecordRaw } from 'vue-router'
import AccessError from '@/pages/AccessError.vue'
import SubscriptionExpired from '@/pages/SubscriptionExpired.vue'
import BillingSuccessPage from '@/pages/Billing/BillingSuccessPage.vue'
import BillingCancelPage from '@/pages/Billing/BillingCancelPage.vue'
import SubscriptionsPage from '@/pages/Settings/Subscriptions/SubscriptionsPage.vue'
import PatientsPrintPreview from '@/pages/Documents/PatientsPrintPreview.vue'

const generalRoutes: Readonly<RouteRecordRaw[]> = [
    { path: '/access-error', name: 'access-error', component: AccessError, meta: { title: 'Prístup zamietnutý', sidebar: false } },
    {
        path: '/subscription-expired',
        name: 'subscription-expired',
        component: SubscriptionExpired,
        meta: {
            title: 'Predplatné vypršalo',
            requiresAuth: false,
            sidebar: false,
            shownavbar: false,
            showfooter: false,
        },
    },
    {
        path: '/billing/success',
        name: 'billing-success',
        component: BillingSuccessPage,
        meta: {
            title: 'Platba úspešná',
            sidebar: false,
            shownavbar: false,
            showfooter: false,
        },
    },
    {
        path: '/billing/cancel',
        name: 'billing-cancel',
        component: BillingCancelPage,
        meta: {
            title: 'Platba zrušená',
            sidebar: false,
            shownavbar: false,
            showfooter: false,
        },
    },
    {
        path: '/billing',
        name: 'billing',
        component: SubscriptionsPage,
        meta: {
            title: 'Fakturácia',
            sidebar: false,
            roles: ['manager'],
        },
    },
    {
        path: '/patients/print-preview',
        alias: '/manager/overview/patients/print-preview',
        name: 'patients-print',
        component: PatientsPrintPreview,
        meta: {
            title: 'Náhľad tlače pacientov',
            sidebar: false,
            navbar: false,
            quartalNavbar: true,
            roles: ['manager', 'nurse'],
        },
    },
];

export default generalRoutes;
