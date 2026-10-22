<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import type { PropType } from 'vue'

const props = defineProps({
    show: { type: Boolean as PropType<boolean>, default: true },
    severity: { type: String as PropType<'success' | 'info' | 'warn' | 'error'>, default: 'info' },
    message: { type: String as PropType<string>, default: '' },
    persistent: { type: Boolean as PropType<boolean>, default: false },
    duration: { type: Number as PropType<number>, default: 5000 },
    closable: { type: Boolean as PropType<boolean>, default: true },
})

const emit = defineEmits<{
    (e: 'close'): void
}>()

const visible = ref(props.show)
let autoTimeout: ReturnType<typeof setTimeout> | null = null

const severityClasses = computed(() => {
    switch (props.severity) {
        case 'success': return 'bg-green-50 border-green-300 text-green-800'
        case 'warn': return 'bg-yellow-50 border-yellow-300 text-yellow-800'
        case 'error': return 'bg-red-50 border-red-300 text-red-800'
        default: return 'bg-blue-50 border-blue-300 text-blue-800'
    }
})

watch(() => props.show, (v) => {
    visible.value = v
    handleAutoDismiss()
})

function close() {
    visible.value = false
    emit('close')
}

function handleAutoDismiss() {
    if (autoTimeout) {
        clearTimeout(autoTimeout)
        autoTimeout = null
    }
    if (!props.persistent && props.show && props.duration > 0) {
        autoTimeout = setTimeout(() => {
            close()
        }, props.duration)
    }
}

onMounted(() => {
    handleAutoDismiss()
})
</script>

<template>
    <div v-if="visible" :class="`border rounded p-3 flex items-start gap-3 ${severityClasses}`" role="status"
        aria-live="polite">
        <div class="shrink-0">
            <svg v-if="severity === 'success'" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor"
                aria-hidden="true">
                <path fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414L9 13.414l4-4z"
                    clip-rule="evenodd" />
            </svg>
            <svg v-else-if="severity === 'error'" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor"
                aria-hidden="true">
                <path fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-5V7a1 1 0 112 0v6a1 1 0 11-2 0zM9 15a1 1 0 102 0 1 1 0 00-2 0z"
                    clip-rule="evenodd" />
            </svg>
            <svg v-else-if="severity === 'warn'" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor"
                aria-hidden="true">
                <path fill-rule="evenodd"
                    d="M8.257 3.099c.765-1.36 2.68-1.36 3.445 0l5.518 9.8c.75 1.333-.214 2.992-1.722 2.992H4.46c-1.508 0-2.472-1.659-1.722-2.992l5.519-9.8zM11 13a1 1 0 10-2 0 1 1 0 002 0zm-1-4a1 1 0 01.993.883L11 10a1 1 0 11-2 0V9a1 1 0 011-1z"
                    clip-rule="evenodd" />
            </svg>
            <svg v-else class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M2 5a2 2 0 012-2h12a2 2 0 012 2v8a2 2 0 01-2 2H7l-5 4V5z" />
            </svg>
        </div>

        <div class="flex-1">
            <slot>
                <div class="font-medium">{{ message }}</div>
            </slot>
            <div v-if="$slots.detail" class="mt-1 text-sm opacity-90">
                <slot name="detail" />
            </div>
        </div>

        <div v-if="closable" class="shrink-0 ml-3">
            <button class="inline-flex rounded p-1 hover:bg-black/5" aria-label="Close" @click="close">
                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 011.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                        clip-rule="evenodd" />
                </svg>
            </button>
        </div>
    </div>
</template>
