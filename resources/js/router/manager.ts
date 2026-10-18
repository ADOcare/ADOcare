import Cars from "@/pages/Settings/Cars/Cars.vue";
import CompanySettings from "@/pages/Settings/Companies/CompanySettingsPage.vue";
import Patients from "@/pages/Patients/PatientListPage.vue";
import Branches from "@/pages/Settings/Branches/BranchesPage.vue";
import Settings from "@/pages/Settings.vue";
import Users from "@/pages/Settings/Users/UsersPage.vue";
import Doctors from "@/pages/Settings/Doctors/DoctorsPage.vue";
import SubscriptionsPage from "@/pages/Settings/Subscriptions/SubscriptionsPage.vue";
import type { RouteRecordRaw } from "vue-router";
import Procedures from "@/pages/Settings/Procedures/ProceduresPage.vue";
import Manager from "@/pages/Manager.vue";
import TravelDocuments from "@/pages/Manager/TravelDocuments.vue";
import DataDocuments from "@/pages/Manager/DataDocuments.vue";
import Invoices from "@/pages/Manager/Invoices.vue";
import PlansPage from "@/pages/Settings/Plans/PlansPage.vue";
import useAuthStore from "@/stores/auth";
import PatientStats from "@/pages/Manager/PatientStats.vue";
import FinancialStats from "@/pages/Manager/FinancialStats.vue";
import Wizard from "@/pages/Manager/EndOfMonth.vue";


function showOnSidebar() {
    return useAuthStore().isManager;
}


const managerRoutes: Readonly<RouteRecordRaw[]> = [

    {
        path: '/manager',
        name: 'manager-dashboard',
        component: () => import('@/pages/DashboardManager.vue'),
        meta: {
            title: 'Manažérsky dashboard',
            sidebar: false,
        },
    },
    {
        path: '/manager/reports',
        name: 'manager-reports',
        component: Manager,
        meta: {
            title: 'Výkonnosť',
            sidebar: showOnSidebar,
            secondaryNavbar: true,
        },
        children: [
            {
                path: 'patients-stats',
                name: 'manager-patients-statistics',
                component: PatientStats,
                meta: {
                    title: 'Podľa pacientov',
                    sidebar: showOnSidebar,
                    link: 'podľa pacientov',
                    navbar: true,
                },
            },
            {
                path: 'financial-stats',
                name: 'manager-financial-stats',
                component: FinancialStats,
                meta: {
                    title: 'Podľa tržieb',
                    sidebar: showOnSidebar,
                    link: 'podľa tržieb',
                    navbar: true,
                },
            },
        ],
    },
    {
        path: '/manager/overview',
        name: 'manager-overview',
        component: Settings,
        meta: {
            title: 'Prehľady',
            sidebar: showOnSidebar,
            secondaryNavbar: true,
        },
        children: [
            {
                path: 'patients',
                name: 'manager-overview-patients',
                component: Patients,
                meta: { title: 'Pacienti', sidebar: showOnSidebar, navbar: true, link: 'pacienti' },
            },
            {
                path: 'doctors',
                name: 'manager-overview-doctors',
                component: Doctors,
                meta: { title: 'Spolupracujúci lekári', sidebar: showOnSidebar, navbar: true, link: 'spolupracujúci lekári' },
            },
        ],
    },
    {
        path: '/manager/wizards',
        name: 'manager-wizards',
        component: Settings,
        redirect: { name: 'manager-wizards-wizard' },
        meta: {
            title: 'Pomocník',
            sectionRoot: 'manager-wizards',
            sidebar: showOnSidebar,
            secondaryNavbar: true,
        },
        children: [
            {
                path: 'wizard',
                name: 'wizard',
                component: Wizard,
                meta: {
                    title: 'Uzávierka',
                    sidebar: showOnSidebar,
                    link: 'uzávierka',
                    navbar: true,
                },
            }
        ],
    },
    {
        path: '/manager/documents',
        name: 'manager-documents',
        component: Settings,
        meta: {
            title: 'Dokumenty',
            sidebar: showOnSidebar,
            secondaryNavbar: true,
        },
        children: [
            {
                path: 'data',
                name: 'manager-overview-data',
                component: DataDocuments,
                meta: { title: 'Vykázané dávky', sidebar: showOnSidebar, navbar: true, link: 'vykázané dávky' },
            },
            {
                path: 'travel',
                name: 'manager-overview-travel',
                component: TravelDocuments,
                meta: { title: 'Cestovné Dokumenty', sidebar: showOnSidebar, navbar: true, link: 'cestovné dokumenty' },
            },
            {
                path: 'invoices',
                name: 'manager-overview-invoices',
                component: Invoices,
                meta: { title: 'Faktúry', sidebar: showOnSidebar, navbar: true, link: 'faktúry' },
            },
        ],
    },
    {
        path: '/manager/settings',
        name: 'manager-settings',
        component: Settings,
        redirect: { name: 'manager-settings-company' },
        meta: {
            title: 'Nastavenia',
            sectionRoot: 'manager-settings',
            sidebar: showOnSidebar,
            secondaryNavbar: true,
        },
        children: [

            {
                path: 'plans',
                name: 'manager-settings-plans',
                component: PlansPage,
                meta: { title: 'Plány ošetrovateľskej starostlivosti', link: 'plány ošetrovateľskej starostlivosti', sidebar: showOnSidebar, navbar: true, },
            },
            {
                path: 'procedures',
                name: 'manager-settings-procedures',
                component: Procedures,
                meta: {
                    title: 'Výkony',
                    link: 'výkony',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },
        ],
    },
    {
        path: '/manager/company',
        name: 'manager-company',
        component: Settings,
        redirect: { name: 'manager-settings-company' },
        meta: {
            title: 'Spoločnosť',
            sectionRoot: 'manager-company',
            sidebar: showOnSidebar,
            secondaryNavbar: true,
        },
        children: [
            {
                path: 'company',
                name: 'manager-settings-company',
                component: CompanySettings,
                meta: { title: 'Nastavenia', link: 'nastavenia', sidebar: showOnSidebar, navbar: true, overflow: true },
            },

            {
                path: 'branches',
                name: 'manager-settings-branches',
                component: Branches,
                meta: { title: 'Pobočky', link: 'pobočky', sidebar: showOnSidebar, navbar: true, },
            },
            {
                path: 'users',
                name: 'manager-settings-users',
                component: Users,
                meta: { title: 'Používatelia', link: 'používatelia', sidebar: showOnSidebar, navbar: true, },
            },
            {
                path: 'cars',
                name: 'manager-settings-cars',
                component: Cars,
                meta: { title: 'Autá', link: 'autá', sidebar: showOnSidebar, navbar: true, },
            },
            {
                path: 'subscriptions',
                name: 'manager-settings-subscriptions',
                component: SubscriptionsPage,
                meta: { title: 'Predplatné', link: 'predplatné', sidebar: showOnSidebar, navbar: true, },
            },
            {
                path: 'delete',
                name: 'manager-settings-delete',
                component: () => import('@/pages/Settings/Companies/DeleteCompanyPage.vue'),
                meta: { title: 'Zmazať spoločnosť', link: 'zmazať spoločnosť', sidebar: showOnSidebar, navbar: true, },
            },
        ],
    },

];

managerRoutes.forEach(route => {
    route.meta = route.meta || {};
    route.meta.roles = route.meta.roles || [];
    (route.meta.roles as string[]).push('manager');

    if (route.children) {
        route.children.forEach(child => {
            child.meta = child.meta || {};
            child.meta.roles = child.meta.roles || [];
            (child.meta.roles as string[]).push('manager');
        });
    }

});


export default managerRoutes;
