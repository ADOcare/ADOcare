<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'

import api from '@/services/api'
import AppButton from '@/components/AppButton.vue'

const ProcedureForm = defineAsyncComponent(() => import('./ProcedureForm.vue'))

type PriceItem = {
    id: number
    insurance_company_id: number
    insurance_company_name: string
    insurance_company_code: string | null
    price: string | number
    valid_from: string | null
    valid_to: string | null
}

type TimelineEntry = {
    key: string
    effective_date: string | null
    recorded_at: string
    changed_by_title: string | null
    changed_by_name: string | null
    changed_by_surname: string | null
    items: PriceItem[]
}

type PriceState = {
    date: string
    prices: Array<{
        insurance_company_id: number
        insurance_company_name: string
        insurance_company_code: string | null
        price: string | number | null
    }>
}

const props = defineProps<{
    visible: boolean
    procedureId: number
    procedure: Record<string, any>
}>()

const emit = defineEmits<{
    (event: 'update:visible', visible: boolean): void
}>()

const entries = ref<TimelineEntry[]>([])
const selectedKey = ref<string | null>(null)
const displayedKey = ref<string | null>(null)
const displayedState = ref<PriceState | null>(null)
const loading = ref(false)
const stateLoading = ref(false)
const error = ref('')
const mobileDetail = ref(false)
let timelineSequence = 0
let stateSequence = 0

const selectedEntry = computed(() =>
    entries.value.find((entry) => entry.key === displayedKey.value) ?? null,
)
const highlightedInsuranceCompanyIds = computed(() =>
    selectedEntry.value?.effective_date
        ? selectedEntry.value.items.map((item) => item.insurance_company_id)
        : [],
)
const timelineEntries = computed(() => [...entries.value].sort((left, right) => {
    const leftDate = left.effective_date ?? ''
    const rightDate = right.effective_date ?? ''
    if (leftDate !== rightDate) return rightDate.localeCompare(leftDate)
    if (left.recorded_at !== right.recorded_at) {
        return right.recorded_at.localeCompare(left.recorded_at)
    }
    return right.key.localeCompare(left.key)
}))

watch(
    [() => props.visible, () => props.procedureId],
    ([visible]) => {
        timelineSequence++
        stateSequence++
        loading.value = false
        stateLoading.value = false
        if (!visible) return

        entries.value = []
        selectedKey.value = null
        displayedKey.value = null
        displayedState.value = null
        error.value = ''
        mobileDetail.value = false
        void loadTimeline()
    },
    { immediate: true },
)

async function loadTimeline(): Promise<void> {
    const sequence = ++timelineSequence
    loading.value = true

    try {
        const response = await api.get(`/v1/procedures/${props.procedureId}/price-history`)
        if (sequence !== timelineSequence || !props.visible) return
        entries.value = Array.isArray(response.data.data) ? response.data.data : []
        if (timelineEntries.value[0]) await selectEntry(timelineEntries.value[0], false)
    } catch (requestError) {
        if (sequence !== timelineSequence || !props.visible) return
        error.value = 'Históriu cien sa nepodarilo načítať.'
        console.error('Failed to load procedure price history', requestError)
    } finally {
        if (sequence === timelineSequence) loading.value = false
    }
}

async function selectEntry(entry: TimelineEntry, showMobileDetail = true): Promise<void> {
    selectedKey.value = entry.key
    mobileDetail.value = showMobileDetail
    error.value = ''

    if (!entry.effective_date) {
        displayedState.value = {
            date: '',
            prices: entry.items
                .map((item) => ({
                    insurance_company_id: item.insurance_company_id,
                    insurance_company_name: item.insurance_company_name,
                    insurance_company_code: item.insurance_company_code,
                    price: item.price,
                })),
        }
        displayedKey.value = entry.key
        return
    }

    const sequence = ++stateSequence
    stateLoading.value = true
    try {
        const response = await api.get(`/v1/procedures/${props.procedureId}/price-state`, {
            params: { date: entry.effective_date },
        })
        if (sequence !== stateSequence || selectedKey.value !== entry.key || !props.visible) return
        displayedState.value = response.data.data
        displayedKey.value = entry.key
    } catch (requestError) {
        if (sequence !== stateSequence || !props.visible) return
        error.value = 'Ceny platné k dátumu zmeny sa nepodarilo načítať.'
        console.error('Failed to load effective procedure prices', requestError)
    } finally {
        if (sequence === stateSequence) stateLoading.value = false
    }
}

function formatDate(value: string | null): string {
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

function actorName(entry: TimelineEntry): string {
    return [entry.changed_by_title, entry.changed_by_name, entry.changed_by_surname]
        .filter(Boolean)
        .join(' ') || 'Systém'
}

function entryTitle(entry: TimelineEntry): string {
    if (!entry.effective_date) return 'Počiatočný stav cien'
    if (entry.items.length === 1) return entry.items[0].insurance_company_name
    return `Zmena ${entry.items.length} cien`
}

</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="História cien výkonu"
        :style="{ width: 'min(96vw, 1100px)' }"
        :content-style="{ padding: '0' }"
        @update:visible="emit('update:visible', $event)"
    >
        <p v-if="error" class="m-4 rounded-md bg-tag3 p-3 text-normal text-danger" role="alert">
            {{ error }}
        </p>

        <div
            v-if="loading"
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
                <p v-if="!entries.length" class="p-4 text-normal text-darkgrey/70">
                    Zatiaľ nie sú zaznamenané žiadne ceny.
                </p>

                <button
                    v-for="entry in timelineEntries"
                    :key="entry.key"
                    type="button"
                    class="mb-2 flex w-full cursor-pointer flex-col gap-2 rounded-md bg-tag3 p-3 text-left text-normal text-darkgrey transition-transform hover:scale-102"
                    @click="selectEntry(entry)"
                >
                    <div class="flex items-center gap-2">
                        <Tag :value="formatDate(entry.effective_date)" class="w-fit! bg-darkgrey!" />
                        <span>{{ entryTitle(entry) }}</span>
                    </div>
                    <div class="flex flex-col text-mini text-accent">
                        <span>{{ formatDateTime(entry.recorded_at) }}</span>
                        <span>{{ actorName(entry) }}</span>
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

                <div v-if="displayedState" inert>
                    <ProcedureForm
                        :procedure="procedure"
                        history-mode
                        :historical-prices="displayedState.prices"
                        :highlighted-insurance-company-ids="highlightedInsuranceCompanyIds"
                    />
                </div>
            </main>
        </div>
    </Dialog>
</template>
