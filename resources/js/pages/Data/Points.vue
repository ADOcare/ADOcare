<script setup lang="ts">
import { ref, computed, markRaw, onMounted, watch, watchEffect, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import { toApiDate } from '@/utils/dateUtils'
import type { InsuranceCompany } from '@/types/models'
import { useAuthStore } from '@/stores/auth'
import { useUiOverlayStore } from '@/stores/uiOverlay'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'
import useEmailDocumentsDialog from '@/composables/useEmailDocumentsDialog'
import useModal from '@/composables/useModal'
import PointsBatchPreviewModal from './PointsBatchPreviewModal.vue'
import type { DataTableOptions } from '@/types/datatable'

const authStore = useAuthStore()
const uiOverlayStore = useUiOverlayStore()
const toast = useToast()
const { openEmailDocumentsDialog } = useEmailDocumentsDialog()
const { openModal } = useModal()
const router = useRouter()

const branchId = computed(() => authStore.currentBranch?.id ?? null)
const TIMELINE_CALC_TOAST_GROUP = 'timeline-calculation-toast'

type BatchType = {
    code: string
    name: string
}

type Insurance = {
    id: number
    code: string | null
    name: string
}

type PointCandidate = {
    point_id: number
    previous_claim_line_id: number | null
    patient_id: number
    patient_name: string
    personal_number: string
    service_date: string
    created_at: string
    updated_at: string
    procedure_code: string
    diagnosis_code: string
    quantity: number
    unit_price: number
    amount: number
    regime: string
    special_category: string | null
    edited: boolean
    added_after_new_batch: boolean
    suggested: boolean
    reasons: string[]
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

const candidates = ref<PointCandidate[]>([])
const selectedPointIds = ref<number[]>([])

const submitted = ref(false)
const loading = ref(false)
const candidatesLoading = ref(false)

watchEffect(() => {
    uiOverlayStore.setContentLoading(loading.value)
})

onBeforeUnmount(() => {
    uiOverlayStore.setContentLoading(false)
})

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

async function pollCalculationStatus(periodFrom: Date) {
    const maxAttempts = 120
    let attempts = 0

    const monthStr = toApiDate(periodFrom)
    const branchId = authStore.currentBranch?.id
    const userId = authStore.user?.id

    await new Promise((resolve) => setTimeout(resolve, 500))

    const interval = setInterval(async () => {
        attempts++

        try {
            const res = await api.get('/v1/visits/timeline/status', {
                params: {
                    month: monthStr,
                    branch_id: branchId,
                    user_id: userId,
                },
            })

            const status = res.data?.data?.status

            if (status === 'completed') {
                clearInterval(interval)
                toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
                toast.add({
                    severity: 'success',
                    summary: 'Výpočet dokončený',
                    detail: 'Časová os návštev bola úspešne vypočítaná.',
                    life: 5000,
                })
            } else if (status === 'failed') {
                clearInterval(interval)
                toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)

                const errorMsg = res.data?.data?.error_message || 'Neznáma chyba'

                toast.add({
                    severity: 'error',
                    summary: 'Chyba výpočtu',
                    detail: errorMsg,
                    life: 5000,
                })
            } else if (attempts >= maxAttempts) {
                clearInterval(interval)
                toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
                toast.add({
                    severity: 'warn',
                    summary: 'Časový limit',
                    detail: 'Výpočet trvá dlhšie ako obvykle. Pokračuje na pozadí.',
                    life: 5000,
                })
            }
        } catch (error) {
            console.error('Failed to check calculation status:', error)

            if (attempts >= maxAttempts) {
                clearInterval(interval)
                toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
            }
        }
    }, 5000)
}

function selectedPeriod() {
    if (!dates.value) {
        return null
    }

    const monthDate = dates.value
    const year = monthDate.getFullYear()
    const month = monthDate.getMonth()
    const periodFrom = new Date(year, month, 1)
    const periodTo = new Date(year, month + 1, 0)

    return {
        periodFrom,
        periodFromApi: toApiDate(periodFrom),
        periodToApi: toApiDate(periodTo),
    }
}

async function loadCandidates(): Promise<boolean> {
    const period = selectedPeriod()

    if (!period || !batchType.value || !insurance.value || !branchId.value) {
        return false
    }

    candidatesLoading.value = true

    try {
        const response = await api.post('/v1/batches/points/candidates', {
            batch_type: batchType.value.code,
            insurance_company_id: insurance.value.id,
            branch_id: branchId.value,
            period: [period.periodFromApi, period.periodToApi],
        })

        const data = response.data?.data
        const existingDocumentId = Number(data?.existing_batch?.document_id ?? 0)
        const canRebuildExistingBatch = data?.existing_batch?.can_rebuild === true

        if (data?.existing_batch && !canRebuildExistingBatch) {
            toast.add({
                severity: 'info',
                summary: 'Dávka už existuje',
                detail: existingDocumentId > 0
                    ? 'Dávka už bola exportovaná. Opravy výkonov vytvorte cez opravnú dávku.'
                    : 'Nová dávka pre zvolené obdobie už bola vytvorená a exportovaná.',
                life: 5000,
            })
            if (existingDocumentId > 0) {
                await router.push(`/documents/points/${existingDocumentId}`)
            }
            return false
        }

        if (canRebuildExistingBatch) {
            toast.add({
                severity: 'info',
                summary: 'Dávka bude nahradená',
                detail: 'Pôvodná nová dávka sa vymaže a vytvorí sa nanovo z aktuálneho bodovania.',
                life: 5000,
            })
        }

        candidates.value = Array.isArray(data?.candidates) ? data.candidates : []
        selectedPointIds.value = candidates.value
            .filter((point) => point.suggested)
            .map((point) => point.point_id)

        if (candidates.value.length === 0) {
            toast.add({
                severity: 'warn',
                summary: 'Žiadne výkony',
                detail: 'Pre zvolené obdobie, poisťovňu a charakter dávky sa nenašli dostupné výkony.',
                life: 7000,
            })
        }

        return true
    } catch (error: any) {
        const validationErrors = error?.response?.data?.errors
        const firstValidationMessage = validationErrors && typeof validationErrors === 'object'
            ? Object.values(validationErrors).flat().map(String)[0]
            : null

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: firstValidationMessage
                ?? error?.response?.data?.message
                ?? 'Nepodarilo sa načítať výkony pre dávku.',
            life: 7000,
        })
        return false
    } finally {
        candidatesLoading.value = false
    }
}

async function onSubmit() {
    submitted.value = true

    const hasPeriod = !!dates.value

    if (
        !batchType.value ||
        !insurance.value ||
        !hasPeriod
    ) {
        return
    }

    const period = selectedPeriod()
    if (!period) {
        return
    }

    const loaded = await loadCandidates()

    if (!loaded) {
        return
    }

    const modalResult = await openModal(
        markRaw(PointsBatchPreviewModal),
        {
            candidates: candidates.value,
            initialSelectedPointIds: selectedPointIds.value,
        },
        {
            header: 'Náhľad dát dávky',
            style: { width: '90vw', maxWidth: '1280px' },
            closable: true,
        },
    )

    if (!modalResult?.pointIds?.length) {
        return
    }

    selectedPointIds.value = modalResult.pointIds

    loading.value = true

    try {
        const res = await api.post('/v1/batches/points/preview', {
            batchType: { code: batchType.value.code },
            insurance: { id: insurance.value.id },
            period: [period.periodFromApi, period.periodToApi],
            branch: { id: authStore.currentBranch?.id },
            pointIds: selectedPointIds.value,
        })

        const sheet = res.data?.data?.sheet

        if (!sheet) {
            console.error('Missing sheet in response:', res.data)
            return
        }

        api.post('/v1/visits/timeline', {
            month: period.periodFromApi,
            branch_id: authStore.currentBranch?.id,
            user_id: authStore.user?.id,
            persist: true,
        })
            .then(() => {
                toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
                toast.add({
                    group: TIMELINE_CALC_TOAST_GROUP,
                    severity: 'info',
                    summary: 'Prebieha výpočet časovej osi.',
                    detail: 'Generovanie dopravných dávok a dekurzov pacientov je počas výpočtu nedostupné, keďže závisí od jeho výsledku.',
                    life: 0,
                    closable: false,
                })

                pollCalculationStatus(period.periodFrom)
            })
            .catch((error) => {
                console.error('Background calculation failed:', error)
                toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
                toast.add({
                    severity: 'warn',
                    summary: 'Upozornenie',
                    detail: 'Výpočet časovej osi návštev nebol spustený.',
                    life: 5000,
                })
            })

        await router.push({
            path: '/documents/points',
            query: {
                batchNumber: sheet.batchNumber,
                fileName: sheet.fileName,
                amount: sheet.amount,
                periodFrom: sheet.periodFrom,
                periodTo: sheet.periodTo,
                performedBy: sheet.performedBy,
                performedDate: sheet.performedDate,
                companyName: sheet.companyName,
                branchName: sheet.branchName,
                insuranceId: insurance.value.id,
                batchTypeCode: batchType.value.code,
                period0: period.periodFromApi,
                period1: period.periodToApi,
                insuranceName: insurance.value.name,
                pointIds: JSON.stringify(selectedPointIds.value),
            },
        })
    } catch (error: any) {
        console.error('Preview or navigation failed', error)

        const errorData = error?.response?.data
        const validationErrors = errorData?.errors

        const messages = Array.isArray(validationErrors?.points_export)
            ? validationErrors.points_export
            : validationErrors && typeof validationErrors === 'object'
                ? Object.values(validationErrors).flat()
                : []

        if (messages.length > 0) {
            messages.slice(0, 8).forEach((message: string) => {
                toast.add({
                    severity: 'error',
                    summary: 'Chýbajúce údaje',
                    detail: message,
                    life: 8000,
                })
            })

            if (messages.length > 8) {
                toast.add({
                    severity: 'warn',
                    summary: 'Ďalšie chyby',
                    detail: `Našlo sa ešte ${messages.length - 8} ďalších chýb. Skontrolujte údaje pacientov, lekárov, prevádzky a poisťovne.`,
                    life: 8000,
                })
            }

            return
        }

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: errorData?.message ?? 'Nepodarilo sa vygenerovať dávku bodov.',
            life: 5000,
        })
    } finally {
        loading.value = false
    }
}

watch(
    [branchId, () => batchType.value?.code, () => insurance.value?.id, dates],
    () => {
        candidates.value = []
        selectedPointIds.value = []
    },
)

onMounted(() => {
    loadInsurances()
})

const batchTypeLabelByCode = computed<Record<string, string>>(() => {
    const map: Record<string, string> = {}

    for (const t of batchTypes.value) {
        map[t.code] = t.name
    }

    return map
})

const formatSubtype = (code?: string) => {
    if (!code) {
        return ''
    }

    return batchTypeLabelByCode.value[code] ?? code
}

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

const openPointsDoc = (doc: DocRow) => {
    const url = router.resolve(`/documents/points/${doc.id}`).href
    window.open(url, '_blank', 'noopener,noreferrer')
}

const options = computed<DataTableOptions<DocRow>>(() => ({
    rowKey: 'id',
    endpointUrl: 'v1/points-batches',
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
                    action: (row: DocRow) => openPointsDoc(row),
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
    <div class="flex flex-col gap-6 relative">
        <form @submit.prevent="onSubmit" class="flex flex-col gap-4">
            <section class="bg-tag3 p-6 rounded-md flex flex-col gap-4">
                <div class="grid grid-cols-12 gap-4">
                    <div class="col-span-12 md:col-span-4">
                        <label class="block text-normal mb-1">Typ dávky</label>
                        <Select
                            v-model="batchType"
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
                    :loading="candidatesLoading || loading"
                    :disabled="candidatesLoading || loading"
                    class="bg-accent! border-0! hover:bg-darkgrey! px-4! rounded-md! text-white! text-normal! h-7!"
                >
                    Náhľad dát
                </Button>
            </div>
        </form>

        <section>
            <UniversalDataTable :options="options" />
        </section>
    </div>
</template>
