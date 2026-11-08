<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Popover from 'primevue/popover'

import AppButton from '@/components/AppButton.vue'
import { useAuthStore } from '@/stores/auth'
import { usePatientStore } from '@/stores/patientStore'
import usePatients from '@/composables/usePatients'
import useBranches from '@/composables/useBranches'
import {
    useNotifications,
    type AppNotification,
} from '@/composables/useNotifications'
import {
    openPatientCreateModal,
    openPatientEditModal,
    openScanDocumentModal,
} from '@/helpers/modalHelpers'

const router = useRouter()
const authStore = useAuthStore()
const patientStore = usePatientStore()

const emit = defineEmits<{
    (e: 'toggle-sidebar'): void
}>()

const props = defineProps<{
    isSidebarOpen?: boolean
}>()

/* ------------ BASIC AUTH / USER ------------ */

const isAuthenticated = computed(() => authStore.isAuthenticated)

/* ------------ INFO / NOTIFICATION POPOVERS ------------ */

const notificationPopover = ref<InstanceType<typeof Popover> | null>(null)
const notificationList = ref<HTMLElement | null>(null)

const {
    notifications,
    notificationCount,
    loading: notificationsLoading,
    loadingMore: notificationsLoadingMore,
    hasMoreNotifications,
    dismissNotification,
    loadNotifications,
    loadMoreNotifications,
    startNotificationSession,
} = useNotifications()

async function toggleNotificationPopover(event: Event) {
    notificationPopover.value?.toggle(event)
    await loadNotifications()
    await ensureNotificationListFilled()
}

function refreshNotifications(event: Event) {
    const detail = (event as CustomEvent<{ url?: string }>).detail
    if (/notifications\/\d+\/dismiss$/.test(detail?.url ?? '')) {
        return
    }

    if (isAuthenticated.value) {
        void loadNotifications(true)
    }
}

async function ensureNotificationListFilled() {
    await nextTick()

    const element = notificationList.value
    if (
        !element
        || notificationsLoadingMore.value
        || !hasMoreNotifications.value
        || element.scrollHeight > element.clientHeight + 1
    ) {
        return
    }

    await loadMoreNotifications()
    await ensureNotificationListFilled()
}

async function handleNotificationScroll(event: Event) {
    const element = event.currentTarget as HTMLElement
    const remaining = element.scrollHeight - element.scrollTop - element.clientHeight

    if (remaining <= 80) {
        await loadMoreNotifications()
    }
}

async function dismissNotificationSmooth(id: string) {
    await dismissNotification(id)
    await ensureNotificationListFilled()
}

async function openNotification(notification: AppNotification) {
    if (!notification.actionable || !notification.action) {
        return
    }

    notificationPopover.value?.hide()

    if (notification.action.target === 'patient') {
        await openPatientEditModal(notification.action.id)
        await loadNotifications(true)
        return
    }

    if (notification.action.target === 'patient_document_page') {
        try {
            const patient = await patientStore.fetchPatient(notification.action.id)
            const branchId = Number(patient.branch_id)

            if (!Number.isInteger(branchId) || !branchOptions.value.some((branch) => branch.id === branchId)) {
                throw new Error('Patient branch is not available to the current user.')
            }

            if (authStore.currentBranch?.id !== branchId || authStore.isManager) {
                await applyBranchSelection(branchId)
            }

            patientStore.setPatient(patient)
            const targetRoute = notification.action.route ?? 'dekurz'

            await router.push({ name: targetRoute })

            if (targetRoute === 'points') {
                await openScanDocumentModal(notification.action.id, branchId)
            }
        } catch (error) {
            console.error('Failed to open documentation audit action', error)
        }
        await loadNotifications(true)
        return
    }

    await router.push({
        name: 'manager-settings-cars',
        query: {
            car: String(notification.action.id),
        },
    })
}

/* ------------ BRANCH SELECT OPTIONS ------------ */

const { branchOptions, selectedBranchId, applyBranchSelection } = useBranches()

/* ------------ PATIENT SELECT ------------ */

const {
    patientOptions,
    selectedPatient,
    patientsLoading,
    patientsLoadError,
    patientFilterString,
    showCreatePatientAction,
    loadPatients,
    onLazyLoadPatients,
    onFilterPatients,
    setBranchId,
} = usePatients()


async function loadPatientsForSelectedBranch() {
    if (authStore.isManager || authStore.isSuperadmin) {
        return
    }

    const branchId = selectedBranchId.value
    if (branchId == null || branchId < 0) {
        return
    }

    setBranchId(branchId)
    await loadPatients()
}

/* when patient is selected from navbar → save & go to detail page */
watch(selectedPatient, async (opt) => {
    if (!opt) return
    if (!opt.selectable) {
        selectedPatient.value = null
        return
    }

    try {
        await patientStore.fetchPatient(opt.id)
        await router.push('/patient/points')
    } catch (error) {
        console.error('Failed to select patient', error)
    } finally {
        selectedPatient.value = null
    }
})

async function openCreatePatientFromSearch() {
    const created = await openPatientCreateModal()
    if (created) {
        patientFilterString.value = ''
        await loadPatients(true)
    }
}

/* ------------ NAVIGATION / ACTIONS ------------ */

function toggleSidebar() {
    emit('toggle-sidebar')
}

/* ------------ ALWAYS-SELECTED BRANCH LOGIC ------------ */

/**
 * Pick a valid branch id from `branchOptions` and apply it.
 * If current selection isn't in options (or is null), force first option.
 */
async function forceValidBranchSelection() {
    if (authStore.isSuperadmin || authStore.isManager) {
        selectedBranchId.value = -1
        return
    }

    const opts = branchOptions.value ?? []

    if (!opts.length) return

    const current = selectedBranchId.value
    const exists = current != null && opts.some((b: any) => b.id === current)
    const nextId = exists ? (current as number) : (opts[0] as any).id

    if (selectedBranchId.value !== nextId) {
        selectedBranchId.value = nextId
    }

    await applyBranchSelection(nextId)
}

async function handleBranchChange(event: { value?: number | null } | number | null) {
    if (authStore.currentRole === 'superadmin') {
        return
    }

    const id = typeof event === 'number' ? event : event?.value ?? null
    const opts = branchOptions.value ?? []
    const valid = id != null && opts.some((b) => b.id === id)

    if (!valid) {
        await forceValidBranchSelection()
        return
    }

    await applyBranchSelection(id)

    if (!authStore.isManager) {
        await loadPatientsForSelectedBranch()
    }
}

/* ------------ LIFECYCLE ------------ */

async function initializePatients() {
    if (authStore.currentRole === 'superadmin') return

    await forceValidBranchSelection()

    if (authStore.isManager) {
        patientStore.clear()
    } else {
        patientStore.loadFromStorage()
        await loadPatientsForSelectedBranch()
    }
}

onMounted(async () => {
    window.addEventListener('notifications-changed', refreshNotifications)

    await authStore.waitUntilInitialized()

    if (isAuthenticated.value) {
        startNotificationSession(authStore.token)

        await Promise.all([
            initializePatients(),
            loadNotifications(),
        ])
    }
})

onBeforeUnmount(() => {
    window.removeEventListener('notifications-changed', refreshNotifications)
})

watch(branchOptions, async (newOpts) => {
    if (!newOpts || newOpts.length === 0) {
        selectedBranchId.value = null
        return
    }

    await initializePatients()
})
</script>

<template>
    <nav
        class="px-3 py-2 flex items-center justify-between bg-darkgrey text-lightgrey min-h-10"
        :key="authStore.currentRole ?? 'default'"
    >
        <div class="flex items-center gap-2">
            <img src="/logo.svg" alt="Logo" class="h-6" />
        </div>

        <div v-if="isAuthenticated" class="flex items-center gap-2 text-normal">
            <Select
                v-if="!authStore.isManager && !authStore.isSuperadmin"
                v-model="selectedPatient"
                :options="patientOptions"
                optionLabel="name"
                optionDisabled="disabled"
                :virtualScrollerOptions="{
                    lazy: true,
                    onLazyLoad: onLazyLoadPatients,
                    appendOnly: true,
                    itemSize: 56,
                    showLoader: false,
                    loading: patientsLoading,
                    delay: 10,
                }"
                :placeholder="patientsLoading ? 'Načítavam pacientov...' : 'Vyberte pacienta'"
                dropdownIcon="bi bi-chevron-down text-white!"
                class="w-110 h-7! flex items-center border-none! bg-tag2! text-normal text-white!"
            >
                <template #value>
                    <span class="text-normal text-white">Vyberte pacienta</span>
                </template>

                <template #header>
                    <div class="p-2">
                        <IconField class="flex items-center gap-2">
                            <InputText
                                v-model="patientFilterString"
                                placeholder="Filtrovať pacientov..."
                                class="w-full"
                                aria-label="Filtrovať pacientov"
                                @input="onFilterPatients"
                            />
                            <InputIcon
                                class="cursor-pointer"
                                @click="patientFilterString = ''; onFilterPatients()"
                            >
                                <i v-if="patientFilterString" class="bi bi-x-lg text-darkgrey" />
                                <i v-else class="bi bi-search text-darkgrey" />
                            </InputIcon>
                        </IconField>
                    </div>
                </template>

                <template #option="{ option }">
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="truncate text-normal text-darkgrey">{{ option.name }}</span>
                            <Tag
                                v-if="option.personalNumber"
                                class="bg-darkgrey! text-white!"
                            >
                                {{ option.personalNumber }}
                            </Tag>
                            <Tag
                                v-if="!option.selectable && option.nurseLabel"
                                class="bg-darkgrey!"
                            >
                                {{ option.nurseLabel ?? '' }}
                            </Tag>
                        </div>
                    </div>
                </template>

                <template #empty>
                    <div class="flex flex-col gap-2 py-1 text-normal text-darkgrey p-0!">
                        <div class="flex items-center gap-2">
                            <i v-if="patientsLoading" class="bi bi-arrow-repeat animate-spin" />
                            <span>
                                {{
                                    patientsLoading
                                        ? 'Načítavam pacientov...'
                                        : patientsLoadError || 'Pacienti neboli nájdení'
                                }}
                            </span>
                        </div>
                        <AppButton
                            v-if="showCreatePatientAction"
                            type="button"
                            icon="bi bi-plus-lg"
                            label="Pridať nového pacienta"
                            class="w-full!"
                            @click.stop="openCreatePatientFromSearch"
                        />
                    </div>
                </template>
            </Select>
            
            <!-- Branch select (hidden for superadmin) -->
            <Select
                v-if="!authStore.isSuperadmin"
                v-model="selectedBranchId"
                :options="branchOptions"
                optionLabel="label"
                optionValue="id"
                dropdownIcon="bi bi-chevron-down text-white!"
                class="w-60 h-7! flex items-center bg-tag2! border-none! text-normal! text-white!"
                :showClear="false"
                :editable="false"
                :placeholder="branchOptions?.length ? undefined : 'Načítavam pobočky...'"
                @change="async (e) => {
                    await handleBranchChange(e)
                }"
            >
                <template #value>
                    <span class="text-normal text-white">
                        {{ branchOptions?.find((b: any) => b.id === selectedBranchId)?.label }}
                    </span>
                </template>
            </Select>

            <!-- Notification centre -->
            <button
                type="button"
                class="relative h-7 min-h-0 px-2! text-white text-normal! text-\2xl flex items-center justify-center cursor-pointer"
                aria-label="Upozornenia"
                @click="toggleNotificationPopover"
            >
                <i class="bi bi-bell"></i>

                <span
                    v-if="notificationCount > 0"
                    class="absolute -top-0 -right-0 min-w-2 h-2 px-1 rounded-full bg-danger text-white text-[5px] leading-none flex items-center justify-center"
                >
                </span>
            </button>

            <button
                type="button"
                class="relative w-7 h-7 flex items-center justify-center cursor-pointer"
                aria-label="Menu"
                @click="toggleSidebar"
            >
                <span
                    class="absolute w-4 h-[1px] rounded-full bg-white transition-all duration-300 ease-in-out"
                    :class="
                        props.isSidebarOpen
                            ? 'rotate-45 translate-y-0'
                            : '-translate-y-[3px]'
                    "
                />

                <span
                    class="absolute w-4 h-[1px] rounded-full bg-white transition-all duration-300 ease-in-out"
                    :class="
                        props.isSidebarOpen
                            ? '-rotate-45 translate-y-0'
                            : 'translate-y-[3px]'
                    "
                />
            </button>
        </div>
    </nav>

    <!-- Notification centre -->
    <Popover
        ref="notificationPopover"
        @show="ensureNotificationListFilled"
    >
        <div
            class="w-[400px] max-w-[calc(100vw-2rem)] h-[34rem] max-h-[calc(100vh-2rem)] min-h-0 flex flex-col gap-4"
        >
            <!-- Header -->
            <div class="flex items-center gap-3">
                <div
                    class="w-10 h-10 shrink-0 rounded-full bg-darkgrey text-white flex items-center justify-center"
                >
                    <i class="bi bi-bell-fill text-lg"></i>
                </div>

                <div class="min-w-0">
                    <div class="text-normal text-darkgrey font-medium">
                        Centrum upozornení
                    </div>

                    <div class="text-mini text-accent">
                        Tu nájdete všetky svoje upozornenia.
                    </div>
                </div>
            </div>

            <!-- Loading -->
            <div
                v-if="notificationsLoading"
                class="py-10 flex flex-col items-center justify-center text-center text-mini text-accent"
            >
                Načítavam upozornenia...
            </div>

            <!-- Empty -->
            <div
                v-else-if="notifications.length === 0"
                class="py-10 flex flex-col items-center justify-center text-center text-mini text-accent"
            >
                Momentálne nemáte žiadne upozornenia.
            </div>

            <!-- Notifications -->
            <div
                v-else
                ref="notificationList"
                class="
                    flex-1
                    min-h-0
                    overflow-y-auto
                    flex
                    flex-col
                    gap-2
                    px-1
                    py-1
                "
                @scroll.passive="handleNotificationScroll"
            >
                <TransitionGroup name="notification" tag="div" class="flex flex-col gap-2">
                    <div
                        v-for="notification in notifications"
                        :key="notification.id"
                        class="
                            group
                            flex
                            gap-3
                            rounded-md
                            transition-transform
                            duration-200
                            ease-out
                            p-4
                            origin-center
                            hover:scale-[1.015]
                        "
                        :class="
                            notification.actionable
                                ? 'bg-darkgrey cursor-pointer text-white'
                                : 'bg-tag2 text-white'
                        "
                        :role="notification.actionable ? 'button' : undefined"
                        :tabindex="notification.actionable ? 0 : undefined"
                        @click="openNotification(notification)"
                        @keydown.enter="openNotification(notification)"
                    >
                        <!-- Content -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <div
                                    class="text-normal font-medium break-words"
                                >
                                    {{ notification.title }}
                                </div>

                                <button
                                    v-if="notification.dismissible"
                                    type="button"
                                    class="shrink-0 w-6 h-6 flex items-center justify-center cursor-pointer hover:text-darkgrey transition-colors"
                                    aria-label="Skryť upozornenie"
                                    title="Skryť upozornenie"
                                    @click.stop="dismissNotificationSmooth(notification.id)"
                                >
                                    <i class="bi bi-x-lg text-mini" />
                                </button>
                            </div>

                            <div
                                v-if="notification.description"
                                class="text-mini mt-0.5 whitespace-normal break-words"
                            >
                                {{ notification.description }}
                            </div>

                            <div
                                class="text-mini mt-2 flex items-center gap-1"
                                :class="notification.actionable
                                    ? 'text-accent'
                                    : 'text-accent'"
                            >
                                <i
                                    :class="notification.actionable
                                        ? 'bi bi-arrow-up-right-circle'
                                        : 'bi bi-info-circle'"
                                />

                                <span>
                                    {{ notification.actionable
                                        ? 'Vyriešiť'
                                        : 'Informácia' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </TransitionGroup>

                <div
                    v-if="notificationsLoadingMore"
                    class="py-3 text-center text-mini text-accent"
                >
                    Načítavam ďalšie upozornenia...
                </div>

                <button
                    v-else-if="hasMoreNotifications"
                    type="button"
                    class="py-2 text-mini text-accent cursor-pointer"
                    @click="loadMoreNotifications"
                >
                    Načítať ďalšie
                </button>
            </div>
        </div>
    </Popover>
</template>

<style scoped>
.notification-enter-active,
.notification-leave-active,
.notification-move {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}

.notification-enter-from,
.notification-leave-to {
    opacity: 0;
    transform: translateX(0.75rem);
}
</style>
