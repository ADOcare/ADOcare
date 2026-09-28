<template>
    <div class="h-full flex flex-col min-h-0 max-h-full overflow-auto">
        <UniversalDataTable
            v-if="endpointUrl"
            :key="tableKey"
            :options="options"
            ref="tableEl"
        >
            <template #toolbar-extra>
                <div
                    v-if="authStore.isManager && !showDeleted && !showDead"
                    class="flex items-center gap-2"
                >
                    <Select
                        v-model="selectedNurseId"
                        :options="availableNurses"
                        option-value="id"
                        option-label="display_name"
                        :placeholder="availableNurses.length === 0 ? 'Načítavam sestry...' : 'Všetky sestry'"
                        :disabled="availableNurses.length === 0"
                        class="w-64"
                        dropdown-icon="bi bi-chevron-down"
                        :show-clear="true"
                        clear-icon="bi bi-x-lg"
                    >
                        <template #option="slotProps">
                            <span v-if="slotProps.option">
                                {{ formatUserFullName(slotProps.option) }}
                            </span>
                        </template>

                        <template #value="slotProps">
                            <span v-if="slotProps.value !== null && slotProps.value !== undefined">
                                {{
                                    availableNurses.find((n) => n.id === slotProps.value)
                                        ? formatUserFullName(
                                            availableNurses.find((n) => n.id === slotProps.value)!
                                        )
                                        : ''
                                }}
                            </span>
                            <span v-else class="text-gray-400">Všetky sestry</span>
                        </template>
                    </Select>
                </div>
            </template>
        </UniversalDataTable>
    </div>
</template>

<script setup lang="ts">
import { computed, markRaw, ref, watch } from 'vue'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import type { Doctor, Patient, User } from '@/types/models'
import { useAuthStore } from '@/stores/auth'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'
import { usePatientStore } from '@/stores/patientStore'
import router from '@/router'
import DeleteConfirmationForm from '@/pages/Patients/partials/form/DeleteConfirmationForm.vue'
import CreatePatientForm from '@/pages/Patients/partials/form/CreatePatientForm.vue'
import type { DataTableOptions, RemoteTableReturn } from '@/types/datatable'
import useModal from '@/composables/useModal'
import { openPatientDocumentsModal, openPatientEditModal } from '@/helpers/modalHelpers'
import { formatBranchFullName, formatUserFullName } from '@/utils/formatUtils'
import api from '@/services/api'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'

interface Props {
    endpointUrl: string
}

const props = defineProps<Props>()
const endpointUrl = computed(() => props.endpointUrl)

const patientStore = usePatientStore()
const { openModal } = useModal()
const toast = useToast()
const authStore = useAuthStore()
const canDeletePatients = computed(() => !!authStore.currentRole)
const branchId = computed(() => authStore.currentBranch?.id ?? null)
const actionRemote = ref<RemoteTableReturn>({} as RemoteTableReturn)

const selectedNurseId = ref<number | null>(null)

type PatientViewMode = 'active' | 'deleted' | 'dead'
const viewMode = ref<PatientViewMode>('active')

const tableKey = computed(() => `patients-${branchId.value ?? 'none'}-${viewMode.value}`)

const showDeleted = computed(() => viewMode.value === 'deleted')
const showDead = computed(() => viewMode.value === 'dead')

const availableNurses = computed(() => {
    if (!authStore.isManager) {
        return []
    }

    const remoteItems = actionRemote.value?.items as any
    const items = Array.isArray(remoteItems) ? remoteItems : remoteItems?.value

    if (!items || !Array.isArray(items) || items.length === 0) {
        return []
    }

    const uniqueNurses = new Map<number, User & { display_name?: string }>()

    items.forEach((patient: any) => {
        if (patient?.nurse?.id) {
            uniqueNurses.set(patient.nurse.id, {
                ...patient.nurse,
                display_name: formatUserFullName(patient.nurse),
            })
        }
    })

    return Array.from(uniqueNurses.values()).sort((a, b) =>
        (a.display_name || '').localeCompare(b.display_name || '')
    )
})

watch(selectedNurseId, async (newValue) => {
    if (!authStore.isManager || !actionRemote.value) {
        return
    }

    if (actionRemote.value.setExtraParam) {
        if (newValue !== null && newValue !== undefined) {
            actionRemote.value.setExtraParam('filter[nurse_id]', newValue)
        } else {
            actionRemote.value.setExtraParam('filter[nurse_id]', undefined)
        }
    }

    await actionRemote.value.loadPage?.(1)
})

watch(viewMode, () => {
    selectedNurseId.value = null
})

interface PatientsPrintPayload {
    mode: 'selected' | 'filtered'
    selectedPatients?: Patient[]
    endpointUrl?: string
    params?: Record<string, any>
}

function openPatientsPrintPreview(payload: PatientsPrintPayload) {
    sessionStorage.setItem('patients-print-payload', JSON.stringify(payload))
    void router.push({ name: 'manager-overview-patients-print' })
}

function printSelectedPatients(patients: Patient[]) {
    if (!patients.length) return

    openPatientsPrintPreview({
        mode: 'selected',
        selectedPatients: patients,
    })
}

function openPatientDocuments(patientId: number) {
    void openPatientDocumentsModal(patientId)
}

async function openEditPatient(patientId: number) {
    await openPatientEditModal(patientId)
    actionRemote.value?.reload()
}

async function restorePatients(rows: Patient[], remote?: RemoteTableReturn) {
    const ids = rows.filter((row) => row.deleted_at).map((row) => row.id)
    if (!ids.length) return

    try {
        await api.post('/v1/patients/restore', { ids })
        toast.add({
            severity: 'success',
            summary: 'Pacienti boli obnovení',
            detail: 'Vybraní pacienti boli úspešne obnovení.',
            life: 4000,
        })
        remote?.reload?.()
    } catch (error: any) {
        const personalNumberError = error?.response?.data?.errors?.personal_number?.[0]

        toast.add({
            severity: 'error',
            summary: personalNumberError ? 'Pacienta nemožno obnoviť' : 'Obnovenie sa nepodarilo',
            detail: personalNumberError
                ?? error?.response?.data?.message
                ?? 'Vybraných pacientov sa nepodarilo obnoviť.',
            life: personalNumberError ? 10000 : 6000,
        })
    }
}

async function deletePatients(rows: Patient[], remote?: RemoteTableReturn) {
    await openModal(
        markRaw(DeleteConfirmationForm),
        {
            title: 'Vymazať pacientov',
            selectedRows: rows,
            remote,
        },
        {
            style: { width: '60%' },
        },
    )
}

const options = computed<DataTableOptions<Patient>>(() => {
    const baseColumns: DataTableOptions<Patient>['columns'] = [
        {
            field: 'first_name',
            header: 'Meno',
            sortable: true,
            render: (v, row) => {
                if (!row) return v
                if (row.deleted_at) return `<s>${v}</s>`
                if (row.death_date) return `${v} †`
                return v
            },
        },
        {
            field: 'last_name',
            header: 'Priezvisko',
            sortable: true,
            render: (v, row) => {
                if (!row) return v
                if (row.deleted_at) return `<s>${v}</s>`
                return v
            },
        },
        {
            field: 'personal_number',
            header: 'Rodné číslo',
            sortable: true,
            render: (v) => v,
        },
        {
            field: 'doctor',
            header: 'Ošetrujúci lekár',
            render: (v: Doctor) => (v ? `${v.title} ${v.first_name} ${v.last_name}` : ''),
            sortable: false,
        },
    ]

    if (showDead.value) {
        baseColumns.splice(3, 0, {
            field: 'death_date',
            header: 'Dátum úmrtia',
            sortable: true,
            render: (v) => (v ? new Date(v).toLocaleDateString('sk-SK') : ''),
        })
    }

    if (showDeleted.value) {
        baseColumns.splice(3, 0, {
            field: 'deleted_at',
            header: 'Dátum zmazania',
            sortable: true,
            render: (v) => (v ? new Date(v).toLocaleDateString('sk-SK') : ''),
        })
    }

    if (!showDeleted.value && !showDead.value) {
        baseColumns.splice(
            3,
            0,
            {
                field: 'adress',
                header: 'Adresa',
                render: (_v, row) => {
                    if (!row) return ''

                    const parts: string[] = []
                    if (row.address) parts.push(row.address)

                    const address = parts.join(', ') || ''
                    return address.length > 50 ? address.substring(0, 40) + '...' : address
                },
            },
            {
                field: 'city',
                header: 'Mesto',
                sortable: true,
            },
        )
    }

    baseColumns.push({
        field: 'documents',
        header: '',
        width: '3rem',
        component: ActionButtons,
        componentOptions: [
            {
                icon: 'bi bi-folder',
                color: 'darkgrey',
                tooltip: 'Zobraziť dokumenty',
                action: (row: Patient) => {
                    openPatientDocuments(row.id)
                },
            },
        ],
    })

    if (!showDeleted.value) {
        baseColumns.push({
            field: 'pin',
            header: '',
            width: '3rem',
            component: ActionButtons,
            componentOptions: [
                {
                    icon: (row: Patient) =>
                        patientStore.current?.id === row.id ? 'bi bi-pin-fill' : 'bi bi-pin',
                    color: 'darkgrey',
                    tooltip: 'Pripnúť pacienta',
                    action: (row: Patient) => {
                        patientStore.setPatient(row)
                        router.push('/patient/points')
                    },
                },
            ],
        })
    }

    if (!showDeleted.value && !showDead.value) {
        baseColumns.push({
            field: 'edit',
            header: '',
            width: '3rem',
            component: markRaw(ActionButtons),
            componentOptions: [
                {
                    icon: 'bi bi-pencil',
                    color: 'darkgrey',
                    tooltip: 'Editovať pacienta',
                    action: (row: Patient) => {
                        openEditPatient(row.id)
                    },
                },
            ],
        })
    }

    const tableOptions: DataTableOptions<Patient> = {
        rowKey: 'id',
        endpointUrl: endpointUrl.value || '',
        defaultPageSize: 25,
        pageSizeOptions: [10, 25, 50],
        selectable: true,
        afterInit: ({ remote }) => {
            actionRemote.value = remote

            if (authStore.isManager && selectedNurseId.value !== null && remote.setExtraParam) {
                remote.setExtraParam('filter[nurse_id]', selectedNurseId.value)
            }
        },
        extraParams: {
            ...(authStore.isManager ? { with: 'nurse,doctor,branch' } : { with: 'doctor' }),
            ...(showDeleted.value ? { only_deleted: 1 } : {}),
            ...(showDead.value ? { only_dead: 1 } : {}),
            ...(authStore.isManager && selectedNurseId.value !== null
                ? { 'filter[nurse_id]': selectedNurseId.value }
                : {}),
        },
        columns: baseColumns,
        actions: [
            {
                key: 'show-active',
                icon: 'bi bi-people',
                position: 'start',
                tooltip: 'Zobraziť aktívnych pacientov',
                class: viewMode.value === 'active'
                    ? '!bg-darkgrey !text-white !border !border-solid !border-darkgrey !focus:!border-darkgrey !active:!border-darkgrey !shadow-none focus:!shadow-none'
                    : '!border !border-solid !border-darkgrey !bg-white !text-darkgrey hover:!bg-darkgrey hover:!text-white hover:!border-darkgrey !focus:!border-darkgrey !active:!border-darkgrey !shadow-none focus:!shadow-none',
                handler: async ({ remote }) => {
                    viewMode.value = 'active'
                    remote.reload()
                },
                bordered: true,
            },
            {
                key: 'show-deleted',
                icon: 'bi bi-person-x',
                position: 'start',
                tooltip: 'Zobraziť zmazaných',
                class: viewMode.value === 'deleted'
                    ? '!bg-darkgrey !text-white !border !border-solid !border-darkgrey !focus:!border-darkgrey !active:!border-darkgrey !shadow-none focus:!shadow-none'
                    : '!border !border-solid !border-darkgrey !bg-white !text-darkgrey hover:!bg-darkgrey hover:!text-white hover:!border-darkgrey !focus:!border-darkgrey !active:!border-darkgrey !shadow-none focus:!shadow-none',
                handler: async ({ remote }) => {
                    viewMode.value = 'deleted'
                    remote.reload()
                },
                bordered: true,
            },
            {
                key: 'show-dead',
                icon: 'bi bi-person-exclamation',
                position: 'start',
                tooltip: 'Zobraziť zosulých',
                class: viewMode.value === 'dead'
                    ? '!bg-darkgrey !text-white !border !border-solid !border-darkgrey !focus:!border-darkgrey !active:!border-darkgrey !shadow-none focus:!shadow-none'
                    : '!border !border-solid !border-darkgrey !bg-white !text-darkgrey hover:!bg-darkgrey hover:!text-white hover:!border-darkgrey !focus:!border-darkgrey !active:!border-darkgrey !shadow-none focus:!shadow-none',
                handler: async ({ remote }) => {
                    viewMode.value = 'dead'
                    remote.reload()
                },
                bordered: true,
            },
        ],
    }

    if (!showDeleted.value && !showDead.value) {
        const activeActions: NonNullable<typeof tableOptions.actions> = [
            {
                key: 'add',
                icon: 'bi bi-plus-lg',
                class: 'bg-accent!',
                handler: async () => {
                    await openModal(
                        markRaw(CreatePatientForm),
                        {},
                        { header: 'Pridať Pacienta', style: { width: '90%' } },
                    )
                },
            },
            {
                key: 'print',
                icon: 'bi bi-printer',
                class: 'bg-accent!',
                disabled: ({ selectedRows }) => !selectedRows.length,
                handler: ({ selectedRows }) => {
                    printSelectedPatients(selectedRows as Patient[])
                },
            },
        ]

        if (canDeletePatients.value) {
            activeActions.splice(1, 0, {
                key: 'delete',
                icon: 'bi bi-eraser',
                class: 'bg-danger!',
                disabled: ({ selectedRows }) => !selectedRows.length,
                handler: async ({ selectedRows, remote }) => {
                    await deletePatients(selectedRows as Patient[], remote)
                },
            })
        }

        tableOptions.actions?.push(...activeActions)
    }

    if (showDeleted.value) {
        tableOptions.actions?.push({
            key: 'restore',
            icon: 'bi bi-arrow-counterclockwise',
            class: 'bg-accent! hover:bg-darkgrey! text-white!',
            disabled: ({ selectedRows }) => {
                const rows = (selectedRows || []) as Patient[]
                return !rows.length || rows.some((row) => !row.deleted_at)
            },
            handler: async ({ selectedRows, remote }) => {
                await restorePatients(selectedRows as Patient[], remote)
            },
        })
    }

    if (authStore.isManager) {
        tableOptions.actions = tableOptions.actions?.filter(
            (action) => !['add'].includes(action.key),
        )

        tableOptions.columns = tableOptions.columns?.filter(
            (col) => !['personal_number', 'adress', 'city', 'pin'].includes(col.field || ''),
        )

        const doctorIndex = tableOptions.columns.findIndex((col) => col.field === 'doctor')

        const newColumns = [
            {
                field: 'nurse',
                header: 'Sestra',
                render: (_v: any, row: Patient) => (row.nurse ? formatUserFullName(row.nurse) : ''),
                sortable: false,
            },
            {
                field: 'branch',
                header: 'Prevádzka',
                render: (_: any, row: Patient) => formatBranchFullName(row.branch),
                sortable: false,
            },
        ]

        tableOptions.columns.splice(doctorIndex + 1, 0, ...newColumns)
    }

    return tableOptions
})
</script>
