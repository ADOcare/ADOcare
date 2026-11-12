<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import DatePicker from 'primevue/datepicker'

import api from '@/services/api'
import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'
import { toApiDate } from '@/utils/dateUtils'
import type {
    PatientHistoryCategory,
    PatientHistoryChangeGroup,
} from '@/composables/patientHistoryChanges'

type SaveConfiguration = {
    change_type: 'change' | 'correction'
    effective_date: string
    effective_dates: Partial<Record<PatientHistoryCategory, string>>
    change_reason: string | null
}

type ImpactResult = {
    has_impact: boolean
    counts: Record<string, number>
}

const props = defineProps<{
    visible: boolean
    patientId: number
    changes: PatientHistoryChangeGroup[]
}>()

const emit = defineEmits<{
    (event: 'update:visible', visible: boolean): void
    (event: 'confirm', configuration: SaveConfiguration): void
}>()

function today() {
    const now = new Date()
    return new Date(now.getFullYear(), now.getMonth(), now.getDate())
}

// DatePicker uses Date objects; the API still receives local YYYY-MM-DD dates.
const dates = ref<Partial<Record<PatientHistoryCategory, Date | null>>>({})
const impact = ref<ImpactResult | null>(null)
const impactLoading = ref(false)
const impactError = ref(false)
let impactSequence = 0

const effectiveDates = computed(() => Object.fromEntries(
    props.changes.map((change) => [
        change.category,
        toApiDate(dates.value[change.category] ?? null) ?? '',
    ]),
) as Partial<Record<PatientHistoryCategory, string>>)

const canSubmit = computed(() =>
    props.changes.length > 0 && Object.values(effectiveDates.value).every(Boolean),
)

watch(
    () => props.visible,
    (visible) => {
        // Invalidate any response from a dialog that has already been closed.
        impactSequence++
        impact.value = null
        impactLoading.value = false
        impactError.value = false

        if (!visible) return

        dates.value = Object.fromEntries(
            props.changes.map((change) => [change.category, today()]),
        ) as Partial<Record<PatientHistoryCategory, Date | null>>
    },
    { immediate: true },
)

watch(
    [() => props.visible, () => props.patientId, effectiveDates],
    () => {
        void loadImpact()
    },
    { immediate: true },
)

async function loadImpact() {
    const sequence = ++impactSequence
    impact.value = null
    impactError.value = false
    impactLoading.value = false

    if (
        !props.visible ||
        !props.changes.length ||
        Object.values(effectiveDates.value).some((date) => !date)
    ) {
        return
    }

    impactLoading.value = true

    try {
        const response = await api.post(
            `/v1/patients/${props.patientId}/history/impact`,
            { effective_dates: effectiveDates.value },
        )

        if (sequence === impactSequence) {
            impact.value = response.data.data
        }
    } catch (error) {
        if (sequence === impactSequence) {
            impactError.value = true
        }
        console.error('Failed to evaluate retrospective patient change', error)
    } finally {
        if (sequence === impactSequence) {
            impactLoading.value = false
        }
    }
}

function confirm() {
    if (!canSubmit.value) return

    emit('confirm', {
        change_type: 'change',
        effective_date: Object.values(effectiveDates.value)[0] ?? toApiDate(today()) ?? '',
        effective_dates: effectiveDates.value,
        change_reason: null,
    })
}

onBeforeUnmount(() => {
    impactSequence++
})
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Platnosť zmien"
        :style="{ width: 'min(94vw, 720px)' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div class="flex flex-col gap-4 text-normal text-darkgrey">
            <div class="flex max-h-[55dvh] flex-col gap-3 overflow-y-auto pr-1">
                <article
                    v-for="change in changes"
                    :key="change.category"
                    class="flex flex-col gap-3 rounded-md bg-tag3 p-3 sm:p-4"
                >
                    <h3 class="text-normal text-accent">
                        {{ change.label }}
                    </h3>

                    <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                        <div class="min-w-0 rounded-md bg-white px-3 py-2">
                            <p class="mb-1 text-mini text-lightgrey">Pôvodne</p>
                            <p class="break-words text-normal text-darkgrey">
                                {{ change.previousSummary || '—' }}
                            </p>
                        </div>

                        <div class="flex items-center justify-center text-accent" aria-hidden="true">
                            <i class="bi bi-arrow-down sm:hidden" />
                            <i class="bi bi-arrow-right hidden sm:inline" />
                        </div>

                        <div class="min-w-0 rounded-md bg-white px-3 py-2">
                            <p class="mb-1 text-mini text-lightgrey">Teraz</p>
                            <p class="break-words text-normal font-medium text-darkgrey">
                                {{ change.newSummary || '—' }}
                            </p>
                        </div>
                    </div>

                    <FormField label="Platnosť zmeny od" required v-slot="{ id, describedBy, invalid }">
                        <DatePicker
                            :input-id="id"
                            v-model="dates[change.category]"
                            date-format="dd.mm.yy"
                            :show-icon="false"
                            :manual-input="false"
                            class="w-full!"
                            input-class="w-full! border-0!"
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                </article>
            </div>

            <div v-if="impactLoading" class="flex items-center gap-2 text-mini text-darkgrey" role="status">
                <i class="bi bi-arrow-repeat animate-spin text-accent" aria-hidden="true" />
                Kontrolujem súvisiace záznamy…
            </div>

            <div
                v-else-if="impactError"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-tag3 p-3"
                role="alert"
            >
                <p class="text-mini text-danger">
                    Súvisiace záznamy sa nepodarilo skontrolovať.
                </p>
                <AppButton
                    type="button"
                    variant="text"
                    icon="bi bi-arrow-clockwise"
                    label="Skúsiť znova"
                    @click="loadImpact"
                />
            </div>

            <div
                v-else-if="impact?.has_impact"
                class="flex items-start gap-3 rounded-md bg-tag3 p-3 sm:p-4"
                role="status"
            >
                <i class="bi bi-exclamation-triangle-fill mt-0.5 text-danger" aria-hidden="true" />
                <div class="flex min-w-0 flex-col gap-1">
                    <p class="text-normal font-semibold text-danger">Upozornenie</p>
                    <p class="text-normal text-darkgrey">
                        Zmena sa týka obdobia, v ktorom už boli zaznamenané návštevy,
                        zdravotné výkony alebo vykazovanie. Skontrolujte, či nie je
                        potrebná oprava vykazovania.
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-end gap-2">
                <AppButton
                    type="button"
                    variant="secondary"
                    label="Zrušiť"
                    @click="emit('update:visible', false)"
                />
                <AppButton
                    type="button"
                    variant="accent"
                    label="Uložiť zmeny"
                    :disabled="!canSubmit"
                    @click="confirm"
                />
            </div>
        </template>
    </Dialog>
</template>
