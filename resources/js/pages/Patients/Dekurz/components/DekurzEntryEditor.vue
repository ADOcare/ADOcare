<script setup lang="ts">
import {
    computed,
} from 'vue'

import AppButton from '@/components/AppButton.vue'
import type {
    DekurzEntry,
    DekurzMacro,
} from '@/types/dekurz'

import DekurzMacroTextarea from './DekurzMacroTextarea.vue'

const props = defineProps<{
    entry: DekurzEntry
    macros: DekurzMacro[]
    saveState?: 'idle' | 'saving' | 'saved' | 'error'
}>()

const emit = defineEmits<{
    save: [entry: DekurzEntry]
    delete: [entry: DekurzEntry]
    propagateFollowing: [entry: DekurzEntry]
    propagateOptions: [entry: DekurzEntry]
    updateText: [entry: DekurzEntry, text: string]
}>()

const sourceMeta = computed(() => {
    switch (props.entry.source) {
        case 'procedure_default':
            return {
                label: 'Všeobecný text výkonu · upravte pre pacienta',
                class: 'text-warning',
            }

        case 'patient_template':
            return {
                label: 'Uložený text pacienta',
                class: 'text-success',
            }

        case 'custom_template':
            return {
                label: 'Uložený vlastný text pacienta',
                class: 'text-success',
            }

        case 'previous_entry':
            return {
                label: 'Prevzaté z predchádzajúceho dňa',
                class: 'text-lightgrey',
            }

        case 'manual':
            return {
                label: 'Upravené pre tento deň',
                class: 'text-accent',
            }

        default:
            return null
    }
})
</script>

<template>
    <div
        class="
            border-b
            border-darkgrey/10
            p-4
            last:border-b-0
        "
    >
        <div
            class="
                mb-2
                flex
                flex-wrap
                items-center
                justify-between
                gap-2
            "
        >
            <div
                class="
                    flex
                    min-w-0
                    flex-wrap
                    items-center
                    gap-2
                "
            >
                <span
                    v-if="entry.procedure_code"
                    class="
                        rounded-md
                        bg-tag2
                        px-2
                        py-0.5
                        text-mini
                        text-white
                    "
                >
                    {{ entry.procedure_code }}
                </span>

                <span
                    v-else
                    class="
                        rounded-md
                        bg-tag2
                        px-2
                        py-0.5
                        text-mini
                        text-white
                    "
                >
                    Vlastný záznam
                </span>

                <span
                    class="
                        text-mini
                        font-medium
                        text-darkgrey
                    "
                >
                    {{ entry.track_name }}
                </span>

                <span
                    v-if="entry.quantity && entry.quantity > 1"
                    class="text-mini text-lightgrey"
                >
                    {{ entry.quantity }}×
                </span>
            </div>

            <div
                class="
                    flex
                    items-center
                    gap-2
                "
            >
                <span
                    v-if="sourceMeta"
                    class="text-mini"
                    :class="sourceMeta.class"
                >
                    {{ sourceMeta.label }}
                </span>

                <span
                    v-if="saveState === 'saving'"
                    class="text-mini text-lightgrey"
                >
                    Ukladám…
                </span>

                <span
                    v-else-if="saveState === 'error'"
                    class="text-mini text-danger"
                >
                    Chyba uloženia
                </span>
            </div>
        </div>

        <DekurzMacroTextarea
            :model-value="entry.text"
            :macros="macros"
            placeholder="Zadajte text záznamu"
            @update:model-value="emit('updateText', entry, $event)"
            @blur="emit('save', entry)"
        />

        <div
            class="
                mt-2
                flex
                flex-wrap
                items-center
                justify-end
                gap-2
            "
        >
            <AppButton
                type="button"
                variant="text"
                icon="bi bi-three-dots"
                label="Ďalšie možnosti"
                @click="emit('propagateOptions', entry)"
                :expandOnHover="true"
            />

            <AppButton
                type="button"
                variant="accent"
                icon="bi bi-chevron-double-down"
                label="Tento a nasledujúce dni"
                :disabled="!entry.id || !entry.text.trim()"
                @click="emit('propagateFollowing', entry)"
                :expandOnHover="true"
            />

            <AppButton
                v-if="entry.track_type === 'custom'"
                type="button"
                variant="danger"
                icon="bi bi-eraser"
                label="Odstrániť"
                aria-label="Odstrániť vlastnú sekciu z tohto dňa"
                title="Odstrániť vlastnú sekciu z tohto dňa"
                :disabled="!entry.id"
                @click="emit('delete', entry)"
                :expandOnHover="true"
            />
        </div>
    </div>
</template>
