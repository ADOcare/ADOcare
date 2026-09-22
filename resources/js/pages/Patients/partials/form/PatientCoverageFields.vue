<script setup lang="ts">
import { computed } from 'vue'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import type { PatientCoverageForm } from './patientCoverage'

type InsuranceCompanyOption = {
    id: number
    name: string
}

const props = withDefaults(defineProps<{
    modelValue: PatientCoverageForm
    insuranceCompanies: InsuranceCompanyOption[]
    errors?: Record<string, string>
    allowUnclassified?: boolean
    disabled?: boolean
}>(), {
    errors: () => ({}),
    allowUnclassified: false,
    disabled: false,
})

const emit = defineEmits<{
    'update:modelValue': [value: PatientCoverageForm]
    'clear-error': [key: string]
}>()

const regimeOptions = computed(() => {
    const options = [
        { value: 'domestic', label: 'Tuzemský poistenec' },
        { value: 'eu', label: 'Poistenec EÚ' },
        { value: 'special', label: 'Osobitná skupina' },
    ]

    if (props.allowUnclassified || props.modelValue.regime === 'unclassified') {
        options.push({ value: 'unclassified', label: 'Nezaradený – treba skontrolovať' })
    }

    return options
})

const specialCategoryOptions = [
    { value: 'homeless', label: 'Bezdomovec podľa § 9 ods. 4' },
    { value: 'non_eu_foreigner', label: 'Cudzinec mimo EÚ' },
    { value: 'statutory_entitlement_9_3', label: 'Nárok podľa § 9 ods. 3' },
]

function parseApiDate(value: string | null): Date | null {
    if (!value) {
        return null
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)

    if (!match) {
        return null
    }

    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
}

function formatApiDate(value: Date | null): string | null {
    if (!value) {
        return null
    }

    const year = value.getFullYear()
    const month = String(value.getMonth() + 1).padStart(2, '0')
    const day = String(value.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

const validFromDate = computed<Date | null>({
    get: () => parseApiDate(props.modelValue.valid_from),
    set: (value) => update('valid_from', formatApiDate(value)),
})

const validToDate = computed<Date | null>({
    get: () => parseApiDate(props.modelValue.valid_to),
    set: (value) => update('valid_to', formatApiDate(value)),
})

function update<K extends keyof PatientCoverageForm>(key: K, value: PatientCoverageForm[K]) {
    const next = {
        ...props.modelValue,
        [key]: value,
    }

    if (key === 'regime') {
        if (value === 'domestic') {
            next.member_state_code = null
            next.foreign_insured_id = null
            next.special_category = null
            next.entitlement_document_type = null
            next.entitlement_document_number = null
        } else if (value === 'eu') {
            next.special_category = null
        } else if (value === 'special') {
            next.member_state_code = null
        }
    }

    emit('update:modelValue', next)
    emit('clear-error', `coverage.${key}`)
}
</script>

<template>
    <section class="space-y-4">
        <div>
            <h3 class="font-medium text-darkgrey">Poistenie a režim úhrady</h3>
            <p class="text-sm text-gray-500">
                Režim vyberte podľa nároku na úhradu, nie podľa občianstva pacienta.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm">Režim úhrady *</label>
                <Select
                    :disabled="disabled"
                    :model-value="modelValue.regime"
                    :options="regimeOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Vyberte režim"
                    class="w-full"
                    @update:model-value="update('regime', $event)"
                />
                <small v-if="errors['coverage.regime']" class="text-danger">
                    {{ errors['coverage.regime'] }}
                </small>
            </div>

            <div>
                <label class="mb-1 block text-sm">Poisťovňa *</label>
                <Select
                    :disabled="disabled"
                    :model-value="modelValue.insurance_company_id"
                    :options="insuranceCompanies"
                    option-label="name"
                    option-value="id"
                    placeholder="Vyberte poisťovňu"
                    class="w-full"
                    @update:model-value="update('insurance_company_id', $event)"
                />
                <small v-if="errors['coverage.insurance_company_id']" class="text-danger">
                    {{ errors['coverage.insurance_company_id'] }}
                </small>
            </div>

            <template v-if="modelValue.regime === 'eu'">
                <div>
                    <label class="mb-1 block text-sm">Štát poistenia *</label>
                    <InputText
                        :disabled="disabled"
                        :model-value="modelValue.member_state_code"
                        maxlength="3"
                        placeholder="napr. CZ, PL, DE"
                        class="w-full uppercase"
                        @update:model-value="update('member_state_code', String($event || '').toUpperCase())"
                    />
                    <small v-if="errors['coverage.member_state_code']" class="text-danger">
                        {{ errors['coverage.member_state_code'] }}
                    </small>
                </div>

                <div>
                    <label class="mb-1 block text-sm">Identifikačné číslo poistenca *</label>
                    <InputText
                        :disabled="disabled"
                        :model-value="modelValue.foreign_insured_id"
                        maxlength="20"
                        class="w-full"
                        @update:model-value="update('foreign_insured_id', String($event || ''))"
                    />
                    <small v-if="errors['coverage.foreign_insured_id']" class="text-danger">
                        {{ errors['coverage.foreign_insured_id'] }}
                    </small>
                </div>
            </template>

            <div v-if="modelValue.regime === 'special'">
                <label class="mb-1 block text-sm">Kategória nároku *</label>
                <Select
                    :disabled="disabled"
                    :model-value="modelValue.special_category"
                    :options="specialCategoryOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Vyberte kategóriu"
                    class="w-full"
                    @update:model-value="update('special_category', $event)"
                />
                <small v-if="errors['coverage.special_category']" class="text-danger">
                    {{ errors['coverage.special_category'] }}
                </small>
            </div>

            <template v-if="modelValue.regime === 'eu' || modelValue.regime === 'special'">
                <div>
                    <label class="mb-1 block text-sm">Druh dokladu</label>
                    <InputText
                        :disabled="disabled"
                        :model-value="modelValue.entitlement_document_type"
                        placeholder="napr. EHIC"
                        class="w-full"
                        @update:model-value="update('entitlement_document_type', String($event || ''))"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-sm">Číslo dokladu</label>
                    <InputText
                        :disabled="disabled"
                        :model-value="modelValue.entitlement_document_number"
                        class="w-full"
                        @update:model-value="update('entitlement_document_number', String($event || ''))"
                    />
                </div>
            </template>

            <div>
                <label class="mb-1 block text-sm">Platnosť od</label>
                <DatePicker
                    :disabled="disabled"
                    v-model="validFromDate"
                    date-format="dd.mm.yy"
                    :manual-input="false"
                    :max-date="validToDate ?? undefined"
                    class="w-full"
                    input-class="w-full!"
                />
            </div>

            <div>
                <label class="mb-1 block text-sm">Platnosť do</label>
                <DatePicker
                    :disabled="disabled"
                    v-model="validToDate"
                    date-format="dd.mm.yy"
                    :manual-input="false"
                    :min-date="validFromDate ?? undefined"
                    class="w-full"
                    input-class="w-full!"
                />
                <small v-if="errors['coverage.valid_to']" class="text-danger">
                    {{ errors['coverage.valid_to'] }}
                </small>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <Checkbox
                :disabled="disabled"
                input-id="coverage-is-verified"
                :model-value="modelValue.is_verified"
                :binary="true"
                @update:model-value="update('is_verified', Boolean($event))"
            />
            <label for="coverage-is-verified" class="text-sm">
                Údaje o poistení boli skontrolované
            </label>
        </div>
    </section>
</template>
