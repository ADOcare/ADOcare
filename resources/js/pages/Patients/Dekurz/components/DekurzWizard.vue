<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DatePicker from 'primevue/datepicker'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import { useDekurzV2 } from '@/composables/useDekurzV2'
import type { DekurzDay, DekurzEntry, DekurzProcedure } from '@/types/dekurz'
import DekurzDailyStep from './DekurzDailyStep.vue'
import DekurzProcedureStep from './DekurzProcedureStep.vue'
import DekurzReviewStep from './DekurzReviewStep.vue'

const props = defineProps<{
    patientId: number
    dekurzNumber?: string | number | null
}>()

const initialMonth = new Date()
initialMonth.setMonth(initialMonth.getMonth() - 1)
const selectedMonth = ref(initialMonth)
const monthKey = computed(
    () => `${selectedMonth.value.getFullYear()}-${String(selectedMonth.value.getMonth() + 1).padStart(2, '0')}`,
)
const patientIdRef = computed(() => props.patientId)
const activeStep = ref(0)
const navigating = ref(false)

const {
    builder,
    macros,
    loading,
    initializing,
    finalizing,
    finalizationErrors,
    error,
    saveStates,
    load,
    loadMacros,
    initializeDraft,
    saveTemplate,
    resetTemplate,
    saveEntry,
    saveDay,
    applyToUnedited,
    finalizeDraft,
    flushPendingSaves,
} = useDekurzV2(patientIdRef, monthKey)

const steps = ['Podklady a výkony', 'Denné dekurzy', 'Kontrola']
const isCompleted = computed(() => builder.value?.draft?.status === 'completed')

async function goToStep(index: number) {
    if (index === activeStep.value || navigating.value) return
    if (isCompleted.value) {
        activeStep.value = 2
        return
    }
    navigating.value = true
    try {
        const savesSucceeded = await flushPendingSaves()
        if (!savesSucceeded) return
        if (index > 0 && !builder.value?.draft) {
            const initialized = await initializeDraft()
            if (!initialized) return
        }
        activeStep.value = index
    } finally {
        navigating.value = false
    }
}

function updateTemplateText(procedure: DekurzProcedure, text: string) {
    procedure.effective_text = text
}

function updateEntryText(entry: DekurzEntry, text: string) {
    entry.text = text
}

function updateDayNote(day: DekurzDay, note: string) {
    day.note = note
}

watch(monthKey, async () => {
    activeStep.value = 0
    await load()
})

watch(patientIdRef, async () => {
    activeStep.value = 0
    await load()
})

watch(
    () => builder.value?.draft?.status,
    (status) => {
        if (status === 'completed') activeStep.value = 2
    },
)

onMounted(() => {
    void Promise.all([load(), loadMacros()])
})
</script>

<template>
    <div class="relative min-h-[520px] pb-24">
        <LoadingOverlay :show="loading || initializing" :text="initializing ? 'Pripravujem koncept…' : 'Načítavam Dekurz…'" />

        <div v-if="error" class="rounded-md border border-danger bg-danger/5 p-5">
            <p class="font-medium text-danger">{{ error }}</p>
            <Button type="button" label="Skúsiť znova" icon="bi bi-arrow-clockwise" class="mt-3" @click="load" />
        </div>

        <template v-else-if="builder">
            <header class="mb-6 border-b border-tag3 pb-5">
                <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <div>
                        <p class="text-mini uppercase text-lightgrey">Dekurz V2</p>
                        <h1 class="text-2xl font-semibold text-darkgrey">{{ builder.patient.full_name }}</h1>
                        <p class="mt-1 text-normal text-lightgrey">
                            Číslo Dekurzu: {{ dekurzNumber || 'neuvedené' }}
                        </p>
                    </div>
                    <div class="w-full sm:w-64">
                        <label for="dekurz-month" class="mb-1 block text-mini text-lightgrey">Mesiac</label>
                        <DatePicker
                            v-model="selectedMonth"
                            input-id="dekurz-month"
                            view="month"
                            date-format="MM yy"
                            :show-icon="true"
                            class="w-full"
                            input-class="w-full!"
                        />
                    </div>
                </div>

                <nav class="mt-5 grid grid-cols-3 gap-2" aria-label="Kroky tvorby Dekurzu">
                    <button
                        v-for="(step, index) in steps"
                        :key="step"
                        type="button"
                        :disabled="isCompleted && index !== 2"
                        class="flex min-w-0 items-center gap-2 border-b-2 px-1 py-3 text-left transition-colors"
                        :class="index === activeStep ? 'border-accent text-accent' : 'border-tag3 text-lightgrey'"
                        @click="goToStep(index)"
                    >
                        <span
                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-mini font-semibold"
                            :class="index === activeStep ? 'bg-accent text-white' : 'bg-tag3 text-darkgrey'"
                        >
                            {{ index + 1 }}
                        </span>
                        <span class="min-w-0 text-normal font-medium sm:truncate">{{ step }}</span>
                    </button>
                </nav>
            </header>

            <DekurzProcedureStep
                v-if="activeStep === 0"
                :procedures="builder.procedures"
                :documents="builder.supporting_documents"
                :macros="macros"
                :save-states="saveStates"
                @save-template="saveTemplate"
                @reset-template="resetTemplate"
                @update-template-text="updateTemplateText"
            />

            <DekurzDailyStep
                v-else-if="activeStep === 1"
                :days="builder.days"
                :macros="macros"
                :save-states="saveStates"
                @save-entry="saveEntry"
                @save-day="saveDay"
                @apply-to-unedited="applyToUnedited"
                @update-entry-text="updateEntryText"
                @update-day-note="updateDayNote"
            />

            <DekurzReviewStep
                v-else
                :days="builder.days"
                :procedures="builder.procedures"
                :documents="builder.supporting_documents"
                :scoring-changes="builder.scoring_changes"
                :completed="isCompleted"
                :finalizing="finalizing"
                :final-document-id="builder.draft?.final_document_id ?? null"
                :finalization-errors="finalizationErrors"
                @finalize="finalizeDraft"
            />

            <footer v-if="!isCompleted" class="fixed right-0 bottom-0 left-0 z-20 border-t border-tag3 bg-white/95 px-4 py-3 backdrop-blur-sm">
                <div class="mx-auto flex max-w-7xl items-center justify-between gap-3">
                    <Button
                        type="button"
                        icon="bi bi-arrow-left"
                        label="Späť"
                        severity="secondary"
                        outlined
                        :disabled="activeStep === 0 || navigating"
                        @click="goToStep(activeStep - 1)"
                    />
                    <span class="hidden text-mini text-lightgrey sm:block">Krok {{ activeStep + 1 }} z {{ steps.length }}</span>
                    <Button
                        v-if="activeStep < steps.length - 1"
                        type="button"
                        icon="bi bi-arrow-right"
                        icon-pos="right"
                        label="Pokračovať"
                        :loading="navigating"
                        @click="goToStep(activeStep + 1)"
                    />
                    <span v-else class="w-24" />
                </div>
            </footer>
        </template>
    </div>
</template>
