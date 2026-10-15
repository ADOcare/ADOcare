<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { DekurzDay, DekurzEntry, DekurzMacro } from '@/types/dekurz'
import DekurzDayEditor from './DekurzDayEditor.vue'

const props = defineProps<{
    days: DekurzDay[]
    macros: DekurzMacro[]
    saveStates: Record<string, 'idle' | 'saving' | 'saved' | 'error'>
}>()

const emit = defineEmits<{
    saveEntry: [entry: DekurzEntry]
    saveDay: [day: DekurzDay]
    applyToUnedited: [entry: DekurzEntry]
    updateEntryText: [entry: DekurzEntry, text: string]
    updateDayNote: [day: DekurzDay, note: string]
}>()

const activeDate = ref(props.days[0]?.date ?? '')
const activeDay = computed(() => props.days.find((day) => day.date === activeDate.value) ?? props.days[0] ?? null)

watch(
    () => props.days,
    (days) => {
        if (!days.some((day) => day.date === activeDate.value)) activeDate.value = days[0]?.date ?? ''
    },
)

function shortDate(value: string) {
    return new Intl.DateTimeFormat('sk-SK', { weekday: 'short', day: 'numeric', month: 'short' }).format(
        new Date(`${value}T00:00:00`),
    )
}

function hasEmptyEntry(day: DekurzDay) {
    return day.entries.some((entry) => !entry.text.trim())
}

function updateEntryText(entry: DekurzEntry, text: string) {
    emit('updateEntryText', entry, text)
}

function updateDayNote(day: DekurzDay, note: string) {
    emit('updateDayNote', day, note)
}
</script>

<template>
    <div v-if="days.length" class="grid items-start gap-5 lg:grid-cols-[210px_minmax(0,1fr)]">
        <nav class="flex gap-2 overflow-x-auto lg:sticky lg:top-4 lg:flex-col" aria-label="Dni Dekurzu">
            <button
                v-for="day in days"
                :key="day.date"
                type="button"
                class="flex shrink-0 items-center justify-between gap-3 rounded-md border px-3 py-2 text-left transition-colors"
                :class="
                    day.date === activeDay?.date
                        ? 'border-accent bg-accent text-white'
                        : 'border-tag3 bg-white text-darkgrey hover:border-accent'
                "
                @click="activeDate = day.date"
            >
                <span class="text-normal capitalize">{{ shortDate(day.date) }}</span>
                <i v-if="hasEmptyEntry(day)" class="bi bi-exclamation-circle text-warning" title="Chýba text" />
                <i v-else class="bi bi-check-circle" title="Deň je vyplnený" />
            </button>
        </nav>

        <DekurzDayEditor
            v-if="activeDay"
            :day="activeDay"
            :macros="macros"
            :save-states="saveStates"
            @save-entry="emit('saveEntry', $event)"
            @save-day="emit('saveDay', $event)"
            @apply-to-unedited="emit('applyToUnedited', $event)"
            @update-entry-text="updateEntryText"
            @update-day-note="updateDayNote"
        />
    </div>
    <div v-else class="rounded-md border border-tag3 bg-white p-8 text-center text-lightgrey">
        Pre tento mesiac nie sú dostupné žiadne dni Dekurzu.
    </div>
</template>
