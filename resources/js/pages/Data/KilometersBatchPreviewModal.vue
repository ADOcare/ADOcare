<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Checkbox from 'primevue/checkbox'
import Textarea from 'primevue/textarea'
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
    changes: Change[]
    source_changes: Change[]
}
type Blocked = { date: string; address: string; journey_id?: number; reason: string }
type Address = { address: string; city: string }
type RouteData = [Address, Address, number, string]
type RouteChange = { journey_id: number; date: string; reason: string; before: RouteData | null; after: RouteData | null }

const props = defineProps<{
    candidates: Candidate[]
    initialSelectedJourneyIds: number[]
    blocked: Blocked[]
    routeChanges: RouteChange[]
    canCreate: boolean
    batchType: string
    comparisonBasis?: string
    modalResolve?: (value?: { journeyIds: number[]; correction?: { confirmed: boolean; reason: string } } | null) => void
}>()

const selectedIds = ref<number[]>([...props.initialSelectedJourneyIds])
const status = ref('all')
const confirmed = ref(false)
const reason = ref('')
const statusLabels: Record<string, string> = { new: 'Nevykázané', changed: 'Zmenené', unchanged: 'Bez zmeny TXT' }
const isCorrection = computed(() => ['O', 'F', 'J'].includes(props.batchType))
const isAddition = computed(() => ['A', 'G', 'K'].includes(props.batchType))
const filters = computed(() => isCorrection.value
    ? [{ value: 'all', label: 'Všetky dostupné' }, { value: 'changed', label: 'Zmenené údaje TXT' }, { value: 'unchanged', label: 'Bez zmeny TXT' }]
    : [{ value: 'all', label: 'Všetky dostupné' }, { value: 'new', label: 'Ešte nevykázané' }])
const selected = computed(() => {
    const ids = new Set(selectedIds.value)
    return props.candidates.filter((row) => ids.has(row.journey_id))
})
const selectedPatients = computed(() => new Set(selected.value.map((row) => row.patient_id)).size)
const selectedKm = computed(() => selected.value.reduce((sum, row) => sum + Number(row.kilometers), 0))
const selectedAmount = computed(() => selected.value.reduce((sum, row) => sum + Number(row.amount), 0))
const visible = computed(() => props.candidates
    .filter((row) => status.value === 'all' || row.status === status.value)
    .slice().sort((a, b) => Number(b.status === 'changed') - Number(a.status === 'changed') || b.date.localeCompare(a.date) || a.journey_id - b.journey_id))
const canSave = computed(() => props.canCreate && selected.value.length > 0
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
    localItems: visible.value,
    initialSelectedKeys: props.initialSelectedJourneyIds,
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

function routeLabel(data: RouteData | null): string {
    if (!data) return 'Návšteva už nie je v trase'
    return `${data[0].address}, ${data[0].city} → ${data[1].address}, ${data[1].city} (${data[2]} km; ${data[3]})`
}
function save() {
    if (!canSave.value) return
    props.modalResolve?.({
        journeyIds: selected.value.map((row) => row.journey_id),
        correction: isCorrection.value ? { confirmed: confirmed.value, reason: reason.value.trim() } : undefined,
    })
}
</script>

<template>
    <div class="flex flex-col gap-4 min-h-0">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-tag3/40 rounded-md p-3">
                <div class="text-mini text-darkgrey">Vybrané jazdy</div>
                <div class="text-normal font-semibold">{{ selected.length }}</div>
            </div>
            <div class="bg-tag3/40 rounded-md p-3">
                <div class="text-mini text-darkgrey">Pacienti</div>
                <div class="text-normal font-semibold">{{ selectedPatients }}</div>
            </div>
            <div class="bg-tag3/40 rounded-md p-3">
                <div class="text-mini text-darkgrey">Vykázané kilometre</div>
                <div class="text-normal font-semibold">{{ selectedKm }} km</div>
            </div>
            <div class="bg-tag3/40 rounded-md p-3">
                <div class="text-mini text-darkgrey">Celková suma</div>
                <div class="text-normal font-semibold">{{ money(selectedAmount) }}</div>
            </div>
        </div>
        <p class="text-mini text-darkgrey">{{ comparisonBasis }}</p>
        <p v-if="isAddition" class="text-normal">
            Zobrazené sú dostupné, ešte nevykázané dopravné nároky. Pridanie pacienta na už vykázanú spoločnú zastávku nevytvára ďalšiu cestu.
        </p>
        <p v-if="isCorrection" class="text-normal">
            Zmenené riadky sú predvybrané na kontrolu. Vyberte iba riadky neuznané poisťovňou; reklamovať možno aj riadok bez zmeny TXT.
        </p>
        <div class="flex flex-wrap items-center gap-3">
            <label for="transport-status-filter">Zobraziť</label>
            <Select inputId="transport-status-filter" v-model="status" :options="filters" optionLabel="label" optionValue="value" />
            <span class="text-mini text-darkgrey">Filter nemení výber; súhrny zahŕňajú aj vybrané riadky mimo filtra.</span>
        </div>
        <div v-if="candidates.length" class="h-[50vh] min-h-[20rem]">
            <UniversalDataTable :options="tableOptions" @row-selected="onRowsSelected" />
        </div>
        <p v-else class="bg-tag3/40 rounded-md p-3">Pre zvolené filtre nie sú dostupné jazdy na vytvorenie dávky.</p>
        <details v-if="blocked.length || routeChanges.length" class="bg-tag3/40 rounded-md p-3" :open="!canCreate">
            <summary class="cursor-pointer font-semibold">Na preverenie ({{ blocked.length }})</summary>
            <ul class="list-disc pl-5 mt-3 space-y-2">
                <li v-for="(item, index) in blocked" :key="index">{{ item.date }} {{ item.address }} — {{ item.reason }}</li>
            </ul>
            <div v-for="change in routeChanges" :key="`${change.date}-${change.journey_id}`" class="mt-3 text-mini">
                <strong>{{ change.date }} · jazda #{{ change.journey_id }}</strong>
                <div>Pôvodne: {{ routeLabel(change.before) }}</div>
                <div>Teraz: {{ routeLabel(change.after) }}</div>
            </div>
        </details>
        <div v-if="isCorrection && candidates.length" class="bg-tag3/40 rounded-md p-3 flex flex-col gap-3">
            <div class="flex items-start gap-2">
                <Checkbox inputId="transport-rejection-confirmed" v-model="confirmed" binary />
                <label for="transport-rejection-confirmed">Potvrdzujem, že vybrané riadky boli predložené poisťovni a neboli uznané.</label>
            </div>
            <label for="transport-correction-reason">Odôvodnenie reklamácie</label>
            <Textarea id="transport-correction-reason" v-model="reason" rows="2" :maxlength="2000" class="w-full" />
            <small>Odôvodnenie sa uloží k dokumentu. Jeho odoslanie poisťovni zabezpečte spôsobom požadovaným poisťovňou.</small>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <Button label="Zrušiť" text class="text-accent! px-3!" @click="modalResolve?.(null)" />
            <Button label="Vytvoriť dávku" :disabled="!canSave" class="bg-accent! border-0! hover:bg-darkgrey! px-4! text-white!" @click="save" />
        </div>
    </div>
</template>
