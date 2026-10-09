<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import AdonisButton from '@/components/AdonisButton.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import useAuthStore from '@/stores/auth'
import { useApi } from '@/composables/useApi'
import api from '@/services/api'
import type { DataTableOptions } from '@/types/datatable'

type RunStatus = 'pending' | 'processing' | 'completed' | 'completed_with_errors' | 'failed'
type ResultStatus = 'created' | 'existing' | 'skipped' | 'failed'

type ExportResult = {
    type: 'points' | 'kilometers' | 'dzc' | 'cp'
    label: string
    status: ResultStatus
    document_id?: number
    message?: string
    insurance_company?: string
    subtype?: string
}

type MonthlyExportRun = {
    id: number
    month: string
    branch_id: number
    status: RunStatus
    current_step: string | null
    results: ExportResult[]
    error_message: string | null
    started_at: string | null
    completed_at: string | null
}

type ApiResponse<T> = {
    success: boolean
    data: T
}

const ACTIVE_RUN_KEY = 'monthly_export_run_id'
const authStore = useAuthStore()
const router = useRouter()
const toast = useToast()
const { get, post } = useApi()

const previousMonth = new Date()
previousMonth.setMonth(previousMonth.getMonth() - 1, 1)
previousMonth.setHours(0, 0, 0, 0)

const selectedMonth = ref<Date>(previousMonth)
const run = ref<MonthlyExportRun | null>(null)
const starting = ref(false)
const loadingRun = ref(false)
const downloadingZip = ref(false)
let pollTimer: number | null = null

function activeRunKey() {
    return `${ACTIVE_RUN_KEY}:${authStore.currentBranch?.id ?? 'none'}`
}

const isRunning = computed(() => ['pending', 'processing'].includes(run.value?.status ?? ''))
const readyResults = computed(() => run.value?.results.filter((result) => result.document_id) ?? [])
const visibleResults = computed(() => run.value?.results.filter((result) => result.status !== 'skipped') ?? [])
const completedCount = computed(() => readyResults.value.length)
const loadingText = computed(() => {
    const step = run.value?.current_step ?? ''

    if (step.startsWith('Výkonová dávka')) return 'Výkonové dávky sa vytvárajú…'
    if (step.startsWith('Dopravná dávka')) return 'Dopravné dávky sa vytvárajú…'
    if (step.startsWith('Denný záznam ciest')) return 'Denný záznam ciest sa vytvára…'
    if (step.startsWith('Cestovný príkaz')) return 'Cestovný príkaz sa vytvára…'

    return 'Dokumenty mesačnej uzávierky sa pripravujú…'
})

const subtypeLabels: Record<string, string> = {
    N: 'Nová dávka',
    O: 'Opravná dávka',
    A: 'Aditívna dávka',

    E: 'Nová dávka – poistenci EÚ',
    F: 'Opravná dávka – poistenci EÚ',
    G: 'Aditívna dávka – poistenci EÚ',

    I: 'Nová dávka – osobitný režim',
    J: 'Opravná dávka – osobitný režim',
    K: 'Aditívna dávka – osobitný režim',
}

function monthValue(date: Date) {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    return `${year}-${month}`
}

function stopPolling() {
    if (pollTimer !== null) {
        window.clearTimeout(pollTimer)
        pollTimer = null
    }
}

function schedulePoll() {
    stopPolling()
    if (isRunning.value) {
        pollTimer = window.setTimeout(() => void loadRun(run.value!.id), 1500)
    }
}

function errorDetail(error: unknown) {
    const responseMessage = (error as any)?.response?.data?.message
    return responseMessage || (error as Error)?.message || 'Mesačný export sa nepodarilo spustiť.'
}

async function startRun() {
    const branchId = authStore.currentBranch?.id
    if (!branchId || !selectedMonth.value || starting.value) return

    starting.value = true
    stopPolling()

    const { data, error } = await post<ApiResponse<MonthlyExportRun>>('/monthly-exports', {
        month: monthValue(selectedMonth.value),
        branch_id: branchId,
    })

    starting.value = false

    if (error || !data?.data) {
        toast.add({
            severity: 'error',
            summary: 'Export sa nepodarilo spustiť',
            detail: errorDetail(error),
            life: 7000,
        })
        return
    }

    run.value = data.data
    localStorage.setItem(activeRunKey(), String(run.value.id))
    schedulePoll()
}

async function loadRun(id: number) {
    if (loadingRun.value) return
    loadingRun.value = true

    const { data, error } = await get<ApiResponse<MonthlyExportRun>>(`/monthly-exports/${id}`)
    loadingRun.value = false

    if (error || !data?.data) {
        localStorage.removeItem(activeRunKey())
        stopPolling()
        return
    }

    const previousStatus = run.value?.status
    run.value = data.data

    if (previousStatus && previousStatus !== run.value.status && !isRunning.value) {
        toast.add({
            severity: run.value.status === 'completed' ? 'success' : 'warn',
            summary: run.value.status === 'completed' ? 'Mesačný export je hotový' : 'Mesačný export skončil s upozorneniami',
            detail: `Pripravené dokumenty: ${completedCount.value}.`,
            life: 6000,
        })
    }

    schedulePoll()
}

function documentRoute(result: ExportResult) {
    if (!result.document_id) return null

    const routeName = {
        points: 'documents-points-show',
        kilometers: 'documents-kilometers-show',
        dzc: 'documents-dzc',
        cp: 'documents-cp',
    }[result.type]

    return router.resolve({ name: routeName, params: { documentId: result.document_id } }).href
}

function resultTitle(result: ExportResult) {
    return {
        points: 'Výkonová dávka',
        kilometers: 'Dopravná dávka',
        dzc: 'Denný záznam ciest',
        cp: 'Cestovný príkaz',
    }[result.type]
}

function legacyBatchMetadata(result: ExportResult) {
    if (!['points', 'kilometers'].includes(result.type)) return null

    const subtypes = [
        'tuzemskí poistenci',
        'poistenci EÚ',
        'osobitní poistenci',
    ]

    for (const subtype of subtypes) {
        const suffix = ` - ${subtype}`
        if (result.label.endsWith(suffix)) {
            return {
                insuranceCompany: result.label.slice(0, -suffix.length),
                subtype,
            }
        }
    }

    return null
}

function resultInsuranceCompany(result: ExportResult) {
    return result.insurance_company ?? legacyBatchMetadata(result)?.insuranceCompany ?? '-'
}

function resultSubtype(result: ExportResult) {
    if (result.subtype) {
        return subtypeLabels[result.subtype] ?? result.subtype
    }

    return legacyBatchMetadata(result)?.subtype ?? '-'
}

const documentTableOptions = computed<DataTableOptions<ExportResult>>(() => ({
    endpointUrl: '',
    localItems: visibleResults.value,
    hideSearch: false,
    hidePaginator: true,
    actions: [
        {
            key: 'Stiahnuť všetko',
            icon: 'bi bi-download',
            class: 'bg-accent! text-white!',
            disabled: () => readyResults.value.length === 0 || downloadingZip.value,
            handler: downloadZip,
        },
    ],
    columns: [
        {
            field: 'type',
            header: 'Typ dokumentu',
            render: (_value, result) => resultTitle(result),
        },
        {
            field: 'insurance_company',
            header: 'Poisťovňa',
            render: (_value, result) => resultInsuranceCompany(result),
            width: '20rem',
        },
        {
            field: 'subtype',
            header: 'Podtyp',
            render: (_value, result) => resultSubtype(result),
        },
        {
            header: '',
            slot: 'actions',
            width: '3.5rem',
        },
    ],
}))

async function downloadZip() {
    if (!run.value || readyResults.value.length === 0 || downloadingZip.value) return

    downloadingZip.value = true

    try {
        const response = await api.get(`/v1/monthly-exports/${run.value.id}/download`, {
            responseType: 'blob',
        })
        const blob = new Blob([response.data], { type: 'application/zip' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `mesacna_uzavierka_${run.value.month}.zip`
        link.click()
        setTimeout(() => URL.revokeObjectURL(url), 100)
    } catch (error: any) {
        let detail = 'ZIP archív sa nepodarilo stiahnuť.'
        if (error?.response?.data instanceof Blob) {
            try {
                const payload = JSON.parse(await error.response.data.text())
                detail = payload?.message ?? detail
            } catch {
                // Keep the generic message when the response is not JSON.
            }
        }

        toast.add({
            severity: 'error',
            summary: 'Sťahovanie zlyhalo',
            detail,
            life: 7000,
        })
    } finally {
        downloadingZip.value = false
    }
}

onMounted(() => {
    const activeRunId = Number(localStorage.getItem(activeRunKey()))
    if (activeRunId > 0) void loadRun(activeRunId)
})

onBeforeUnmount(stopPolling)
</script>

<template>
    <div class="relative flex min-h-[20rem] flex-col gap-6">
        <LoadingOverlay
            :show="starting || isRunning"
            :text="loadingText"
        />

        <!-- HEADER - ALWAYS THE SAME -->
        <form
            class="flex flex-col gap-4"
            @submit.prevent="startRun"
        >
            <section class="bg-tag3 p-6 rounded-md">
                <div class="grid grid-cols-12 gap-4 items-end">
                    <div class="col-span-12">
                        <label
                            for="monthly-export-period"
                            class="block text-normal mb-1"
                        >
                            Obdobie uzávierky
                        </label>

                        <DatePicker
                            input-id="monthly-export-period"
                            v-model="selectedMonth"
                            view="month"
                            date-format="MM yy"
                            :max-date="new Date()"
                            :manual-input="false"
                            :disabled="isRunning"
                            fluid
                            input-class="w-full! border-none! shadow-none! bg-white! focus:ring-0! focus:shadow-none!"
                        />
                    </div>
                </div>
            </section>

            <div class="flex justify-end">
                <AdonisButton
                    expanded
                    :label="'Spustiť'"
                    loading-label="Pripravujú sa dokumenty"
                    :loading="starting || isRunning"
                    :disabled="
                        !authStore.currentBranch
                            || !selectedMonth
                            || starting
                            || isRunning
                    "
                    @click="startRun"
                />
            </div>
        </form>

        <!-- ERROR -->
        <div
            v-if="run?.error_message"
            class="rounded-md bg-danger/10 px-4 py-3 text-danger"
        >
            {{ run.error_message }}
        </div>

        <!-- DOCUMENTS -->
        <div
            v-if="run && visibleResults.length > 0"
            class="flex flex-col"
        >
            <UniversalDataTable :options="documentTableOptions">
                <template #actions="{ row }">
                    <a
                        v-if="documentRoute(row)"
                        :href="documentRoute(row)!"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-accent"
                        title="Zobraziť dokument"
                        aria-label="Zobraziť dokument"
                    >
                        <i class="bi bi-eye" />
                    </a>
                </template>
            </UniversalDataTable>
        </div>
    </div>
</template>