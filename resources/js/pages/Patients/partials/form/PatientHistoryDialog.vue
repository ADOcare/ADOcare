<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'

import api from '@/services/api'
import AppButton from '@/components/AppButton.vue'
import PatientHistoryForm from './PatientHistoryForm.vue'
import Tag from 'primevue/tag'

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

type TimelineEntry = {
    key: string
    revision: Revision
    item: RevisionItem | null
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

const revisions = ref<Revision[]>([])
const selectedEntryKey = ref<string | null>(null)
const displayedEntryKey = ref<string | null>(null)
const displayedState = ref<Record<string, HistoricalValue | number | string> | null>(null)
const revisionsLoading = ref(false)
const stateLoading = ref(false)
const error = ref('')
const mobileDetail = ref(false)

let revisionsSequence = 0
let stateSequence = 0

const timelineEntries = computed<TimelineEntry[]>(() => revisions.value
    .flatMap<TimelineEntry>((revision) => revision.items.length > 0
        ? revision.items.map((item) => ({
            key: `${revision.id}:${item.id}`,
            revision,
            item,
        }))
        : [{
            key: `${revision.id}:baseline`,
            revision,
            item: null,
        }])
    .sort((left, right) => {
        const leftDate = left.item?.effective_date ?? ''
        const rightDate = right.item?.effective_date ?? ''
        if (leftDate !== rightDate) return rightDate.localeCompare(leftDate)
        if (left.revision.recorded_at !== right.revision.recorded_at) {
            return right.revision.recorded_at.localeCompare(left.revision.recorded_at)
        }
        return right.key.localeCompare(left.key)
    }))

const selectedEntry = computed(() =>
    timelineEntries.value.find((entry) => entry.key === displayedEntryKey.value) ?? null,
)

const changedCategories = computed(() =>
    selectedEntry.value?.item ? [selectedEntry.value.item.category] : [],
)

const changedFields = computed<Record<string, string[]>>(() => {
    const item = selectedEntry.value?.item
    if (!item) return {}
    return { [item.category]: changedItemFields(item) }
})

watch(
    [() => props.visible, () => props.patientId],
    ([visible]) => {
        revisionsSequence++
        stateSequence++
        revisionsLoading.value = false
        stateLoading.value = false
        if (!visible) return

        revisions.value = []
        selectedEntryKey.value = null
        displayedEntryKey.value = null
        displayedState.value = null
        error.value = ''
        mobileDetail.value = false
        void loadTimeline()
    },
    { immediate: true },
)

async function loadTimeline() {
    const sequence = ++revisionsSequence
    revisionsLoading.value = true
    error.value = ''

    try {
        const response = await api.get(`/v1/patients/${props.patientId}/history`)
        if (sequence !== revisionsSequence || !props.visible) return

        revisions.value = Array.isArray(response.data.data) ? response.data.data : []
        const firstEntry = timelineEntries.value[0]
        if (firstEntry) await selectEntry(firstEntry, false)
    } catch (requestError) {
        if (sequence !== revisionsSequence || !props.visible) return
        error.value = 'Históriu zmien sa nepodarilo načítať.'
        console.error('Failed to load patient history timeline', requestError)
    } finally {
        if (sequence === revisionsSequence) revisionsLoading.value = false
    }
}

async function selectEntry(entry: TimelineEntry, showMobileDetail = true) {
    selectedEntryKey.value = entry.key
    mobileDetail.value = showMobileDetail
    error.value = ''

    const effectiveDate = entry.item?.effective_date
    if (!effectiveDate) {
        stateSequence++
        stateLoading.value = false
        displayedState.value = entry.revision.snapshot
        displayedEntryKey.value = entry.key
        return
    }

    const sequence = ++stateSequence
    stateLoading.value = true

    try {
        const response = await api.get(`/v1/patients/${props.patientId}/history/state`, {
            params: { date: effectiveDate },
        })
        if (sequence !== stateSequence || !props.visible || selectedEntryKey.value !== entry.key) return
        displayedState.value = response.data.data
        displayedEntryKey.value = entry.key
    } catch (requestError) {
        if (sequence !== stateSequence || !props.visible) return
        error.value = 'Údaje platné k dátumu zmeny sa nepodarilo načítať.'
        console.error('Failed to load effective patient history state', requestError)
    } finally {
        if (sequence === stateSequence) stateLoading.value = false
    }
}

function changedItemFields(item: RevisionItem): string[] {
    const previous = item.previous_values ?? {}
    const next = item.new_values ?? {}
    return [...new Set([...Object.keys(previous), ...Object.keys(next)])]
        .filter((field) => comparableValue(previous[field]) !== comparableValue(next[field]))
}

function comparableValue(value: unknown): string {
    if (value === null || value === undefined) return ''
    return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

function entryTitle(entry: TimelineEntry): string {
    if (entry.item) return categoryLabels[entry.item.category] ?? entry.item.category
    if (entry.revision.change_type === 'creation') return 'Vytvorenie pacienta'
    return 'Počiatočný zaznamenaný stav'
}

function formatDate(value: string | null | undefined): string {
    if (!value) return 'Neznáme'
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
    return parts ? `${parts[3]}.${parts[2]}.${parts[1]}` : value
}

function formatDateTime(value: string): string {
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value
    return new Intl.DateTimeFormat('sk-SK', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date)
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
        <p v-if="error" class="m-4 rounded-md bg-tag3 p-3 text-normal text-danger" role="alert">
            {{ error }}
        </p>

        <div
            v-if="revisionsLoading"
            class="flex min-h-64 items-center justify-center gap-2 text-normal text-darkgrey"
            role="status"
        >
            <i class="bi bi-arrow-repeat animate-spin text-accent" aria-hidden="true" />
            Načítavam históriu…
        </div>

        <div v-else class="grid min-h-[360px] md:grid-cols-[340px_minmax(0,1fr)]">
            <aside
                class="max-h-[75dvh] min-w-0 overflow-y-auto p-3"
                :class="mobileDetail ? 'hidden md:block' : 'block'"
            >
                <p v-if="!timelineEntries.length" class="p-4 text-normal text-darkgrey/70">
                    Zatiaľ nie sú zaznamenané žiadne zmeny.
                </p>

                <button
                    v-for="entry in timelineEntries"
                    :key="entry.key"
                    type="button"
                    class="mb-2 flex w-full flex-col gap-2 rounded-md p-3 text-left text-normal text-darkgrey transition-colors bg-tag3 hover:scale-102 transition-transform animate-smooth cursor-pointer"
                    @click="selectEntry(entry)"
                >
                    <div class="flex gap-2">
                        <Tag
                            :value="formatDate(entry.item?.effective_date)"
                            class="bg-darkgrey! w-fit!"
                        />
                        <span class="text-darkgrey">{{ entryTitle(entry) }}</span>
                    </div>

                    <div class="flex flex-col text-mini text-accent">
                        <span>
                            {{ formatDateTime(entry.revision.recorded_at) }}
                        </span>

                        <span>
                            {{ entry.revision.user?.name ?? 'Systém' }}
                        </span>

                        <span
                            v-if="entry.revision.change_type === 'correction'"
                            class="text-mini text-darkgrey/60"
                        >
                            Oprava historických údajov
                        </span>
                    </div>
                </button>
            </aside>

            <main
                class="max-h-[75dvh] min-w-0 overflow-y-auto px-4 py-4 sm:px-6"
                :class="mobileDetail ? 'block' : 'hidden md:block'"
                :aria-busy="stateLoading"
            >
                <AppButton
                    type="button"
                    variant="text"
                    icon="bi bi-arrow-left"
                    label="Späť na zmeny"
                    class="mb-4! md:hidden!"
                    @click="mobileDetail = false"
                />

                <PatientHistoryForm
                    v-if="displayedState"
                    :state="displayedState"
                    :changed-categories="changedCategories"
                    :changed-fields="changedFields"
                    :allow-assignment-editing="allowAssignmentEditing"
                />
            </main>
        </div>
    </Dialog>
</template>
