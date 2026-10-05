<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { usePatientStore } from '@/stores/patientStore'
import { getPatientIdentifier } from '@/utils/patientIdentifier'
import type { User } from '@/types/models'
import usePatients from '@/composables/usePatients'
import useBranches from '@/composables/useBranches'

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

const companyName = computed(() => user.value?.company?.name ?? '')
const fullName = computed(() =>
    user.value ? `${user.value.title ?? ''} ${user.value.first_name ?? ''} ${user.value.last_name ?? ''}`.trim() : ''
)
const selectedPatientIdentifier = computed(() => {
    return getPatientIdentifier(patientStore.current)
})

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
        // superadmin/manager does not use branch selection at all
        selectedBranchId.value = -1;
        return
    }
    const opts = branchOptions.value ?? []

    if (!opts.length) return

    const current = selectedBranchId.value

    // Only allow selecting ids that exist in options
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
    // Always force a valid selection from the dropdown (manager or nurse)
    // NOTE: we do NOT set -1; it must always be a real option id.
    if (authStore.currentRole == 'superadmin') return;

    await forceValidBranchSelection()

    // Nurse: load patients for selected branch
    if (authStore.isManager) {
        // Manager: no patients, but branch stays selected (still required)
        patientStore.clear()
    } else {
        patientStore.loadFromStorage()
        await loadPatientsForSelectedBranch()
    }
}


onMounted(async () => {
    await authStore.waitUntilInitialized()

    if (isAuthenticated.value) {
        await initializePatients()
    }

})


watch(branchOptions, async (newOpts) => {
    if (!newOpts || newOpts.length === 0) {
        selectedBranchId.value = null
        return
    }

    initializePatients()
});

</script>

<template>
    <nav class="px-3 py-2 flex items-center justify-end bg-darkgrey text-lightgrey min-h-10"
        :key="authStore.currentRole ?? 'default'">
        <div v-if="isAuthenticated" class="flex items-center gap-2 text-normal">
            <Button icon="bi bi-arrow-left" text
                class="h-7! min-h-0! px-2! rounded-md! bg-white! text-darkgrey! flex items-center justify-center"
                @click="router.back" />

            <Button icon="bi bi-circle text-xs" text
                class="h-7! min-h-0! px-2! rounded-md! bg-white! text-darkgrey! flex items-center justify-center"
                @click="router.dashboard" />

            <!-- PATIENT SELECT (only nurse users, hide for manager & superadmin) -->
            <Select v-if="!authStore.isManager && !authStore.isSuperadmin" v-model="selectedPatient"
                :options="patientOptions" optionLabel="name" :virtualScrollerOptions="{
                    lazy: true,
                    onLazyLoad: onLazyLoadPatients,
                    appendOnly: true,
                    itemSize: 38,
                    showLoader: false,
                    loading: patientsLoading,
                    delay: 10,
                }" :placeholder="patientsLoading ? 'Načítavam pacientov...' : 'Vyberte pacienta'"
                dropdownIcon="bi bi-chevron-down text-white!"
                class="w-60 h-7! flex items-center border-none! bg-tag2! text-normal text-white!">
                <template #value>
                    <span v-if="patientStore.current"
                        class="flex items-center gap-2 text-normal text-white whitespace-nowrap">
                        <span class="truncate max-w-55">{{ (patientStore.current.title ?? '') + ' ' +
                            (patientStore.current.first_name ?? '') + ' ' + (patientStore.current.last_name ?? '')
                            }}</span>
                        <span class="bg-darkgrey rounded-md text-mini text-white px-2">{{
                            selectedPatientIdentifier }}</span>
                    </span>
                    <span v-else class="text-normal text-white">Vyberte pacienta</span>
                </template>

                <template #header>
                    <div class="p-2">
                        <IconField class="flex items-center gap-2">
                            <InputText v-model="patientFilterString" placeholder="Filtrovať pacientov..." class="w-full"
                                @input="onFilterPatients" aria-label="Filtrovať pacientov" />
                            <InputIcon @click="patientFilterString = ''; onFilterPatients()" class="cursor-pointer">
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
                        <span>{{ patientsLoading ? 'Načítavam pacientov...' : 'Pacienti neboli nájdení' }}</span>
                    </div>
                </template>
            </Select>

            <!-- User name -->
            <span v-if="user"
                class="h-7 flex items-center rounded-md bg-tag2 text-lightgrey px-3 text-normal whitespace-pre-line">
                {{ fullName }}
            </span>

            <!-- Branch select (hidden for superadmin) -->
            <Select v-if="!authStore.isSuperadmin" v-model="selectedBranchId" :options="branchOptions"
                optionLabel="label" optionValue="id" dropdownIcon="bi bi-chevron-down text-white!"
                class="w-60 h-7! flex items-center bg-tag2! border-none! text-normal! text-white!" :showClear="false"
                :editable="false" :placeholder="branchOptions?.length ? undefined : 'Načítavam pobočky...'" @change="async (e) => {
                    await handleBranchChange(e)
                }">
                <template #value>
                    <span class="text-normal text-white">{{branchOptions?.find((b: any) => b.id ===
                        selectedBranchId)?.label}}</span>
                </template>
            </Select>

            <!-- Company name -->
            <span v-if="companyName" class="h-7 flex items-center rounded-md bg-tag2 text-lightgrey px-3 text-normal">
                {{ companyName }}
            </span>

            <Button icon="bi bi-box-arrow-right" text
                class="h-7! min-h-0! px-2! rounded-md! bg-white! text-darkgrey! flex items-center justify-center"
                @click="logout" />

            <Button :icon="props.isSidebarOpen ? 'bi bi-x-lg' : 'bi bi-list'" text
                class="h-7! min-h-0! px-2! rounded-md! bg-white! text-darkgrey! flex items-center justify-center"
                @click="toggleSidebar" />
        </div>
    </nav>
</template>
