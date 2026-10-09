<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import type { DataTableOptions } from '@/types/datatable'

type Change = { field: string; label: string; before: string; after: string }
type Candidate = {
    journey_id: number
    patient_id: number
    patient_name: string
    date: string
    origin: string
    destination: string
    procedure_code: string
    diagnosis_code: string
    kilometers: number
    previous_kilometers: number | null
    amount: number
    previous_document_id: number | null
    previous_character: string | null
    status: 'new' | 'changed' | 'unchanged'
    suggested?: boolean
    changes: Change[]
    source_changes: Change[]
}
type Blocked = { date: string; address: string; journey_id?: number; reason: string }
type Address = { address: string; city: string }
type RouteData = [Address, Address, number, string]
type RouteChange = { journey_id: number; date: string; reason: string; before: RouteData | null; after: RouteData | null }
type BatchSelection = { journeyIds: number[]; correction?: { confirmed: boolean; reason: string } }
type BatchResult = BatchSelection & { documentId: number }
type Preview = {
    candidates: Candidate[]
    blocked?: Blocked[]
    route_changes?: RouteChange[]
    can_create: boolean
    comparison_basis?: string
    previewToken: string
    car: { id: number }
}

const props = defineProps<{
    batchType: string
    loadPreview: () => Promise<Preview>
    createBatch: (selection: BatchSelection, preview: Preview) => Promise<number>
    modalResolve?: (value?: BatchResult | null) => void
}>()

const preview = ref<Preview | null>(null)
const initialSelectedIds = ref<number[]>([])
const selectedIds = ref<number[]>([])
const status = ref('all')
const confirmed = ref(false)
const reason = ref('')
const loading = ref(true)
const saving = ref(false)
const loadErrors = ref<string[]>([])
const saveErrors = ref<string[]>([])
const statusLabels: Record<string, string> = { new: 'Nevykázané', changed: 'Zmenené', unchanged: 'Bez zmeny TXT' }
const isCorrection = computed(() => ['O', 'F', 'J'].includes(props.batchType))
const candidates = computed(() => preview.value?.candidates ?? [])
const canCreate = computed(() => preview.value?.can_create ?? false)
const selected = computed(() => {
    const ids = new Set(selectedIds.value)
    return candidates.value.filter((row) => ids.has(row.journey_id))
})
const selectedPatients = computed(() => new Set(selected.value.map((row) => row.patient_id)).size)
const selectedKm = computed(() => selected.value.reduce((sum, row) => sum + Number(row.kilometers), 0))
const selectedAmount = computed(() => selected.value.reduce((sum, row) => sum + Number(row.amount), 0))
const visibleCandidates = computed(() => candidates.value
    .filter((row) => status.value === 'all' || row.status === status.value)
    .slice().sort((a, b) => Number(b.status === 'changed') - Number(a.status === 'changed') || b.date.localeCompare(a.date) || a.journey_id - b.journey_id))
const canSave = computed(() => canCreate.value && selected.value.length > 0
    && (!isCorrection.value || (confirmed.value && reason.value.trim().length > 0)))

const htmlEntities: Record<string, string> = {
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}
function escapeHtml(value: unknown): string {
    return String(value ?? '').replace(/[&<>"']/g, (character) => htmlEntities[character] ?? character)
}
function money(value: number): string {
    return Number(value).toLocaleString('sk-SK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
}
function renderChanges(_: unknown, row: Candidate): string {
    const changes = (row.changes ?? []).map((change) =>
        `<div><strong>${escapeHtml(change.label)}:</strong> ${escapeHtml(change.before || '—')} → ${escapeHtml(change.after || '—')}</div>`)
    const sources = (row.source_changes ?? []).map((change) =>
        `<div class="text-mini">Podklad – ${escapeHtml(change.label)}: ${escapeHtml(change.before || '—')} → ${escapeHtml(change.after || '—')}</div>`)
    return [...changes, ...sources].join('') || (row.status === 'new' ? 'Nový dopravný nárok' : 'Bez zmeny údajov TXT')
}
const tableOptions = computed<DataTableOptions<Candidate>>(() => ({
    endpointUrl: '',
    localItems: visibleCandidates.value,
    initialSelectedKeys: initialSelectedIds.value,
    resetPageOnLocalItemsChange: true,
    rowKey: 'journey_id',
    selectable: true,
    defaultPageSize: 50,
    pageSizeOptions: [10, 25, 50, 100],
    columns: [
        { field: 'date', header: 'Dátum', width: '7rem' },
        { field: 'patient_name', header: 'Pacient' },
        { field: 'procedure_code', header: 'Výkon', width: '5rem' },
        { field: 'diagnosis_code', header: 'Diagnóza', width: '6rem' },
        { field: 'origin', header: 'Odkiaľ' },
        { field: 'destination', header: 'Kam' },
        { field: 'previous_kilometers', header: 'Pôvodne km', align: 'right', render: (value) => value == null ? '—' : escapeHtml(value) },
        { field: 'kilometers', header: 'Teraz km', align: 'right' },
        { field: 'amount', header: 'Suma', align: 'right', render: (value) => money(Number(value ?? 0)) },
        { field: 'previous_document_id', header: 'Pôvodný dokument', render: (value) => value == null ? '—' : `#${escapeHtml(value)}` },
        { field: 'status', header: 'Stav', render: (value) => statusLabels[String(value)] ?? '—' },
        { field: 'changes', header: 'Čo sa zmenilo', render: renderChanges },
    ],
}))

function onRowsSelected(rows: Candidate | Candidate[]) {
    selectedIds.value = (Array.isArray(rows) ? rows : [rows]).map((row) => row.journey_id)
}
watch(() => selectedIds.value.join(','), () => { confirmed.value = false })

function errorMessages(error: any): string[] {
    const body = error?.response?.data
    const messages = body?.errors && typeof body.errors === 'object'
        ? Object.values(body.errors).flat().map(String)
        : []

    return messages.length
        ? messages.slice(0, 8)
        : [body?.message ?? error?.message ?? 'Nepodarilo sa vytvoriť dopravnú dávku.']
}

async function load() {
    loading.value = true
    loadErrors.value = []

    try {
        const loadedPreview = await props.loadPreview()
        const suggestedIds = loadedPreview.candidates
            .filter((row) => row.suggested)
            .map((row) => row.journey_id)

        preview.value = loadedPreview
        initialSelectedIds.value = suggestedIds
        selectedIds.value = suggestedIds
    } catch (error) {
        preview.value = null
        loadErrors.value = errorMessages(error)
    } finally {
        loading.value = false
    }
}

onMounted(load)

function close(result: BatchResult | null = null) {
    props.modalResolve?.(result)
}

async function save() {
    if (!canSave.value || saving.value || !preview.value) return

    const selection: BatchSelection = {
        journeyIds: selected.value.map((row) => row.journey_id),
        correction: isCorrection.value ? { confirmed: confirmed.value, reason: reason.value.trim() } : undefined,
    }

    saving.value = true
    saveErrors.value = []

    try {
        const documentId = await props.createBatch(selection, preview.value)
        close({ ...selection, documentId })
    } catch (error) {
        saveErrors.value = errorMessages(error)
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div class="relative flex flex-col gap-4">
        <div v-if="loading || saving" class="min-h-[20rem]">
            <LoadingOverlay
                :show="loading || saving"
            />
        </div>

        <div v-if="loadErrors.length" class="flex min-h-[18rem] flex-col items-center justify-center gap-4">
            <div class="w-full rounded-md bg-danger/10 p-3 text-danger" role="alert">
                <div v-for="message in loadErrors" :key="message">{{ message }}</div>
            </div>
            <div class="flex gap-2">
                <Button label="Zrušiť" text class="text-accent! px-3!" @click="close()" />
                <Button label="Skúsiť znova" class="bg-accent! border-0! text-white!" @click="load" />
            </div>
        </div>

        <template v-if="preview">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-tag3 rounded-md p-3">
                <div class="text-mini text-darkgrey">Vybrané jazdy</div>
                <div class="text-normal font-semibold">{{ selected.length }}</div>
            </div>
            <div class="bg-tag3 rounded-md p-3">
                <div class="text-mini text-darkgrey">Pacienti</div>
                <div class="text-normal font-semibold">{{ selectedPatients }}</div>
            </div>
            <div class="bg-tag3 rounded-md p-3">
                <div class="text-mini text-darkgrey">Vykázané kilometre</div>
                <div class="text-normal font-semibold">{{ selectedKm }} km</div>
            </div>
            <div class="bg-tag3 rounded-md p-3">
                <div class="text-mini text-darkgrey">Celková suma</div>
                <div class="text-normal font-semibold">{{ money(selectedAmount) }}</div>
            </div>
        </div>
        <div v-if="candidates.length" class="h-[50vh] min-h-[20rem]">
            <UniversalDataTable :options="tableOptions" @row-selected="onRowsSelected" />
        </div>
        <div v-if="saveErrors.length" class="rounded-md bg-danger/10 p-3 text-danger" role="alert">
            <div v-for="message in saveErrors" :key="message">{{ message }}</div>
        </div>

        <div class="flex justify-end gap-2 mt-2">
            <Button label="Zrušiť" text :disabled="saving" class="text-accent! px-3!" @click="close()" />
            <Button label="Vytvoriť dávku" :loading="saving" :disabled="!canSave || saving" class="bg-accent! border-0! hover:bg-darkgrey! px-4! text-white!" @click="save" />
        </div>
        </template>
    </div>
</template>
