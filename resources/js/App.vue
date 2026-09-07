<!-- src/App.vue -->
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'

import Navbar from '@/components/Navbar.vue'
import Footer from '@/components/Footer.vue'
import Sidebar from '@/components/Sidebar.vue'
import TercialNavbar from './components/TercialNavbar.vue'
import MobileBlocker from './components/MobileBlocker.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import AccessError from '@/pages/AccessError.vue'
import AccessReadOnlyBanner from '@/components/AccessReadOnlyBanner.vue'
import AccessPaymentWarningBanner from '@/components/AccessPaymentWarningBanner.vue'
import { navigationAccessError } from '@/router'

import { useAuthStore } from '@/stores/auth'
import { usePatientStore } from '@/stores/patientStore'
import { useUiOverlayStore } from '@/stores/uiOverlay'
import ModalProvider from './components/ModalProvider.vue'
import useDeathCheck from '@/composables/useDeathCheck'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const uiStore = useUiOverlayStore()
const { contentLoading } = storeToRefs(uiStore)
const toast = useToast()
const patientStore = usePatientStore()
const { current: currentPatient } = storeToRefs(patientStore)
const ROUTES_TOAST_GROUP = 'kilometers-routes-toast'
const TIMELINE_CALC_TOAST_GROUP = 'timeline-calculation-toast'

useDeathCheck({
    router,
    auth,
    patientStore,
    currentPatient,
    toast,
})

const isLoggedIn = computed(() => auth.isAuthenticated)
const showNavbar = computed(() => route.meta.shownavbar !== false)
const showFooter = computed(() => route.meta.showfooter !== false)

const isSidebarOpen = ref(false)
function handleToggleSidebar() {
    isSidebarOpen.value = !isSidebarOpen.value
}

function goToRoutesFromToast() {
    toast.removeGroup(ROUTES_TOAST_GROUP)
    router.push('/accounting/routes')
}

function handleSubscriptionExpired() {
    if (route.name !== 'subscription-expired') {
        router.push({ name: 'subscription-expired' })
    }
}

function handleAccessRestricted(event: Event) {
    const detail = (event as CustomEvent<{ message?: string }>).detail

    toast.add({
        severity: 'warn',
        summary: 'Akcia nie je dostupná',
        detail: detail?.message ?? 'Účet je v režime len na čítanie.',
        life: 6000,
    })
}

onMounted(() => {
    window.addEventListener('subscription-expired', handleSubscriptionExpired)
    window.addEventListener('access-restricted', handleAccessRestricted)
})

onBeforeUnmount(() => {
    window.removeEventListener('subscription-expired', handleSubscriptionExpired)
    window.removeEventListener('access-restricted', handleAccessRestricted)
})
</script>

<template>

    <div class="h-screen flex flex-col bg-darkgrey">
        <Navbar v-if="showNavbar" class="flex-none" :isSidebarOpen="isSidebarOpen"
            @toggle-sidebar="handleToggleSidebar" />

        <TercialNavbar v-if="isLoggedIn && showNavbar" class="flex-none" />

        <div class="flex flex-1 overflow-hidden">
            <div class="flex-1 bg-white p-8 relative" :class="contentLoading ? 'overflow-hidden' : 'overflow-auto'">
                <LoadingOverlay :show="contentLoading" text="" />

                <AccessReadOnlyBanner v-if="isLoggedIn" class="mb-5" />
                <AccessPaymentWarningBanner v-if="isLoggedIn" class="mb-5" />

                <AccessError v-if="navigationAccessError" />
                <router-view v-else />
            </div>

            <Sidebar v-if="isSidebarOpen && isLoggedIn"
                class="flex-none bg-darkgrey text-white border-l border-lightgrey" />
        </div>

        <Footer v-if="showFooter" class="flex-none" />

        <Toast position="bottom-right" />

        <Toast :group="ROUTES_TOAST_GROUP" position="bottom-right">
            <template #message="slotProps">
                <div class="flex flex-col gap-2 w-full">
                    <div class="font-semibold">{{ slotProps.message.summary }}</div>
                    <div class="text-sm">{{ slotProps.message.detail }}</div>
                    <Button label="Skontrolovať" size="small"
                        class="!bg-white !border-0 !text-success hover:!bg-darkgrey !px-2 !w-auto self-start"
                        @click="goToRoutesFromToast" />
                </div>
            </template>
        </Toast>

        <Toast :group="TIMELINE_CALC_TOAST_GROUP" position="bottom-right">
            <template #message="slotProps">
                <div class="rounded-md flex items-center justify-between gap-4 w-full min-w-[22rem]">
                    <div>
                        <p class="text-normal mb-2">{{ slotProps.message.summary }}</p>
                        <p class="text-white text-mini">{{ slotProps.message.detail }}</p>
                    </div>

                    <svg width="50" height="50" viewBox="0 0 237 100" xmlns="http://www.w3.org/2000/svg"
                        class="logo-spinner">
                        <g class="orbit-left-spinner">
                            <path
                                d="M50 0C77.6142 0 100 22.3858 100 50C100 77.6142 77.6142 100 50 100C22.3858 100 0 77.6142 0 50C0 22.3858 22.3858 0 50 0ZM40.9062 36.0781V62H45.5312V57.6094L48.0469 55.3594L54.9062 62H61.8438L51.5938 52.1875L61.2344 43.5625H54.1562L45.5312 51.3906V36.0781H40.9062Z"
                                fill="#FFFFFF" />
                        </g>
                        <path
                            d="M118 0C145.614 0 168 22.3858 168 50C168 77.6142 145.614 100 118 100C90.3858 100 68 77.6142 68 50C68 22.3858 90.3858 0 118 0ZM118.156 43.2344C117.375 43.2344 116.568 43.276 115.734 43.3594C114.901 43.4427 114.068 43.5625 113.234 43.7188C112.401 43.8646 111.583 44.0417 110.781 44.25C109.99 44.4583 109.245 44.6875 108.547 44.9375L109.953 48.7344C110.516 48.474 111.12 48.25 111.766 48.0625C112.422 47.875 113.083 47.7188 113.75 47.5938C114.417 47.4688 115.062 47.375 115.688 47.3125C116.312 47.25 116.885 47.2188 117.406 47.2188C118.365 47.2188 119.219 47.3177 119.969 47.5156C120.729 47.7135 121.375 47.9948 121.906 48.3594C122.448 48.7135 122.87 49.1406 123.172 49.6406C123.474 50.1302 123.651 50.6667 123.703 51.25C122.37 50.9062 121.073 50.651 119.812 50.4844C118.562 50.3177 117.37 50.2344 116.234 50.2344C114.703 50.2344 113.359 50.3802 112.203 50.6719C111.057 50.9531 110.099 51.3594 109.328 51.8906C108.557 52.4115 107.979 53.0365 107.594 53.7656C107.208 54.4948 107.016 55.3021 107.016 56.1875C107.016 57.0625 107.198 57.875 107.562 58.625C107.938 59.3646 108.49 60.0104 109.219 60.5625C109.948 61.1146 110.854 61.5469 111.938 61.8594C113.031 62.1719 114.297 62.3281 115.734 62.3281C116.589 62.3281 117.396 62.2708 118.156 62.1562C118.927 62.0521 119.646 61.9062 120.312 61.7188C120.979 61.5312 121.594 61.3125 122.156 61.0625C122.729 60.8125 123.255 60.5469 123.734 60.2656V62H128.359V53.9688C128.359 50.3333 127.521 47.6354 125.844 45.875C124.167 44.1146 121.604 43.2344 118.156 43.2344ZM116.406 53.9062C116.823 53.9062 117.307 53.9219 117.859 53.9531C118.422 53.9844 119.016 54.0417 119.641 54.125C120.276 54.1979 120.938 54.3021 121.625 54.4375C122.323 54.5729 123.026 54.7396 123.734 54.9375V55.75C123.38 56.0833 122.938 56.4062 122.406 56.7188C121.875 57.0312 121.281 57.3073 120.625 57.5469C119.969 57.7865 119.26 57.9792 118.5 58.125C117.74 58.2708 116.953 58.3438 116.141 58.3438C115.38 58.3438 114.729 58.2812 114.188 58.1562C113.656 58.0208 113.219 57.849 112.875 57.6406C112.531 57.4219 112.281 57.1771 112.125 56.9062C111.969 56.625 111.891 56.3333 111.891 56.0312C111.891 55.75 111.964 55.4792 112.109 55.2188C112.255 54.9583 112.505 54.7344 112.859 54.5469C113.214 54.349 113.677 54.1927 114.25 54.0781C114.823 53.9635 115.542 53.9062 116.406 53.9062Z"
                            fill="#FFFFFF" />
                        <g class="orbit-right-spinner">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M187 0C214.614 0 237 22.3858 237 50C237 77.6142 214.614 100 187 100C159.386 100 137 77.6142 137 50C137 22.3858 159.386 0 187 0ZM186.922 43.2344C185.151 43.2344 183.573 43.4792 182.188 43.9688C180.812 44.4583 179.646 45.1354 178.688 46C177.74 46.8542 177.016 47.8698 176.516 49.0469C176.026 50.2135 175.781 51.474 175.781 52.8281C175.781 54.1927 176.036 55.4531 176.547 56.6094C177.068 57.7656 177.812 58.7708 178.781 59.625C179.75 60.4688 180.932 61.1302 182.328 61.6094C183.724 62.0885 185.302 62.3281 187.062 62.3281C187.927 62.3281 188.76 62.2656 189.562 62.1406C190.375 62.026 191.146 61.8646 191.875 61.6562C192.615 61.4479 193.307 61.2031 193.953 60.9219C194.599 60.6302 195.198 60.3177 195.75 59.9844L193.609 56.5C192.734 57.0417 191.786 57.474 190.766 57.7969C189.755 58.1198 188.677 58.2812 187.531 58.2812C186.49 58.2812 185.542 58.1458 184.688 57.875C183.844 57.5938 183.12 57.2135 182.516 56.7344C181.922 56.2448 181.458 55.6667 181.125 55C180.802 54.3229 180.641 53.5885 180.641 52.7969C180.641 52.0052 180.792 51.276 181.094 50.6094C181.406 49.9323 181.854 49.349 182.438 48.8594C183.031 48.3594 183.75 47.974 184.594 47.7031C185.438 47.4219 186.391 47.2812 187.453 47.2812C188.38 47.2812 189.292 47.3906 190.188 47.6094C191.094 47.8281 192.052 48.1719 193.062 48.6406L195.203 45.1562C194.734 44.875 194.182 44.6198 193.547 44.3906C192.911 44.151 192.229 43.9479 191.5 43.7812C190.781 43.6042 190.031 43.4688 189.25 43.375C188.469 43.2812 187.693 43.2344 186.922 43.2344Z"
                                fill="#FFFFFF" />
                        </g>
                    </svg>
                </div>
            </template>
        </Toast>

        <ModalProvider />

        <MobileBlocker />

        <router-view name="modal" />
    </div>
</template>

<style scoped>
.logo-spinner {
    width: 50px;
    height: 50px;
}

.orbit-left-spinner {
    transform-box: fill-box;
    transform-origin: 118px 50px;
    animation: orbitLeftSpinner 1.5s ease-in-out infinite;
}

.orbit-right-spinner {
    transform-box: fill-box;
    transform-origin: -20px 50px;
    animation: orbitRightSpinner 1.5s ease-in-out infinite;
}

@keyframes orbitLeftSpinner {
    0% {
        transform: rotate(0deg);
    }

    100% {
        transform: rotate(-360deg);
    }
}

@keyframes orbitRightSpinner {
    0% {
        transform: rotate(0deg);
    }

    100% {
        transform: rotate(-360deg);
    }
}
</style>
