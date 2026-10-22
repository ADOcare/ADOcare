<script setup lang="ts">
import {
    ref,
    watch,
} from 'vue'

import Dialog from 'primevue/dialog'

import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'

import type {
    DekurzDay,
    DekurzEntry,
    DekurzMacro,
    DekurzPropagationMode,
} from '@/types/dekurz'

import DekurzDayEditor from './DekurzDayEditor.vue'
import DekurzPropagateDialog from './DekurzPropagateDialog.vue'

const props = defineProps<{
    days: DekurzDay[]
    macros: DekurzMacro[]
    saveStates: Record<string, 'idle' | 'saving' | 'saved' | 'error'>
}>()

const emit = defineEmits<{
    saveEntry: [entry: DekurzEntry]
    deleteEntry: [entry: DekurzEntry]
    createSection: [payload: { day: DekurzDay; name: string }]
    propagate: [payload: {
        entry: DekurzEntry
        mode: DekurzPropagationMode
        target_entry_ids: number[]
        target_dates: string[]
        save_as_patient_template: boolean
    }]
    updateEntryText: [entry: DekurzEntry, text: string]
}>()

const openDates =
    ref<string[]>([])

const propagationVisible =
    ref(false)

const propagationEntry =
    ref<DekurzEntry | null>(null)

const propagationDay =
    ref<DekurzDay | null>(null)

const addSectionVisible =
    ref(false)

const addSectionDay =
    ref<DekurzDay | null>(null)

const customSectionName =
    ref('')

const sectionPresets = [
    'Stav rany',
    'Mobilita',
    'Celkový stav',
    'Bolestivosť',
    'Koža',
    'Výživa a hydratácia',
]

watch(
    () =>
        props.days
            .map(day => day.date)
            .join('|'),
    () => {
        openDates.value =
            props.days[0]
                ? [props.days[0].date]
                : []
    },
    {
        immediate: true,
    },
)

function formatDate(
    value: string,
) {
    return new Intl.DateTimeFormat(
        'sk-SK',
        {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        },
    ).format(
        new Date(
            `${value}T00:00:00`,
        ),
    )
}

function toggleDay(
    date: string,
) {
    const set =
        new Set(
            openDates.value,
        )

    if (set.has(date)) {
        set.delete(date)
    } else {
        set.add(date)
    }

    openDates.value =
        [...set]
}

function activeEntries(
    day: DekurzDay,
) {
    return day.entries.filter(
        entry => !entry.is_excluded,
    )
}

function hasIssue(
    day: DekurzDay,
) {
    return activeEntries(day).some(
        entry =>
            !entry.text.trim() ||
            entry.text.includes('[TEXT]') ||
            entry.source === 'procedure_default',
    )
}

function propagateFollowing(
    day: DekurzDay,
    entry: DekurzEntry,
) {
    const targetDates =
        entry.track_type === 'custom'
            ? props.days
                .filter(candidate =>
                    candidate.date >= day.date,
                )
                .map(candidate =>
                    candidate.date,
                )
            : []

    emit(
        'propagate',
        {
            entry,
            mode: 'following',
            target_entry_ids: [],
            target_dates: targetDates,
            save_as_patient_template: true,
        },
    )
}

function openPropagationOptions(
    day: DekurzDay,
    entry: DekurzEntry,
) {
    propagationDay.value =
        day

    propagationEntry.value =
        entry

    propagationVisible.value =
        true
}

function openAddSection(
    day: DekurzDay,
) {
    addSectionDay.value =
        day

    customSectionName.value =
        ''

    addSectionVisible.value =
        true
}

function selectPreset(
    name: string,
) {
    customSectionName.value =
        name
}

function createSection() {
    const name =
        customSectionName.value.trim()

    if (
        !name ||
        !addSectionDay.value
    ) {
        return
    }

    emit(
        'createSection',
        {
            day:
                addSectionDay.value,
            name,
        },
    )

    addSectionVisible.value =
        false
}
</script>

<template>
    <div
        v-if="days.length"
        class="flex flex-col gap-3"
    >
        <section
            v-for="day in days"
            :key="day.date"
            class="overflow-hidden rounded-md bg-white"
        >
            <button
                type="button"
                class="flex w-full items-center justify-between gap-4 p-4 text-left"
                @click="toggleDay(day.date)"
            >
                <div
                    class="flex items-center gap-3"
                >
                    <i
                        :class="openDates.includes(day.date) ? 'bi bi-chevron-down text-accent' : 'bi bi-chevron-right text-accent'"
                    />

                    <span
                        class="text-normal font-medium capitalize text-accent"
                    >
                        {{ formatDate(day.date) }}
                    </span>
                </div>

                <div
                    class="flex items-center gap-3"
                >
                    <span
                        class="text-mini text-accent bg-tag3 px-2 py-1 rounded-md"
                    >
                        {{ activeEntries(day).length }} {{ activeEntries(day).length === 1 ? 'sekcia' : 'sekcií' }}
                    </span>

                    <i
                        :class="hasIssue(day) ? 'bi bi-exclamation-circle text-warning' : 'bi bi-check-lg text-success'"
                    />
                </div>
            </button>

            <div
                v-if="openDates.includes(day.date)"
                class="border-t border-darkgrey/10 p-3"
            >
                <DekurzDayEditor
                    :day="day"
                    :macros="macros"
                    :save-states="saveStates"
                    @save-entry="emit('saveEntry', $event)"
                    @delete-entry="emit('deleteEntry', $event)"
                    @propagate-following="propagateFollowing(day, $event)"
                    @propagate-options="openPropagationOptions(day, $event)"
                    @update-entry-text="(entry, text) => emit('updateEntryText', entry, text)"
                />

                <div
                    class="mt-3 flex justify-start"
                >
                    <AppButton
                        type="button"
                        variant="text"
                        icon="bi bi-plus-lg"
                        label="Pridať sekciu"
                        @click="openAddSection(day)"
                    />
                </div>
            </div>
        </section>
    </div>

    <p
        v-else
        class="py-8 text-center text-normal text-lightgrey"
    >
        Pre tento mesiac nie sú dostupné žiadne dni Dekurzu.
    </p>

    <DekurzPropagateDialog
        v-model:visible="propagationVisible"
        :entry="propagationEntry"
        :day="propagationDay"
        :days="days"
        @apply="payload => propagationEntry && emit('propagate', { entry: propagationEntry, ...payload })"
    />

    <Dialog
        v-model:visible="addSectionVisible"
        modal
        header="Pridať sekciu"
        :style="{ width: 'min(520px, 94vw)' }"
    >
        <div
            class="flex flex-col gap-5"
        >
            <FormField
                v-model="customSectionName"
                label="Názov sekcie"
                control="text"
                placeholder="Stav rany"
                @keyup.enter="createSection"
            />

            <div
                class="flex flex-wrap gap-2"
            >
                <AppButton
                    v-for="preset in sectionPresets"
                    :key="preset"
                    variant="secondary"
                    type="button"
                    @click="selectPreset(preset)"
                >
                    {{ preset }}
                </AppButton>
            </div>
        </div>

        <template #footer>
            <div
                class="flex w-full justify-end gap-2"
            >
                <AppButton
                    type="button"
                    variant="text"
                    label="Zrušiť"
                    @click="addSectionVisible = false"
                />

                <AppButton
                    type="button"
                    variant="accent"
                    label="Pridať"
                    :disabled="!customSectionName.trim()"
                    @click="createSection"
                />
            </div>
        </template>
    </Dialog>
</template>
