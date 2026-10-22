<script setup lang="ts">
import Textarea from 'primevue/textarea'

import type { DekurzMacro } from '@/types/dekurz'

withDefaults(
    defineProps<{
        modelValue: string
        macros: DekurzMacro[]
        placeholder?: string
        disabled?: boolean
    }>(),
    {
        placeholder: '',
        disabled: false,
    },
)

const emit = defineEmits<{
    'update:modelValue': [value: string]
    blur: []
}>()
</script>

<template>
    <Textarea
        :model-value="modelValue"
        rows="1"
        :placeholder="placeholder"
        :disabled="disabled"
        auto-resize
        class="
            w-full!
            resize-none!
            overflow-hidden!
            border-0!
            p-0!
        "
        @update:model-value="
            emit(
                'update:modelValue',
                String($event ?? ''),
            )
        "
        @blur="emit('blur')"
    />
</template>
