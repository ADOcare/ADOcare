<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import DatePicker from 'primevue/datepicker'

import api from '@/services/api'
import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'
import PatientHistoryForm from './PatientHistoryForm.vue'
import { toApiDate } from '@/utils/dateUtils'

type HistoricalValue = {
    status: 'known' | 'unknown' | 'missing' | 'conflict'
    values: Record<string, unknown> | null
    display_values?: Record<string, unknown> | null
    valid_from: string | null
    valid_to: string | null
}

type RevisionItem = {
    id: number
    category: string
    previous_values: Record<string, unknown> | null
    new_values: Record<string, unknown>
    effective_date: string | null
    metadata: {
        previous_display_values?: Record<string, unknown> | null
        new_display_values?: Record<string, unknown> | null
    }
}

type Revision = {
    id: number
    change_type: 'creation' | 'baseline' | 'change' | 'correction'
    recorded_at: string
    reason: string | null
    user: { id: number; name: string } | null
    snapshot: Record<string, HistoricalValue | number | string>
    metadata: Record<string, unknown>
    items: RevisionItem[]
}

const props = defineProps<{
    visible: boolean
    patientId: number
    allowAssignmentEditing?: boolean
}>()

const emit = defineEmits<{
    (event: 'update:visible', visible: boolean): void
}>()

const categoryLabels: Record<string, string> = {
    name: 'Meno a titul',
    identity: 'Identifikačné údaje',
    insurance: 'Zdravotné poistenie',
    doctor: 'Lekár',
    nurse: 'Priradená sestra',
    branch: 'Prevádzka',
    address: 'Adresa',
    contact: 'Kontaktné údaje',
}

const mode = ref<'revisions' | 'effective'>('revisions')
const revisions = ref<Revision[]>([])
const selectedRevisionId = ref<number | null>(null)
const effectiveState = ref<Record<string, HistoricalValue> | null>(null)
const selectedDate = ref<Date | null>(null)
const revisionsLoading = ref(false)
const effectiveLoading = ref(false)
const revisionsError = ref('')
const effectiveError = ref('')
const mobileDetail = ref(false)

let revisionSequence = 0
let effectiveSequence = 0

const selectedRevision = computed(() =>
    revisions.value.find((revision) => revision.id === selectedRevisionId.value) ?? null,
)

const displayedState = computed(() =>
    mode.value === 'revisions'
        ? selectedRevision.value?.snapshot ?? null
        : effectiveState.value,
)

const changedCategories = computed(() =>
    mode.value === 'revisions'
        ? [...new Set(selectedRevision.value?.items.map((item) => item.category) ?? [])]
        : [],
)

const changedFields = computed<Record<string, string[]>>(() => {
    if (mode.value !== 'revisions' || !selectedRevision.value) return {}
    const byCategory: Record<string, Set<string>> = {}
    for (const item of selectedRevision.value.items) {
        byCategory[item.category] ??= new Set<string>()
        for (const entry of changedItemFields(item)) byCategory[item.category].add(entry.field)
    }
    return Object.fromEntries(Object.entries(byCategory).map(([category, fields]) => [
        category,
        [...fields],
    ]))
})

const loading = computed(() => mode.value === 'revisions' ? revisionsLoading.value : effectiveLoading.value)
const error = computed(() => mode.value === 'revisions' ? revisionsError.value : effectiveError.value)

function localToday(): Date {
    const now = new Date()
    return new Date(now.getFullYear(), now.getMonth(), now.getDate())
}

watch(
    [() => props.visible, () => props.patientId],
    ([visible]) => {
        revisionSequence++
        effectiveSequence++
        revisionsLoading.value = false
        effectiveLoading.value = false
        if (!visible) return

        mode.value = 'revisions'
        mobileDetail.value = false
        revisions.value = []
        selectedRevisionId.value = null
        effectiveState.value = null
        selectedDate.value = localToday()
        revisionsError.value = ''
        effectiveError.value = ''
        void loadRevisions()
    },
    { immediate: true },
)

watch(selectedDate, () => {
    if (props.visible && mode.value === 'effective') void loadEffectiveState()
})

async function loadRevisions() {
    const sequence = ++revisionSequence
    revisionsLoading.value = true
    revisionsError.value = ''

    try {
        const response = await api.get(`/v1/patients/${props.patientId}/history`)
        if (sequence !== revisionSequence || !props.visible) return

        revisions.value = Array.isArray(response.data.data) ? response.data.data : []
        selectedRevisionId.value = revisions.value[0]?.id ?? null
    } catch (requestError) {
        if (sequence !== revisionSequence || !props.visible) return
        revisionsError.value = 'Históriu zmien sa nepodarilo načítať.'
        console.error('Failed to load patient revision history', requestError)
    } finally {
        if (sequence === revisionSequence) revisionsLoading.value = false
    }
}

async function loadEffectiveState() {
    const date = toApiDate(selectedDate.value)
    const sequence = ++effectiveSequence
    effectiveState.value = null
    effectiveError.value = ''
    if (!date || !props.visible || mode.value !== 'effective') return
    effectiveLoading.value = true

    try {
        const response = await api.get(`/v1/patients/${props.patientId}/history/state`, {
            params: { date },
        })
        if (sequence !== effectiveSequence || !props.visible || mode.value !== 'effective') return
        effectiveState.value = response.data.data
    } catch (requestError) {
        if (sequence !== effectiveSequence || !props.visible || mode.value !== 'effective') return
        effectiveError.value = 'Údaje platné k zvolenému dátumu sa nepodarilo načítať.'
        console.error('Failed to load effective patient history', requestError)
    } finally {
        if (sequence === effectiveSequence) effectiveLoading.value = false
    }
}

function selectMode(nextMode: 'revisions' | 'effective') {
    if (mode.value === nextMode) return
    mode.value = nextMode
    mobileDetail.value = false
    if (nextMode === 'effective') void loadEffectiveState()
    else effectiveSequence++
}

function selectRevision(revision: Revision) {
    selectedRevisionId.value = revision.id
    mobileDetail.value = true
}

function formatDateTime(value: string) {
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value
    return new Intl.DateTimeFormat('sk-SK', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date)
}

function formatDate(value: string | null) {
    if (!value) return 'neznámy dátum'
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
    return parts ? `${parts[3]}.${parts[2]}.${parts[1]}` : value
}

function valueText(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—'
    if (typeof value === 'boolean') return value ? 'Áno' : 'Nie'
    if (typeof value === 'object') return JSON.stringify(value)
    return String(value)
}

function changedItemFields(item: RevisionItem) {
    const previous = item.previous_values ?? {}
    const next = item.new_values ?? {}
    const fields = [...new Set([...Object.keys(previous), ...Object.keys(next)])]
    return fields
        .filter((field) => valueText(previous[field]) !== valueText(next[field]))
        .map((field) => ({ field, previous: previous[field], next: next[field] }))
}

function revisionTitle(revision: Revision) {
    if (revision.change_type === 'creation') return 'Vytvorenie pacienta'
    if (revision.change_type === 'baseline') return 'Počiatočný zaznamenaný stav'
    if (revision.change_type === 'correction') return 'Oprava nesprávnych údajov'
    if (revision.items.length === 1) {
        const category = revision.items[0].category
        return `Zmena: ${categoryLabels[category] ?? category}`
    }
    return `Zmena ${revision.items.length} skupín údajov`
}

function isRetrospective(item: RevisionItem, revision: Revision) {
    return !!item.effective_date && item.effective_date < revision.recorded_at.slice(0, 10)
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="História zmien pacienta"
        :style="{ width: 'min(96vw, 1200px)' }"
        :content-style="{ padding: '0' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div class="flex flex-wrap items-center gap-2 border-b border-tag3 px-4 py-3">
            <AppButton
                type="button"
                label="Zaznamenané zmeny"
                :variant="mode === 'revisions' ? 'accent' : 'secondary'"
                @click="selectMode('revisions')"
            />
            <AppButton
                type="button"
                label="Platnosť údajov"
                :variant="mode === 'effective' ? 'accent' : 'secondary'"
                @click="selectMode('effective')"
            />
        </div>

        <p v-if="error" class="m-4 rounded-md bg-tag3 p-3 text-normal text-danger" role="alert">
            {{ error }}
        </p>

        <div v-if="loading" class="flex min-h-64 items-center justify-center gap-2 text-normal text-darkgrey" role="status">
            <i class="bi bi-arrow-repeat animate-spin text-accent" aria-hidden="true" />
            Načítavam údaje…
        </div>

        <div v-else-if="mode === 'revisions'" class="grid min-h-[360px] md:grid-cols-[290px_minmax(0,1fr)]">
            <aside
                class="max-h-[70dvh] min-w-0 overflow-y-auto border-r border-darkgrey bg-darkgrey p-3"
                :class="mobileDetail ? 'hidden md:block' : 'block'"
            >
                <p v-if="!revisions.length" class="p-4 text-normal text-darkgrey/70">
                    Zatiaľ nie sú zaznamenané žiadne zmeny.
                </p>

                <button
                    v-for="revision in revisions"
                    :key="revision.id"
                    type="button"
                    class="mb-2 flex w-full flex-col gap-1 rounded-md p-3 text-left text-normal text-darkgrey transition-colors"
                    :class="selectedRevisionId === revision.id ? 'bg-tag3!' : 'bg-white! hover:bg-tag3!'"
                    @click="selectRevision(revision)"
                >
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-accent">{{ revisionTitle(revision) }}</span>
                        <span v-if="revision.change_type === 'correction'" class="rounded-md bg-tag3 px-2 py-0.5 text-mini text-darkgrey">
                            Oprava
                        </span>
                    </span>
                    <span class="text-mini">{{ formatDateTime(revision.recorded_at) }}</span>
                    <span class="text-mini text-darkgrey/70">{{ revision.user?.name ?? 'Systém' }}</span>
                    <span v-for="item in revision.items" :key="item.id" class="text-mini text-darkgrey/70">
                        {{ categoryLabels[item.category] ?? item.category }} · od {{ formatDate(item.effective_date) }}
                        <span v-if="isRetrospective(item, revision)" class="text-accent"> · Spätne</span>
                    </span>
                </button>
            </aside>

            <main
                class="max-h-[70dvh] min-w-0 overflow-y-auto px-4 py-4 sm:px-6"
                :class="mobileDetail ? 'block' : 'hidden md:block'"
            >
                <AppButton
                    type="button"
                    variant="text"
                    icon="bi bi-arrow-left"
                    label="Späť na zmeny"
                    class="mb-4! md:hidden!"
                    @click="mobileDetail = false"
                />

                <template v-if="selectedRevision">
                    <PatientHistoryForm
                        :state="displayedState"
                        :changed-categories="changedCategories"
                        :changed-fields="changedFields"
                        :allow-assignment-editing="allowAssignmentEditing"
                    />
                </template>
            </main>
        </div>

        <div v-else class="flex max-h-[75dvh] flex-col gap-5 overflow-y-auto p-4 sm:p-6">
            <FormField label="Údaje platné k dátumu" class="w-full max-w-xs" v-slot="{ id }">
                <DatePicker
                    :id="id"
                    v-model="selectedDate"
                    dateFormat="dd.mm.yy"
                    :manualInput="false"
                    :showIcon="false"
                    class="w-full"
                    inputClass="w-full! cursor-pointer!"
                />
            </FormField>

            <PatientHistoryForm
                v-if="effectiveState"
                :state="displayedState"
                :allow-assignment-editing="allowAssignmentEditing"
            />
            <p v-else-if="!error" class="text-normal text-darkgrey/70">Vyberte dátum na zobrazenie údajov.</p>
        </div>
    </Dialog>
</template>
