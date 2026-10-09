<script setup lang="ts">
import { computed, watch } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import {
    getCountries,
    getCountryCallingCode,
    type CountryCode,
} from 'libphonenumber-js'

const DEFAULT_COUNTRY_CODE = '+421'

const props = withDefaults(defineProps<{
    modelValue?: string | null
    countryCode?: string | null
    disabled?: boolean
    invalid?: boolean
}>(), {
    modelValue: null,
    countryCode: null,
    disabled: false,
    invalid: false,
})

const emit = defineEmits<{
    'update:modelValue': [value: string]
    'update:countryCode': [value: string]
}>()

const displayNames = new Intl.DisplayNames(['sk'], { type: 'region' })

const countryCodeOptions = Array.from(
    getCountries().reduce((options, country) => {
        const callingCode = `+${getCountryCallingCode(country as CountryCode)}`

        if (!options.has(callingCode)) {
            options.set(callingCode, {
                value: callingCode,
                label: `${callingCode} – ${displayNames.of(country) ?? country}`,
            })
        }

        return options
    }, new Map<string, { value: string, label: string }>()).values(),
).sort((left, right) => left.label.localeCompare(right.label, 'sk', { numeric: true }))

const selectedCountryCode = computed({
    get: () => props.countryCode || DEFAULT_COUNTRY_CODE,
    set: (value: string) => emit('update:countryCode', value),
})

const phoneNumber = computed({
    get: () => props.modelValue ?? '',
    set: (value: string) => emit('update:modelValue', value.replace(/[^\d\s()-]/g, '')),
})

watch(
    () => props.countryCode,
    (value) => {
        if (!value) {
            emit('update:countryCode', DEFAULT_COUNTRY_CODE)
        }
    },
    { immediate: true },
)
</script>

<template>
    <div class="flex w-full gap-2">
        <Select
            v-model="selectedCountryCode"
            :options="countryCodeOptions"
            option-label="label"
            option-value="value"
            filter
            :disabled="disabled"
            :invalid="invalid"
            class="w-48 shrink-0"
        />

        <InputText
            v-model="phoneNumber"
            :disabled="disabled"
            :invalid="invalid"
            inputmode="tel"
            autocomplete="tel-national"
            placeholder="Telefónne číslo"
            maxlength="30"
            fluid
            :class="{ 'opacity-50!': disabled }"
        />
    </div>
</template>
