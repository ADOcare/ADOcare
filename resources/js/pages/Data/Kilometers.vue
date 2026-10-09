<script setup lang="ts">
import { ref, computed, markRaw, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import type { InsuranceCompany } from '@/types/models'
import { useAuthStore } from '@/stores/auth'
import { useUiOverlayStore } from '@/stores/uiOverlay'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'
import useEmailDocumentsDialog from '@/composables/useEmailDocumentsDialog'
import type { DataTableOptions } from '@/types/datatable'
import useModal from '@/composables/useModal'
import KilometersBatchPreviewModal from './KilometersBatchPreviewModal.vue'

const authStore = useAuthStore()
const toast = useToast()
const { openEmailDocumentsDialog } = useEmailDocumentsDialog()
const branchId = computed(() => authStore.currentBranch?.id ?? null)
const uiOverlayStore = useUiOverlayStore()
const router = useRouter()
const { openModal } = useModal()
const busy = ref(false)
let formVersion = 0

const ROUTES_TOAST_GROUP = 'kilometers-routes-toast'

type BatchType = {
    code: string
    name: string
}

type Insurance = {
    id: number
    code: string | null
    name: string
}

type DocRow = {
    id: number
    name: string
    type: string
    subtype?: 'N' | 'O' | string
    period?: string
    created_at?: string
    updated_at?: string
    insurance_company_name?: string
}

const batchType = ref<BatchType | null>(null)
const insurance = ref<Insurance | null>(null)

const now = new Date()
const dates = ref<Date | null>(new Date(now.getFullYear(), now.getMonth() - 1, 1))

const submitted = ref(false)

const batchTypes = ref<BatchType[]>([
    { code: 'N', name: 'Nová dávka' },
    { code: 'O', name: 'Opravná dávka' },
    { code: 'A', name: 'Aditívna dávka' },
    { code: 'E', name: 'Nová dávka – poistenci EÚ' },
    { code: 'F', name: 'Opravná dávka – poistenci EÚ' },
    { code: 'G', name: 'Aditívna dávka – poistenci EÚ' },
    { code: 'I', name: 'Nová dávka - osobitný režim' },
    { code: 'J', name: 'Opravná dávka – osobitný režim' },
    { code: 'K', name: 'Aditívna dávka – osobitný režim' },
])

const insurances = ref<Insurance[]>([])

function mapInsuranceCompanyToOption(company: InsuranceCompany): Insurance {
    const displayName = company.name ?? ''

    return {
        id: company.id,
        code: company.code,
        name: displayName ? `${displayName}` : displayName || `#${company.id}`,
    }
}

async function loadInsurances() {
    try {
        const res = await api.get('/v1/insurance-companies', {
            params: { paginate: 0 },
        })

        const payload = res.data?.data

        const items =
            (payload?.items as InsuranceCompany[] | undefined) ??
            (Array.isArray(payload) ? (payload as InsuranceCompany[]) : []) ??
            []

        insurances.value = items.map(mapInsuranceCompanyToOption)
    } catch (e) {
        console.error('Failed to load insurance companies', e)
        insurances.value = []
    }
}

function showRoutesGeneratedToast() {
    toast.add({
        group: ROUTES_TOAST_GROUP,
        severity: 'success',
        summary: 'Úspech',
        detail: 'Cestovný príkaz a Denný záznam ciest boli úspešne vygenerované.',
        life: 10000,
    })
}

const formatLocalDate = (date: Date) => {
    const y = date.getFullYear()
    const m = String(date.getMonth() + 1).padStart(2, '0')
    const d = String(date.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
}

async function onSubmit() {
    if (busy.value) return
    submitted.value = true
    if (!batchType.value || !insurance.value || !dates.value) return

    busy.value = true
    const version = formVersion
    const monthDate = dates.value
    const periodFrom = formatLocalDate(new Date(monthDate.getFullYear(), monthDate.getMonth(), 1))
    const periodTo = formatLocalDate(new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0))
    const type = batchType.value.code
    const insuranceId = insurance.value.id

    try {
        uiOverlayStore.setContentLoading(true)
        await authStore.waitUntilInitialized()
        if (version !== formVersion) return
        const selectedBranchId = authStore.currentBranch?.id
        if (!selectedBranchId) throw new Error('Vyberte aktuálnu prevádzku.')
        const request = {
            batchType: { code: type },
            insurance: { id: insuranceId },
            branch: { id: selectedBranchId },
            period: [periodFrom, periodTo],
        }
        const response = await api.post('/v1/batches/kilometers/preview', {
            ...request,
            candidatesOnly: true,
        })
        if (version !== formVersion) return
        const preview = response.data?.data
        if (!preview || !Array.isArray(preview.candidates) || !preview.previewToken) {
            throw new Error('Nepodarilo sa načítať náhľad dopravnej dávky.')
        }
        uiOverlayStore.setContentLoading(false)
        const result = await openModal(markRaw(KilometersBatchPreviewModal), {
            candidates: preview.candidates,
            initialSelectedJourneyIds: preview.candidates.filter((row: any) => row.suggested).map((row: any) => row.journey_id),
            blocked: preview.blocked ?? [],
            routeChanges: preview.route_changes ?? [],
            canCreate: preview.can_create,
            batchType: type,
            comparisonBasis: preview.comparison_basis,
        }, {
            header: 'Náhľad dát dopravnej dávky',
            style: { width: '90vw', maxWidth: '1440px' },
            closable: true,
        })
        if (!result?.journeyIds?.length) return
        if (version !== formVersion || authStore.currentBranch?.id !== selectedBranchId) {
            throw new Error('Výber prevádzky alebo filtrov sa zmenil. Otvorte náhľad znova.')
        }
        uiOverlayStore.setContentLoading(true)
        const saved = await api.post('/v1/kilometers-batches', {
            ...request,
            car_id: preview.car.id,
            journeyIds: result.journeyIds,
            previewToken: preview.previewToken,
            correction: result.correction,
        })
        const documentId = saved.data?.data?.document_id
        if (!documentId) throw new Error('Server nevrátil číslo vytvoreného dokumentu.')

        // Corrections/additions must not overwrite the original travel journal.
        if (['N', 'E', 'I'].includes(type)) {
            void Promise.allSettled([
                api.post('/v1/cps', { start: periodFrom, end: periodTo, branch_id: selectedBranchId }),
                api.post('/v1/dzcs', { start: periodFrom, end: periodTo, branch_id: selectedBranchId }),
            ]).then((results) => {
                if (results.every((result) => result.status === 'fulfilled')) {
                    showRoutesGeneratedToast()
                } else {
                    toast.add({ severity: 'warn', summary: 'Dávka uložená',
                        detail: 'Dopravná dávka je uložená, ale CP alebo denný záznam ciest sa nepodarilo vytvoriť.', life: 8000 })
                }
            })
        }
        await router.push({ name: 'documents-kilometers-show', params: { documentId } })
    } catch (error: any) {
        const body = error?.response?.data
        const messages = body?.errors && typeof body.errors === 'object'
            ? Object.values(body.errors).flat().map(String) : []
        const errors = messages.length ? messages : [body?.message ?? error?.message ?? 'Nepodarilo sa vytvoriť dopravnú dávku.']
        errors.slice(0, 8).forEach((message) => toast.add({
            severity: 'error', summary: 'Dopravná dávka', detail: message, life: 15000,
        }))
    } finally {
        busy.value = false
        uiOverlayStore.setContentLoading(false)
    }
}

watch([branchId, () => batchType.value?.code, () => insurance.value?.id, dates], () => {
    formVersion++
})
onBeforeUnmount(() => {
    formVersion++
    uiOverlayStore.setContentLoading(false)
})

onMounted(() => {
    loadInsurances()
})

const formatSubtype = (code?: string) => batchTypes.value.find((item) => item.code === code)?.name ?? code ?? ''

const formatDateWithTime = (dateStr?: string) => {
    if (!dateStr) {
        return ''
    }

    const date = new Date(dateStr)
    const datePart = date.toLocaleDateString('sk-SK')
    const timePart = date.toLocaleTimeString('sk-SK', {
        hour: '2-digit',
        minute: '2-digit',
    })

    return `${datePart} ${timePart}`
}

const openKilometersDoc = (doc: DocRow) => {
    const url = router.resolve(`/documents/kilometers/${doc.id}`).href
    window.open(url, '_blank', 'noopener,noreferrer')
}

const options = computed<DataTableOptions<DocRow>>(() => ({
    rowKey: 'id',
    endpointUrl: 'v1/kilometers-batches',
    extraParams: {
        ...(branchId.value ? { branch_id: branchId.value } : {}),
    },
    dateRangeFilter: {
        mode: 'single',
        param: 'period',
        view: 'month',
        dateFormat: 'mm/yy',
        value: dates.value,
    },
    defaultPageSize: 25,
    pageSizeOptions: [10, 25, 50],
    selectable: true,

    columns: [
        {
            field: 'name',
            header: 'Číslo dávky',
            sortable: true,
            render: (v?: string) => {
                if (!v) {
                    return ''
                }

                const parts = v.split('_')
                return parts[3] ?? ''
            },
        },
        {
            field: 'insurance_company_name',
            header: 'Poisťovňa',
            sortable: true,
            render: (v?: string) => {
                if (!v) {
                    return ''
                }

                return v.trim().split(/\s+/)[0] ?? ''
            },
        },
        {
            field: 'subtype',
            header: 'Druh dávky',
            sortable: true,
            render: (v?: string) => formatSubtype(v),
        },
        {
            field: 'period',
            header: 'Obdobie',
            sortable: true,
        },
        {
            field: 'updated_at',
            header: 'Naposledy upravené',
            sortable: true,
            render: (v?: string) => formatDateWithTime(v),
        },
        {
            field: 'preview',
            header: '',
            width: '3rem',
            component: ActionButtons,
            componentOptions: [
                {
                    icon: 'bi bi-eye',
                    color: 'info',
                    tooltip: 'Zobraziť',
                    action: (row: DocRow) => openKilometersDoc(row),
                },
            ],
        },
    ],

    actions: [
        {
            key: 'email',
            disabled: ({ selectedRows }: { selectedRows: DocRow[] }) => selectedRows.length === 0,
            icon: 'bi bi-send',
            class: 'bg-accent!',
            tooltip: 'Poslať vybrané dokumenty emailom',
            handler: async ({ selectedRows, remote }: { selectedRows: DocRow[]; remote: any }) => {
                await openEmailDocumentsDialog({
                    documents: selectedRows,
                    remote,
                })
            },
        },
        {
            key: 'delete',
            disabled: ({ selectedRows }: { selectedRows: DocRow[] }) => selectedRows.length === 0,
            icon: 'bi bi-eraser',
            class: 'bg-danger!',
            confirm: 'Naozaj chcete zmazať vybrané dokumenty?',
            handler: async ({ selectedRows, remote }: { selectedRows: DocRow[]; remote: any }) => {
                await api.delete('/v1/documents', {
                    data: {
                        ids: selectedRows.map((r) => r.id),
                    },
                })

                await remote.loadPage(remote.page)
            },
        },
    ],
}))
</script>

<template>
    <div class="flex flex-col gap-6">
        <form @submit.prevent="onSubmit" class="flex flex-col gap-4">
            <section class="bg-tag3 p-6 rounded-md flex flex-col gap-4">
                <div class="grid grid-cols-12 gap-4">
                    <div class="col-span-12 md:col-span-4">
                        <label class="block text-normal mb-1">Typ dávky</label>
                        <Select
                            v-model="batchType"
                            :disabled="busy"
                            :options="batchTypes"
                            optionLabel="name"
                            fluid
                            class="w-full! border-none! shadow-none! bg-white! focus:ring-0! focus:shadow-none!"
                        />
                        <small v-if="submitted && !batchType" class="text-danger">
                            Typ dávky je povinný.
                        </small>
                    </div>

                    <div class="col-span-12 md:col-span-4">
                        <label class="block text-normal mb-1">Poisťovňa</label>
                        <Select
                            v-model="insurance"
                            :disabled="busy"
                            :options="insurances"
                            optionLabel="name"
                            fluid
                            class="w-full! border-none! shadow-none! bg-white! focus:ring-0! focus:shadow-none!"
                        />
                        <small v-if="submitted && !insurance" class="text-danger">
                            Poisťovňa je povinná.
                        </small>
                    </div>

                    <div class="col-span-12 md:col-span-4">
                        <label class="block text-normal mb-1">Obdobie</label>
                        <DatePicker
                            v-model="dates"
                            :disabled="busy"
                            view="month"
                            dateFormat="MM yy"
                            :manualInput="false"
                            inputClass="w-full! border-none! shadow-none! bg-white! focus:ring-0! focus:shadow-none!"
                            fluid
                        />

                        <small v-if="submitted && !dates" class="text-danger">
                            Obdobie je povinné.
                        </small>
                    </div>

                </div>
            </section>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :disabled="busy"
                    :loading="busy"
                    class="bg-accent! border-0! hover:bg-darkgrey! px-4! rounded-md! text-white! text-normal! h-7!"
                >
                    Zobraziť náhľad dávky
                </Button>
            </div>
        </form>

        <section>
            <UniversalDataTable :options="options" />
        </section>
    </div>
</template>
