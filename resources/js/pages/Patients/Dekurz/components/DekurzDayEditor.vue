<script setup lang="ts">
import Textarea from 'primevue/textarea'
import type { DekurzDay, DekurzEntry, DekurzMacro } from '@/types/dekurz'
import DekurzMacroStrip from './DekurzMacroStrip.vue'

defineProps<{
    day: DekurzDay
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

function formatDate(value: string) {
    return new Intl.DateTimeFormat('sk-SK', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(`${value}T00:00:00`))
}

function append(entry: DekurzEntry, text: string) {
    const separator = entry.text.trim() ? ' ' : ''
    emit('updateEntryText', entry, `${entry.text}${separator}${text}`)
    emit('saveEntry', entry)
}
</script>

<template>
    <section class="min-w-0">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="text-mini uppercase text-lightgrey">Denný záznam</p>
                <h2 class="text-heading capitalize">{{ formatDate(day.date) }}</h2>
            </div>
            <span class="text-mini text-lightgrey">{{ day.entries.length }} výkonov</span>
        </header>

        <div class="flex flex-col gap-3">
            <article
                v-for="entry in day.entries"
                :key="entry.patient_point_snapshot_id"
                class="rounded-md border border-tag3 bg-white p-4"
            >
                <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-accent">{{ entry.procedure_code }}</span>
                        <span class="text-normal text-darkgrey">{{ entry.procedure_name }}</span>
                        <span class="rounded-full bg-tag3 px-2 py-0.5 text-mini">{{ entry.quantity }}×</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="entry.edited_at" class="text-mini text-success">Upravené</span>
                        <span v-if="saveStates[`entry-${entry.id}`] === 'saving'" class="text-mini text-lightgrey">
                            Ukladám…
                        </span>
                        <span v-else-if="saveStates[`entry-${entry.id}`] === 'error'" class="text-mini text-danger">
                            Chyba uloženia
                        </span>
                    </div>
                </div>

                <Textarea
                    :model-value="entry.text"
                    rows="4"
                    auto-resize
                    class="w-full! border-tag3!"
                    placeholder="Zadajte text denného záznamu"
                    @update:model-value="emit('updateEntryText', entry, String($event ?? ''))"
                    @blur="emit('saveEntry', entry)"
                />
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <DekurzMacroStrip :macros="macros" class="min-w-0 flex-1" @insert="append(entry, $event)" />
                    <Button
                        type="button"
                        icon="bi bi-copy"
                        label="Použiť pre neupravené dni"
                        text
                        size="small"
                        class="shrink-0! text-mini!"
                        :disabled="!entry.text.trim()"
                        title="Prepíše iba zatiaľ neupravené záznamy rovnakého výkonu"
                        @click="emit('applyToUnedited', entry)"
                    />
                </div>
            </article>
        </div>

        <div class="mt-4 rounded-md border border-tag3 bg-white p-4">
            <label :for="`day-note-${day.id}`" class="mb-2 block font-medium text-darkgrey">Poznámka k dňu</label>
            <Textarea
                :id="`day-note-${day.id}`"
                :model-value="day.note"
                rows="3"
                auto-resize
                class="w-full! border-tag3!"
                placeholder="Voliteľná poznámka"
                @update:model-value="emit('updateDayNote', day, String($event ?? ''))"
                @blur="emit('saveDay', day)"
            />
            <p v-if="saveStates[`day-${day.id}`] === 'saving'" class="mt-1 text-mini text-lightgrey">Ukladám…</p>
            <p v-else-if="saveStates[`day-${day.id}`] === 'error'" class="mt-1 text-mini text-danger">
                Poznámka sa nepodarila uložiť.
            </p>
        </div>
    </section>
</template>
