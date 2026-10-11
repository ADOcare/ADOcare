<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Popover from 'primevue/popover'
import FormField from '@/components/FormField.vue'

import { useAuthStore } from '@/stores/auth'
import { usePatientStore } from '@/stores/patientStore'
import { getPatientIdentifier } from '@/utils/patientIdentifier'
import type { User } from '@/types/models'
import usePatients from '@/composables/usePatients'
import useBranches from '@/composables/useBranches'
import { useNotifications } from '@/composables/useNotifications'
import AppButton from './AppButton.vue'

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
const user = computed<User | null>(() => authStore.user as User | null)

const company = computed(() => user.value?.company ?? null)
const companyName = computed(() => company.value?.name ?? '')
const currentBranch = computed(() => authStore.currentBranch)
const currentRoleLabel = computed(() => (
    authStore.isManager ? 'Manažér' : 'Zdravotná sestra'
))
const fullName = computed(() =>
    user.value
        ? `${user.value.title ?? ''} ${user.value.first_name ?? ''} ${user.value.last_name ?? ''}`.trim()
        : '',
)
const selectedPatientIdentifier = computed(() => {
    return getPatientIdentifier(patientStore.current)
})

/* ------------ INFO / NOTIFICATION POPOVERS ------------ */

const userPopover = ref<InstanceType<typeof Popover> | null>(null)
const companyPopover = ref<InstanceType<typeof Popover> | null>(null)
const notificationPopover = ref<InstanceType<typeof Popover> | null>(null)

const {
    notifications,
    notificationCount,
    loading: notificationsLoading,
    loadNotifications,
} = useNotifications()

function toggleUserPopover(event: Event) {
    userPopover.value?.toggle(event)
}

function toggleCompanyPopover(event: Event) {
    companyPopover.value?.toggle(event)
}

async function toggleNotificationPopover(event: Event) {
    notificationPopover.value?.toggle(event)
    await loadNotifications()
}

function formatNotificationDate(date: Date) {
    return date.toLocaleDateString('sk-SK', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    })
}

/* ------------ BRANCH SELECT OPTIONS ------------ */

const { branchOptions, selectedBranchId, applyBranchSelection } = useBranches()

/* ------------ PATIENT SELECT ------------ */

const {
    patientOptions,
    selectedPatient,
    patientsLoading,
    patientFilterString,
    loadPatients,
    onLazyLoadPatients,
    onFilterPatients,
    setBranchId,
} = usePatients()


const currentBranchAddress = computed(() => {
    if (!currentBranch.value) {
        return ''
    }

    return [
        currentBranch.value.address,
        currentBranch.value.city,
    ]
        .filter(Boolean)
        .join(', ')
})

const companyAddress = computed(() => {
    if (!company.value) {
        return ''
    }

    const cityLine = [
        company.value.psc,
        company.value.city,
    ]
        .filter(Boolean)
        .join(' ')

    return [
        company.value.address,
        cityLine,
    ]
        .filter(Boolean)
        .join(', ')
})

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
watch(selectedPatient, (opt) => {
    if (!opt) return
    patientStore.setPatient(opt.raw)
    router.push('/patient/points')
    selectedPatient.value = null
})

/* ------------ NAVIGATION / ACTIONS ------------ */

async function logout() {
    try {
        await authStore.clearAuth()
    } catch (e) {
        console.error('Logout failed', e)
    } finally {
        router.push('/login')
    }
}

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
    await authStore.waitUntilInitialized()

    if (isAuthenticated.value) {
        await Promise.all([
            initializePatients(),
            loadNotifications(),
        ])
    }
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
        class="px-3 py-2 flex items-center justify-end bg-darkgrey text-lightgrey min-h-10"
        :key="authStore.currentRole ?? 'default'"
    >
        <div v-if="isAuthenticated" class="flex items-center gap-2 text-normal">
            <Select
                v-if="!authStore.isManager && !authStore.isSuperadmin"
                v-model="selectedPatient"
                :options="patientOptions"
                optionLabel="name"
                :virtualScrollerOptions="{
                    lazy: true,
                    onLazyLoad: onLazyLoadPatients,
                    appendOnly: true,
                    itemSize: 38,
                    showLoader: false,
                    loading: patientsLoading,
                    delay: 10,
                }"
                :placeholder="patientsLoading ? 'Načítavam pacientov...' : 'Vyberte pacienta'"
                dropdownIcon="bi bi-chevron-down text-white!"
                class="w-60 h-7! flex items-center border-none! bg-tag2! text-normal text-white!"
            >
                <template #value>
                    <span
                        v-if="patientStore.current"
                        class="flex items-center gap-2 text-normal text-white whitespace-nowrap"
                    >
                        <span class="truncate max-w-55">
                            {{
                                (patientStore.current.title ?? '') + ' ' +
                                (patientStore.current.first_name ?? '') + ' ' +
                                (patientStore.current.last_name ?? '')
                            }}
                        </span>
                        <span class="bg-darkgrey rounded-md text-mini text-white px-2">
                            {{ selectedPatientIdentifier }}
                        </span>
                    </span>
                    <span v-else class="text-normal text-white">Vyberte pacienta</span>
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
                                <i v-if="patientFilterString" class="bi bi-x-lg text-darkgrey text-sm" />
                                <i v-else class="bi bi-search text-darkgrey" />
                            </InputIcon>
                        </IconField>
                    </div>
                </template>

                <template #option="{ option }">
                    <div class="flex">
                        <span class="text-normal text-darkgrey pr-2">{{ option.name }}</span>
                        <span class="bg-darkgrey rounded-md text-mini text-white px-2 content-center">
                            {{ option.personalNumber }}
                        </span>
                    </div>
                </template>

                <template #empty>
                    <div class="flex items-center gap-2 px-2 py-1 text-normal text-darkgrey">
                        <i v-if="patientsLoading" class="bi bi-arrow-repeat animate-spin" />
                        <span>
                            {{ patientsLoading ? 'Načítavam pacientov...' : 'Pacienti neboli nájdení' }}
                        </span>
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

            <!-- User name -->
            <button
                v-if="user"
                type="button"
                class="h-7 flex items-center gap-2 rounded-md bg-tag2 text-white px-3 text-normal whitespace-nowrap cursor-pointer"
                @click="toggleUserPopover"
            >
                <span>{{ fullName }}</span>
            </button>

            <!-- Company name -->
            <button
                v-if="companyName"
                type="button"
                class="h-7 flex items-center gap-2 rounded-md bg-tag2 text-white px-3 text-normal whitespace-nowrap cursor-pointer"
                @click="toggleCompanyPopover"
            >
                <span>{{ companyName }}</span>
            </button>

            <!-- Notification centre -->
            <button
                type="button"
                class="relative h-7 min-h-0 px-2! rounded-md bg-tag2! text-white flex items-center justify-center cursor-pointer hover:bg-accent hover:text-white transition-colors"
                aria-label="Upozornenia"
                @click="toggleNotificationPopover"
            >
                <i class="bi bi-bell"></i>

                <span
                    v-if="notificationCount > 0"
                    class="absolute -top-1.5 -right-1.5 min-w-4 h-4 px-1 rounded-full bg-danger text-white text-[9px] leading-none flex items-center justify-center"
                >
                    {{ notificationCount > 9 ? '9+' : notificationCount }}
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

    <!-- Nurse info -->
    <Popover ref="userPopover">
        <div
            v-if="user"
            class="w-80 flex flex-col gap-10"
        >
            <!-- Keep title/header the same -->
            <div class="flex items-center gap-3">
                <div
                    class="w-10 h-10 shrink-0 rounded-full bg-darkgrey text-white flex items-center justify-center"
                >
                    <i class="bi bi-person-fill text-lg"></i>
                </div>

                <div class="min-w-0">
                    <div class="text-normal text-darkgrey font-medium">
                        {{ fullName }}
                    </div>

                    <div class="text-mini text-accent">
                        {{ currentRoleLabel }}
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <FormField
                    v-if="user.email"
                    label="E-mail"
                    type="text"
                    :model-value="user.email"
                    disabled
                />

                <FormField
                    v-if="user.phone_number"
                    label="Telefón"
                    type="text"
                    :model-value="user.phone_number"
                    disabled
                />

                <FormField
                    v-if="user.code"
                    label="Kód pracovníka"
                    type="text"
                    :model-value="user.code"
                    disabled
                />
            </div>


            <div class="flex flex-col gap-2">
                <AppButton
                    label="Odhlásiť sa"
                    @click="logout"
                />

                <AppButton
                    label="Zmeniť heslo"
                    @click="changePassword"
                    variant="text"
                />
            </div>


        </div>
    </Popover>

    <!-- Company info -->
    <Popover ref="companyPopover">
        <div
            v-if="company"
            class="w-96 flex flex-col gap-10"
        >
            <!-- Keep title/header the same -->
            <div class="flex items-center gap-3">
                <div
                    class="w-10 h-10 shrink-0 rounded-full bg-darkgrey text-white flex items-center justify-center"
                >
                    <i class="bi bi-building text-lg"></i>
                </div>

                <div class="min-w-0">
                    <div class="text-normal text-darkgrey font-medium">
                        {{ company.name }}
                    </div>

                    <div class="text-mini text-accent">
                        Poskytovateľ zdravotnej starostlivosti
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <FormField
                    v-if="company.ico"
                    label="IČO"
                    type="text"
                    :model-value="company.ico"
                    disabled
                />

                <FormField
                    v-if="company.dic"
                    label="DIČ"
                    type="text"
                    :model-value="company.dic"
                    disabled
                />

                <FormField
                    v-if="company.ic_dph"
                    label="IČ DPH"
                    type="text"
                    :model-value="company.ic_dph"
                    disabled
                />

                <div
                    v-if="companyAddress"
                    class="col-span-3"
                >
                    <FormField
                        label="Sídlo"
                        type="text"
                        :model-value="companyAddress"
                        disabled
                    />
                </div>

                <div
                    v-if="company.email"
                    class="col-span-3"
                >
                    <FormField
                        label="E-mail"
                        type="text"
                        :model-value="company.email"
                        disabled
                    />
                </div>

                <div
                    v-if="company.phone"
                    class="col-span-3"
                >
                    <FormField
                        label="Telefón"
                        type="text"
                        :model-value="company.phone"
                        disabled
                    />
                </div>
            </div>
        </div>
    </Popover>

    <!-- Notification centre -->
    <Popover ref="notificationPopover">
        <div
            class="w-[400px] max-w-[calc(100vw-2rem)] max-h-[34rem] flex flex-col gap-10"
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
                class="py-10 flex items-center justify-center gap-2 text-normal text-accent"
            >
                <i class="bi bi-arrow-repeat animate-spin"></i>
                Načítavam upozornenia...
            </div>

            <!-- Empty -->
            <div
                v-else-if="notifications.length === 0"
                class="py-10 flex flex-col items-center justify-center text-center"
            >
                <div
                    class="w-11 h-11 rounded-full bg-tag3 flex items-center justify-center mb-3"
                >
                    <i class="bi bi-check-lg text-accent text-lg"></i>
                </div>

                <div class="text-normal font-medium text-darkgrey">
                    Všetko je v poriadku
                </div>

                <div class="text-mini text-accent mt-1">
                    Momentálne nemáte žiadne upozornenia.
                </div>
            </div>

            <!-- Notifications -->
            <div
                v-else
                class="overflow-y-auto flex flex-col gap-2 pr-1"
            >
                <div
                    v-for="notification in notifications"
                    :key="notification.id"
                    class="
                        group
                        flex
                        gap-3
                        rounded-2xl
                        bg-tag3/60
                        p-3
                        transition-all
                        duration-200
                        hover:bg-tag3
                    "
                >
                    <!-- App-like icon -->
                    <div
                        class="
                            shrink-0
                            w-10
                            h-10
                            rounded-xl
                            bg-danger
                            text-white
                            flex
                            items-center
                            justify-center
                        "
                    >
                        <i class="bi bi-car-front text-base"></i>
                    </div>

                    <!-- Content -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div
                                class="text-normal font-medium text-darkgrey truncate"
                            >
                                {{ notification.title }}
                            </div>

                            <div
                                class="shrink-0 text-mini text-accent whitespace-nowrap"
                            >
                                {{ formatNotificationDate(notification.date) }}
                            </div>
                        </div>

                        <div
                            v-if="notification.description"
                            class="text-mini text-darkgrey mt-0.5 truncate"
                        >
                            {{ notification.description }}
                        </div>

                        <div class="text-mini text-accent mt-1">
                            Termín sa blíži
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Popover>
</template>
