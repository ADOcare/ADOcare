<script setup lang="ts">
import type {
    DekurzDay,
    DekurzEntry,
    DekurzMacro,
} from '@/types/dekurz'

import DekurzEntryEditor from './DekurzEntryEditor.vue'

defineProps<{
    day: DekurzDay
    macros: DekurzMacro[]
    saveStates: Record<string, 'idle' | 'saving' | 'saved' | 'error'>
}>()

const emit = defineEmits<{
    saveEntry: [entry: DekurzEntry]
    deleteEntry: [entry: DekurzEntry]
    propagateFollowing: [entry: DekurzEntry]
    propagateOptions: [entry: DekurzEntry]
    updateEntryText: [entry: DekurzEntry, text: string]
}>()
</script>

<template>
    <div class="overflow-hidden rounded-md bg-white">
        <DekurzEntryEditor
            v-for="entry in day.entries.filter(item => !item.is_excluded)"
            :key="entry.id ?? entry.track_key"
            :entry="entry"
            :macros="macros"
            :save-state="entry.id ? saveStates[`entry-${entry.id}`] : undefined"
            @save="emit('saveEntry', $event)"
            @delete="emit('deleteEntry', $event)"
            @propagate-following="emit('propagateFollowing', $event)"
            @propagate-options="emit('propagateOptions', $event)"
            @update-text="(entry, text) => emit('updateEntryText', entry, text)"
        />
    </div>
</template>
