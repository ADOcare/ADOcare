<script setup lang="ts">
import { computed } from 'vue'
import Textarea from 'primevue/textarea'
import type { DekurzMacro, DekurzProcedure } from '@/types/dekurz'
import DekurzMacroStrip from './DekurzMacroStrip.vue'

const props = defineProps<{
    procedure: DekurzProcedure
    macros: DekurzMacro[]
    saveState?: 'idle' | 'saving' | 'saved' | 'error'
}>()

const emit = defineEmits<{
    save: [procedure: DekurzProcedure]
    reset: [procedure: DekurzProcedure]
    updateText: [procedure: DekurzProcedure, text: string]
}>()

const sourceLabel = computed(() => {
    if (props.procedure.effective_text_source === 'patient_template') return 'Vlastný text pacienta'
    if (props.procedure.effective_text_source === 'procedure_default') return 'Predvolený text výkonu'
    return 'Bez predvoleného textu'
})

function append(text: string) {
    const separator = props.procedure.effective_text.trim() ? ' ' : ''
    emit('updateText', props.procedure, `${props.procedure.effective_text}${separator}${text}`)
    emit('save', props.procedure)
}
</script>

<template>
    <article class="rounded-md border border-tag3 bg-white p-4">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-accent">{{ procedure.code }}</span>
                    <span class="text-normal text-darkgrey">{{ procedure.name }}</span>
                    <span class="rounded-full bg-tag3 px-2 py-0.5 text-mini text-darkgrey">
                        {{ procedure.scoring_rows_count }}×
                    </span>
                </div>
                <p class="mt-1 text-mini text-lightgrey">{{ sourceLabel }}</p>
            </div>
            <div class="flex items-center gap-2 text-mini">
                <span v-if="saveState === 'saving'" class="text-lightgrey">Ukladám…</span>
                <span v-else-if="saveState === 'saved'" class="text-success">Uložené</span>
                <span v-else-if="saveState === 'error'" class="text-danger">Nepodarilo sa uložiť</span>
                <Button
                    v-if="procedure.patient_template_text !== null"
                    type="button"
                    icon="bi bi-arrow-counterclockwise"
                    text
                    size="small"
                    title="Obnoviť predvolený text výkonu"
                    aria-label="Obnoviť predvolený text výkonu"
                    @click="emit('reset', procedure)"
                />
            </div>
        </div>

        <Textarea
            :model-value="procedure.effective_text"
            rows="4"
            auto-resize
            class="w-full! border-tag3!"
            placeholder="Zadajte text pre tento výkon"
            @update:model-value="emit('updateText', procedure, String($event ?? ''))"
            @blur="emit('save', procedure)"
        />
        <DekurzMacroStrip :macros="macros" class="mt-2" @insert="append" />
    </article>
</template>
