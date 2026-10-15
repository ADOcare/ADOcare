<script setup lang="ts">
import type {
    DekurzMacro,
    DekurzProcedure,
    DekurzSupportingDocument,
} from '@/types/dekurz'
import DekurzProcedureTextCard from './DekurzProcedureTextCard.vue'
import DekurzSupportingDocuments from './DekurzSupportingDocuments.vue'

defineProps<{
    procedures: DekurzProcedure[]
    documents: DekurzSupportingDocument[]
    macros: DekurzMacro[]
    saveStates: Record<string, 'idle' | 'saving' | 'saved' | 'error'>
}>()

const emit = defineEmits<{
    saveTemplate: [procedure: DekurzProcedure]
    resetTemplate: [procedure: DekurzProcedure]
    updateTemplateText: [procedure: DekurzProcedure, text: string]
}>()

function updateTemplateText(procedure: DekurzProcedure, text: string) {
    emit('updateTemplateText', procedure, text)
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <DekurzSupportingDocuments :documents="documents" />

        <section aria-labelledby="procedure-texts-heading">
            <div class="mb-3">
                <h2 id="procedure-texts-heading" class="text-heading">Texty výkonov</h2>
                <p class="text-mini text-lightgrey">
                    Upravte text, ktorý sa použije pri prvom vytvorení denných záznamov.
                </p>
            </div>

            <div v-if="procedures.length" class="grid gap-3 xl:grid-cols-2">
                <DekurzProcedureTextCard
                    v-for="procedure in procedures"
                    :key="procedure.id"
                    :procedure="procedure"
                    :macros="macros"
                    :save-state="saveStates[`template-${procedure.id}`]"
                    @save="emit('saveTemplate', $event)"
                    @reset="emit('resetTemplate', $event)"
                    @update-text="updateTemplateText"
                />
            </div>
            <div v-else class="rounded-md border border-tag3 bg-white p-6 text-center text-lightgrey">
                V zvolenom mesiaci nie sú výkony určené pre Dekurz.
            </div>
        </section>
    </div>
</template>
