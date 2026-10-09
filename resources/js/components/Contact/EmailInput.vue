<script setup lang="ts">
import { computed, ref } from 'vue'
import InputText from 'primevue/inputtext'
import { isValidEmail } from '@/composables/companySettingsShared'

const props = withDefaults(defineProps<{
    modelValue?: string | null
    disabled?: boolean
    error?: string | null
}>(), {
    modelValue: null,
    disabled: false,
    error: null,
})

const emit = defineEmits<{
    'update:modelValue': [value: string]
}>()

const touched = ref(false)

const email = computed({
    get: () => props.modelValue ?? '',
    set: (value: string) => emit('update:modelValue', value),
})

const localError = computed(() => {
    const value = email.value.trim()

    if (!touched.value || !value || isValidEmail(value)) {
        return null
    }

    return 'Zadajte platnú emailovú adresu.'
})

const displayedError = computed(() => props.error || localError.value)

function validate() {
    touched.value = true
    const normalized = email.value.trim()

    if (normalized !== email.value) {
        emit('update:modelValue', normalized)
    }
}
</script>

<template>
    <div class="w-full">
        <InputText
            v-model="email"
            type="email"
            autocomplete="email"
            inputmode="email"
            maxlength="255"
            fluid
            :disabled="disabled"
            :invalid="Boolean(displayedError)"
            :class="{ 'opacity-50!': disabled }"
            @blur="validate"
        />

        <small v-if="displayedError" class="text-danger">
            {{ displayedError }}
        </small>
    </div>
</template>
