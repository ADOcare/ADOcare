<script setup lang="ts" generic="T = unknown">
import { computed, useAttrs, useId } from 'vue'
import type { Component, StyleValue } from 'vue'
import AutoComplete from 'primevue/autocomplete'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import InputMask from 'primevue/inputmask'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import RadioButton from 'primevue/radiobutton'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'

defineOptions({ inheritAttrs: false })

type FormControl =
    | 'autocomplete'
    | 'checkbox'
    | 'date'
    | 'mask'
    | 'multiselect'
    | 'number'
    | 'password'
    | 'radio'
    | 'select'
    | 'text'
    | 'textarea'
    | 'toggle'

const props = withDefaults(defineProps<{
    control?: FormControl
    controlClass?: unknown
    disabled?: boolean
    error?: string | null | false
    fluid?: boolean
    help?: string
    id?: string
    label?: string
    modelModifiers?: {
        number?: boolean
        trim?: boolean
    }
    modelValue?: T
    outlined?: boolean
    readonly?: boolean
    required?: boolean
    type?: string
}>(), {
    control: 'text',
    controlClass: undefined,
    disabled: false,
    error: null,
    fluid: true,
    help: undefined,
    id: undefined,
    label: undefined,
    modelModifiers: () => ({}),
    modelValue: undefined,
    outlined: true,
    readonly: false,
    required: false,
    type: 'text',
})

const emit = defineEmits<{
    'update:modelValue': [value: T]
}>()

defineSlots<{
    default?: (props: {
        describedBy: string | undefined
        disabled: boolean
        error: string | null | false
        id: string
        invalid: boolean
        outlined: boolean
        readonly: boolean
    }) => unknown
    label?: () => unknown
}>()

const attrs = useAttrs()
const rootStyle = computed(() => attrs.style as StyleValue)
const controlAttrs = computed(() => Object.fromEntries(
    Object.entries(attrs).filter(([name]) => name !== 'class' && name !== 'style'),
))
const generatedId = useId()
const fieldId = computed(() => props.id ?? `field-${generatedId}`)
const errorId = computed(() => `${fieldId.value}-error`)
const helpId = computed(() => `${fieldId.value}-help`)
const invalid = computed(() => Boolean(props.error))
const describedBy = computed(() => {
    const ids = [
        attrs['aria-describedby'],
        invalid.value ? errorId.value : null,
        props.help ? helpId.value : null,
    ].filter(Boolean)

    return ids.length ? ids.join(' ') : undefined
})

const controlComponents: Record<FormControl, Component> = {
    autocomplete: AutoComplete,
    checkbox: Checkbox,
    date: DatePicker,
    mask: InputMask,
    multiselect: Select,
    number: InputNumber,
    password: Password,
    radio: RadioButton,
    select: Select,
    text: InputText,
    textarea: Textarea,
    toggle: ToggleSwitch,
}

const controlComponent = computed(() => controlComponents[props.control])

function updateModelValue(value: T) {
    let nextValue: unknown = value

    if (props.modelModifiers.trim && typeof nextValue === 'string') {
        nextValue = nextValue.trim()
    }

    if (props.modelModifiers.number && typeof nextValue === 'string') {
        const numericValue = Number(nextValue)
        nextValue = Number.isNaN(numericValue) ? nextValue : numericValue
    }

    emit('update:modelValue', nextValue as T)
}
</script>

<template>
    <div
        :class="['flex w-full flex-col gap-1', $attrs.class]"
        :style="rootStyle"
    >
        <label
            v-if="label || $slots.label"
            :for="fieldId"
            :class="[
                'block text-normal',
                disabled ? 'opacity-50!' : '',
            ]"
        >
            <slot name="label">{{ label }}</slot>
            <span v-if="required" class="text-danger" aria-hidden="true"> *</span>
        </label>

        <slot
            v-if="$slots.default"
            :id="fieldId"
            :described-by="describedBy"
            :disabled="disabled"
            :readonly="readonly"
            :invalid="invalid"
            :outlined="outlined"
            :error="error"
        />

        <component
            :is="controlComponent"
            v-else
            v-bind="controlAttrs"
            :id="fieldId"
            :input-id="fieldId"
            :model-value="modelValue"
            :multiple="control === 'multiselect' ? true : undefined"
            :type="control === 'text' ? type : undefined"
            :disabled="disabled"
            :readonly="readonly"
            :invalid="invalid"
            :fluid="fluid"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedBy"
            :class="[
                controlClass,
                outlined ? '' : 'border-none! shadow-none! ring-0! outline-none!',
                disabled ? 'opacity-50!' : '',
            ]"
            @update:model-value="updateModelValue"
        />

        <small
            v-if="error"
            :id="errorId"
            class="text-danger"
            role="alert"
        >
            {{ error }}
        </small>

        <small
            v-else-if="help"
            :id="helpId"
            class="text-mini text-accent"
        >
            {{ help }}
        </small>
    </div>
</template>