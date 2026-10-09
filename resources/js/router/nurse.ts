import Login from '@/pages/LoginPage.vue'
import Patients from '@/pages/Patients/PatientListPage.vue'
import Settings from '@/pages/Settings.vue'
import Procedures from '@/pages/Settings/Procedures/ProceduresPage.vue'
import Diagnoses from '@/pages/Settings/Diagnoses/DiagnosesPage.vue'
import Doctors from '@/pages/Settings/Doctors/DoctorsPage.vue'
import Macros from '@/pages/Settings/Macros/MacrosPage.vue'
import Data from '@/pages/Data/DataPage.vue'
import Points from '@/pages/Data/Points.vue'
import Kilometers from '@/pages/Data/Kilometers.vue'
import MonthlyExportWizard from '@/pages/Data/MonthlyExportWizard.vue'
import Routes from '@/pages/Data/Routes.vue'
import Patient from '@/pages/Patients/PatientDetailPage.vue'
import PatientPoints from '@/pages/Patients/Points/PatientPointsPage.vue'
import Document from '@/pages/Documents/DocumentsPage.vue'
import DocumentPoints from '@/pages/Actions/PointsCreate.vue'
import DocumentKilometers from '@/pages/Documents/Kilometers.vue'
import DocumentProposal from '@/pages/Documents/Proposal.vue'
import DocumentAgreement from '@/pages/Documents/Agreement.vue'
import DocumentCP from '@/pages/Documents/CP.vue'
import DocumentDZC from '@/pages/Documents/DZC.vue'
import DocumentDekurz from '@/pages/Documents/Dekurz.vue'
import DocumentLeave from '@/pages/Documents/Leave.vue'
import DocumentRecord from '@/pages/Documents/Record.vue'
import DocumentNalez from '@/pages/Documents/Nalez.vue'
import DocumentKilometersShow from '@/pages/Documents/KilometersShow.vue'
import DocumentPointsShow from '@/pages/Documents/PointsShow.vue'
import DocumentInvoice from '@/pages/Documents/Invoice.vue'
import PatientAgreement from '@/pages/Patients/Agreement/PatientAgreementPage.vue'
import PatientRecord from '@/pages/Patients/Record/PatientRecordPage.vue'
import PatientDekurz from '@/pages/Patients/Dekurz/PatientDekurzPage.vue'
import PatientLeave from '@/pages/Patients/Leave/PatientLeavePage.vue'
import type { RouteRecordRaw } from 'vue-router'
import DashboardPage from '@/pages/DashboardPage.vue'
import ScanCapturePage from '@/pages/Patients/Scan/ScanCapturePage.vue'
import { defineAsyncComponent } from 'vue'
import useAuthStore from '@/stores/auth'

const PatientProposal = defineAsyncComponent(() => import('@/pages/Patients/Proposal/PatientProposalPage.vue'))


function showOnSidebar() {
    return useAuthStore().currentRole === 'nurse';
}

const generalRoutes: Readonly<RouteRecordRaw[]> = [
    { path: '', redirect: { name: 'dashboard' } },
    { path: '/', redirect: { name: 'dashboard' } },
    { path: '/dashboard', name: 'dashboard', component: DashboardPage },
    {
        path: '/login',
        name: 'login',
        component: Login,
        meta: {
            requiresAuth: false
        }
    },
    {
        path: '/register',
        name: 'register',
        component: () => import('@/pages/Auth/RegisterPage.vue'),
        meta: {
            title: 'Registrácia',
            requiresAuth: false,
            sidebar: false,
        }
    },
    {
        path: '/data',
        name: 'data',
        component: Data,
        meta: {
            title: 'Dávka',
            sectionRoot: 'data',
            sidebar: showOnSidebar
        },
        children: [
            {
                path: 'points',
                name: 'pointsdata',
                component: Points,
                meta: {
                    title: 'Výkonová',
                    link: 'výkonová',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },

            {
                path: 'kilometers',
                name: 'kilometersdata',
                component: Kilometers,
                meta: {
                    title: 'Dopravná',
                    link: 'dopravná',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },
            {
                path: 'monthly-export',
                name: 'monthly-export-wizard',
                component: MonthlyExportWizard,
                meta: {
                    title: 'Mesačná uzávierka',
                    link: 'mesačná uzávierka',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },
        ]
    },

    {
        path: '/documents',
        name: 'documents',
        component: Document,
        meta: {
            title: 'Dokumenty',
            sectionRoot: 'documents',
            sidebar: false
        },
        children: [
            {
                path: 'points',
                name: 'pointsdocument',
                component: DocumentPoints,
                meta: {
                    title: 'Bodovanie',
                    link: 'bodovanie',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'kilometers',
                name: 'kilometersdocument',
                component: DocumentKilometers,
                meta: {
                    title: 'Kilometre',
                    link: 'kilometre',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'proposal/:documentId',
                name: 'documents-proposal',
                component: DocumentProposal,
                meta: {
                    title: 'Návrh na poskytnutie ošetrovateľskej starostlivosti',
                    link: 'návrh',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'agreement/:documentId',
                name: 'documents-agreement',
                component: DocumentAgreement,
                meta: {
                    title: 'Dohoda o poskytnutí zdravotnej starostlivosti v rozsahu ošetrovateľskej starostlivosti',
                    link: 'dohoda',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'cp/:documentId',
                name: 'documents-cp',
                component: DocumentCP,
                meta: {
                    title: 'Cestovný príkaz',
                    link: 'cestovný príkaz',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'dzc/:documentId',
                name: 'documents-dzc',
                component: DocumentDZC,
                meta: {
                    title: 'Denný záznam ciest',
                    link: 'denný záznam ciest',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'dekurz/:documentId',
                name: 'documents-dekurz',
                component: DocumentDekurz,
                meta: {
                    title: 'Dekurz',
                    link: 'dekurz',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'leave/:documentId',
                name: 'documents-leave',
                component: DocumentLeave,
                meta: {
                    title: 'Ošetrovateľská prepúšťacia správa',
                    link: 'prepúšťacia správa',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'record/:documentId',
                name: 'documents-record',
                component: DocumentRecord,
                meta: {
                    title: 'Vstupný záznam sesterského posúdenia',
                    link: 'sesterské posúdenie',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'scan/:documentId',
                name: 'documents-scan',
                component: DocumentNalez,
                meta: {
                    title: 'Lekársky nález',
                    link: 'lekársky nález',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'kilometers/:documentId',
                name: 'documents-kilometers-show',
                component: DocumentKilometersShow,
                meta: {
                    title: 'Kilometre',
                    sidebar: false,
                    navbar: false
                },
            },
            {
                path: 'points/:documentId',
                name: 'documents-points-show',
                component: DocumentPointsShow,
                meta: {
                    title: 'Body',
                    sidebar: false,
                    navbar: false
                },
            },
            {
                path: 'invoices/:documentId',
                name: 'documents-invoice-show',
                component: DocumentInvoice,
                meta: {
                    title: 'Faktúra',
                    sidebar: false,
                    navbar: false,
                },
            },
        ]
    },

    {
        path: '/patient',
        name: 'patient',
        component: Patient,
        meta: {
            title: 'Pacient',
            sectionRoot: 'patient',
            sidebar: false
        },
        children: [
            {
                path: 'points',
                name: 'points',
                component: PatientPoints,
                meta: {
                    title: 'Bodovanie pacienta',
                    link: 'bodovanie',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'dekurz',
                name: 'dekurz',
                component: PatientDekurz,
                meta: {
                    title: 'Dekurz',
                    link: 'dekurz',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'proposal',
                name: 'proposal',
                component: PatientProposal,
                meta: {
                    title: 'Návrh na poskytnutie ošetrovateľskej starostlivosti',
                    link: 'návrh',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'agreement',
                name: 'agreement',
                component: PatientAgreement,
                meta: {
                    title: 'Dohoda o poskytnutí zdravotnej starostlivosti v rozsahu ošetrovateľskej starostlivosti',
                    link: 'dohoda',
                    sidebar: false,
                    navbar: false,
                },
            },
            {
                path: 'record',
                name: 'record',
                component: PatientRecord,
                meta: {
                    title: 'Ošetrovateľský záznam',
                    link: 'ošetrovateľský záznam',
                    sidebar: false,
                    navbar: false,
                },
            },

            {
                path: 'leave',
                name: 'leave',
                component: PatientLeave,
                meta: {
                    title: 'Ošetrovateľská prepúšťacia správa',
                    link: 'prepúšťacia správa',
                    sidebar: false,
                    navbar: false,
                },
            },

        ]
    },
    {
        path: '/accounting',
        name: 'accounting',
        component: Data,
        redirect: { name: 'routes' },
        meta: {
            title: 'Účtovníctvo',
            sectionRoot: 'accounting',
            sidebar: showOnSidebar,
        },
        children: [
            {
                path: 'routes',
                name: 'routes',
                component: Routes,
                meta: {
                    title: 'Cestovné',
                    link: 'cestovné',
                    sidebar: showOnSidebar,
                    navbar: false,
                },
            },
        ]
    },
    {
        path: '/settings',
        name: 'settings',
        redirect: { name: 'doctors' },
        component: Settings,
        meta: {
            title: 'Nastavenia',
            sectionRoot: 'settings',
            sidebar: showOnSidebar
        },
        children: [
            {
                path: 'doctors',
                name: 'doctors',
                component: Doctors,
                meta: {
                    title: 'Spolupracujúci lekári',
                    link: 'spolupracujúci lekári',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },
            {
                path: 'macros',
                name: 'macros',
                component: Macros,
                meta: {
                    title: 'Makrá',
                    link: 'makrá',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },
        ],
    },
    {
        path: '/overview',
        name: 'overview',
        component: Settings,
        redirect: { name: 'doctors' },
        meta: {
            title: 'Prehľady',
            sectionRoot: 'overview',
            sidebar: showOnSidebar

        },
        children: [
            {
                path: 'patients',
                name: 'patients',
                component: Patients,
                meta: {
                    title: 'Pacienti',
                    link: 'pacienti',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },
            {
                path: 'procedures',
                name: 'procedures',
                component: Procedures,
                meta: {
                    title: 'Výkony',
                    link: 'výkony',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },
            {
                path: 'diagnoses',
                name: 'diagnoses',
                component: Diagnoses,
                meta: {
                    title: 'Diagnózy',
                    link: 'diagnózy',
                    sidebar: showOnSidebar,
                    navbar: true,
                },
            },
        ],
    },
    {
        path: '/scan',
        name: 'scan',
        meta: {
            title: 'Skenovanie',
            sectionRoot: 'scan',
            sidebar: false

        },
        children: [
            {
                path: '/scan/:token',
                name: 'scan-capture',
                component: ScanCapturePage,
                meta: {
                    requiresAuth: false,
                    title: 'Lekársky nález',
                    shownavbar: false,
                    allowMobile: true,
                }
            },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: { name: 'dashboard' }
    }
];



export default generalRoutes;
