<script setup lang="ts">
import type { DekurzMacro } from '@/types/dekurz'

defineProps<{
    macros: DekurzMacro[]
}>()

const emit = defineEmits<{
    insert: [text: string]
}>()
</script>

<template>
    <div class="flex min-w-0 items-center gap-2">
        <span class="shrink-0 text-mini text-lightgrey">Makrá</span>
        <div v-if="macros.length" class="flex min-w-0 gap-2 overflow-x-auto py-1">
            <Button
                v-for="macro in macros"
                :key="macro.key"
                type="button"
                size="small"
                severity="secondary"
                text
                class="shrink-0! h-7! px-2! text-mini!"
                :label="macro.title"
                :title="macro.body"
                @click="emit('insert', macro.body)"
            />
        </div>
        <a v-else href="/settings/macros" target="_blank" class="text-mini text-accent underline">
            Vytvoriť makrá
        </a>
    </div>
</template>
