<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'

import type { PatientCoverageForm } from '@/composables/patientCoverage'
import api from '@/services/api'

type InsuranceCompanyOption = {
    id: number
    name: string
}

type CountryOption = {
    id: number
    name: string
    code: string
}

const props = withDefaults(defineProps<{
    modelValue: PatientCoverageForm
    insuranceCompanies: InsuranceCompanyOption[]
    errors?: Record<string, string>
    disabled?: boolean
    personalNumber?: string | null
    sex?: string | null
    verifying?: boolean
    loadingInsurance?: boolean
    canLoadInsurance?: boolean
    canVerifyInsurance?: boolean
    verificationHasUnsavedChanges?: boolean
}>(), {
    errors: () => ({}),
    disabled: false,
    personalNumber: null,
    sex: null,
    verifying: false,
    loadingInsurance: false,
    canLoadInsurance: false,
    canVerifyInsurance: false,
    verificationHasUnsavedChanges: false,
})

const emit = defineEmits<{
    'update:modelValue': [value: PatientCoverageForm]
    'update:personalNumber': [value: string]
    'clear-error': [key: string]
    'verify': []
    'load-insurance': []
}>()

const countries = ref<CountryOption[]>([])
const countriesLoading = ref(false)

const euCountryCodes = new Set([
    'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
    'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SI',
    'ES', 'SE', 'IS', 'LI', 'NO', 'CH', 'RS', 'MK', 'ME',
])

const categoryOptions = [
    { value: 'domestic', label: 'Tuzemský poistenec' },
    { value: 'eu', label: 'Poistenec EÚ, EHP alebo Švajčiarska' },
    { value: 'special', label: 'Osobitná kategória' },
]

const specialCategoryOptions = [
    { value: 'homeless', label: 'Bezdomovec s nárokom' },
    { value: 'non_eu_foreigner', label: 'Cudzinec mimo EÚ' },
    { value: 'statutory_entitlement_9_3', label: 'Osoba podľa § 9 ods. 3' },
]

const identificationMethodOptions = [
    { value: 'slovak_identifier', label: 'Rodné číslo / BIČ' },
    { value: 'foreign_triad', label: 'Zahraničné identifikačné údaje' },
]

const isDomestic = computed(() => props.modelValue.category === 'domestic')
const isEu = computed(() => props.modelValue.category === 'eu')
const isSpecial = computed(() => props.modelValue.category === 'special')
const usesSlovakIdentifier = computed(() => (
    props.modelValue.identification_method === 'slovak_identifier'
))
const usesForeignIdentification = computed(() => (
    props.modelValue.identification_method === 'foreign_triad'
))

const slovakIdentifierError = computed(() => (
    props.errors.personal_number || props.errors.bic || null
))

const countryOptions = computed(() => countries.value
    .filter((country) => {
        const code = country.code.toUpperCase()

        if (code === 'SK') {
            return false
        }

        return !isEu.value || euCountryCodes.has(code)
    })
    .map((country) => ({
        ...country,
        code: country.code.toUpperCase(),
        label: `${country.name} (${country.code.toUpperCase()})`,
    }))
    .sort((left, right) => left.name.localeCompare(right.name, 'sk')))

const showInsuranceActions = computed(() => isDomestic.value)

function emitCoverage(next: PatientCoverageForm, errorKeys: string[] = []) {
    emit('update:modelValue', next)
    errorKeys.forEach((key) => emit('clear-error', `coverage.${key}`))
}

function selectCategory(category: PatientCoverageForm['category']) {
    const next: PatientCoverageForm = {
        ...props.modelValue,
        category,
        identification_method: category === 'domestic'
            ? 'slovak_identifier'
            : category === 'eu' ? 'foreign_triad' : null,
        special_category: null,
        member_state_code: null,
        foreign_insured_id: null,
        is_verified: false,
    }

    if (category === 'eu') {
        emit('update:personalNumber', '')
    }

    emitCoverage(next, [
        'category',
        'identification_method',
        'special_category',
        'member_state_code',
        'foreign_insured_id',
    ])
}

function updateField<K extends keyof PatientCoverageForm>(
    key: K,
    value: PatientCoverageForm[K],
) {
    emitCoverage({
        ...props.modelValue,
        [key]: value,
        is_verified: key === 'insurance_company_id'
            ? false
            : props.modelValue.is_verified,
    }, [String(key)])
}

function selectIdentificationMethod(
    method: PatientCoverageForm['identification_method'],
) {
    const next = {
        ...props.modelValue,
        identification_method: method,
        member_state_code: null,
        foreign_insured_id: null,
        is_verified: false,
    }

    if (method === 'foreign_triad') {
        emit('update:personalNumber', '')
    }

    emitCoverage(next, [
        'identification_method',
        'member_state_code',
        'foreign_insured_id',
    ])
}

function updateSlovakIdentifier(value: string | null) {
    const identifier = String(value || '')
    const normalized = /[A-Za-z]/.test(identifier)
        ? identifier.replace(/\s+/g, '').toUpperCase()
        : identifier.replace(/\D+/g, '')

    emit('update:personalNumber', normalized)
    clearIdentifierErrors()
}

function clearIdentifierErrors() {
    emit('clear-error', 'personal_number')
    emit('clear-error', 'bic')

    if (props.modelValue.is_verified) {
        emitCoverage({ ...props.modelValue, is_verified: false })
    }
}

function selectCountry(code: string | null) {
    updateField('member_state_code', code ? String(code).toUpperCase() : null)
}

async function loadCountries() {
    countriesLoading.value = true

    try {
        const response = await api.get('/v1/countries')
        const payload = response.data?.data
        countries.value = Array.isArray(payload) ? payload : (payload?.items ?? [])
    } catch (error) {
        console.error('Failed to load countries', error)
    } finally {
        countriesLoading.value = false
    }
}

onMounted(loadCountries)
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12">
                <label class="block text-normal text-accent">
                    Poistenie pacienta
                </label>
            </div>

            <!-- TUZEMSKÝ POISTENEC -->
            <template v-if="!modelValue.category || isDomestic">
                <div class="col-span-4">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Typ poistenia
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.category"
                        :options="categoryOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Vyberte kategóriu poistenca"
                        fluid
                        :invalid="Boolean(errors['coverage.category'])"
                        :class="[
                            'w-full!',
                            { 'opacity-50!': disabled },
                        ]"
                        @update:model-value="selectCategory"
                    />

                    <small
                        v-if="errors['coverage.category']"
                        class="text-danger"
                    >
                        {{ errors['coverage.category'] }}
                    </small>
                </div>

                <template v-if="isDomestic">
                    <div class="col-span-2">
                        <label
                            :class="[
                                'block text-normal mb-1',
                                disabled && 'opacity-50!',
                            ]"
                        >
                            Rodné číslo alebo BIČ
                        </label>

                        <InputText
                            :disabled="disabled"
                            :model-value="personalNumber"
                            maxlength="20"
                            fluid
                            :invalid="Boolean(slovakIdentifierError)"
                            @update:model-value="
                                updateSlovakIdentifier(
                                    String($event || ''),
                                )
                            "
                        />

                        <small
                            v-if="slovakIdentifierError"
                            class="text-danger"
                        >
                            {{ slovakIdentifierError }}
                        </small>
                    </div>

                    <div
                        v-if="showInsuranceActions"
                        class="col-span-2"
                    >
                        <label
                            class="block text-normal mb-1 invisible select-none"
                            aria-hidden="true"
                        >
                            Akcia
                        </label>

                        <Button
                            type="button"
                            label="Načítať poistenie"
                            :loading="loadingInsurance"
                            :disabled="
                                disabled
                                    || !canLoadInsurance
                                    || loadingInsurance
                            "
                            class="w-full! h-7! bg-accent! text-white! text-normal! rounded-md hover:bg-darkgrey! border-0!"
                            @click="emit('load-insurance')"
                        />
                    </div>

                    <div class="col-span-4">
                        <label
                            :class="[
                                'block text-normal mb-1',
                                disabled && 'opacity-50!',
                            ]"
                        >
                            Zdravotná poisťovňa
                        </label>

                        <Select
                            :disabled="disabled"
                            :model-value="modelValue.insurance_company_id"
                            :options="insuranceCompanies"
                            option-label="name"
                            option-value="id"
                            placeholder="Vyberte poisťovňu"
                            fluid
                            :invalid="
                                Boolean(
                                    errors[
                                        'coverage.insurance_company_id'
                                    ],
                                )
                            "
                            :class="{ 'opacity-50!': disabled }"
                            @update:model-value="
                                updateField(
                                    'insurance_company_id',
                                    $event,
                                )
                            "
                        />

                        <small
                            v-if="
                                errors[
                                    'coverage.insurance_company_id'
                                ]
                            "
                            class="text-danger"
                        >
                            {{
                                errors[
                                    'coverage.insurance_company_id'
                                ]
                            }}
                        </small>
                    </div>
                </template>
            </template>

            <!-- EÚ / EHP / ŠVAJČIARSKO -->
            <template v-else-if="isEu">
                <div class="col-span-4">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Typ poistenia
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.category"
                        :options="categoryOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Vyberte kategóriu poistenca"
                        fluid
                        :invalid="Boolean(errors['coverage.category'])"
                        @update:model-value="selectCategory"
                    />

                    <small
                        v-if="errors['coverage.category']"
                        class="text-danger"
                    >
                        {{ errors['coverage.category'] }}
                    </small>
                </div>

                <div class="col-span-4">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Slovenská vykazujúca poisťovňa
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.insurance_company_id"
                        :options="insuranceCompanies"
                        option-label="name"
                        option-value="id"
                        placeholder="Vyberte poisťovňu"
                        fluid
                        :invalid="
                            Boolean(
                                errors[
                                    'coverage.insurance_company_id'
                                ],
                            )
                        "
                        @update:model-value="
                            updateField(
                                'insurance_company_id',
                                $event,
                            )
                        "
                    />

                    <small
                        v-if="
                            errors[
                                'coverage.insurance_company_id'
                            ]
                        "
                        class="text-danger"
                    >
                        {{
                            errors[
                                'coverage.insurance_company_id'
                            ]
                        }}
                    </small>
                </div>

                <div class="col-span-2">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Štát poistenia
                    </label>

                    <Select
                        :disabled="disabled"
                        :loading="countriesLoading"
                        :model-value="modelValue.member_state_code"
                        :options="countryOptions"
                        option-label="label"
                        option-value="code"
                        filter
                        placeholder="Vyberte štát"
                        fluid
                        :invalid="
                            Boolean(
                                errors[
                                    'coverage.member_state_code'
                                ],
                            )
                        "
                        @update:model-value="selectCountry"
                    />

                    <small
                        v-if="
                            errors[
                                'coverage.member_state_code'
                            ]
                        "
                        class="text-danger"
                    >
                        {{
                            errors[
                                'coverage.member_state_code'
                            ]
                        }}
                    </small>
                </div>

                <div class="col-span-2">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Identifikačné číslo poistenca
                    </label>

                    <InputText
                        :disabled="disabled"
                        :model-value="modelValue.foreign_insured_id"
                        maxlength="20"
                        fluid
                        :invalid="
                            Boolean(
                                errors[
                                    'coverage.foreign_insured_id'
                                ],
                            )
                        "
                        @update:model-value="
                            updateField(
                                'foreign_insured_id',
                                String($event || ''),
                            )
                        "
                    />

                    <small
                        v-if="
                            errors[
                                'coverage.foreign_insured_id'
                            ]
                        "
                        class="block text-danger"
                    >
                        {{
                            errors[
                                'coverage.foreign_insured_id'
                            ]
                        }}
                    </small>
                </div>
            </template>

            <!-- OSOBITNÁ KATEGÓRIA -->
            <template v-else-if="isSpecial">
                <!-- Prvý riadok: klasifikácia pacienta -->
                <div class="col-span-4">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Typ poistenia
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.category"
                        :options="categoryOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Vyberte kategóriu poistenca"
                        fluid
                        :invalid="Boolean(errors['coverage.category'])"
                        @update:model-value="selectCategory"
                    />
                </div>

                <div class="col-span-4">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Slovenská vykazujúca poisťovňa
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.insurance_company_id"
                        :options="insuranceCompanies"
                        option-label="name"
                        option-value="id"
                        placeholder="Vyberte poisťovňu"
                        fluid
                        :invalid="
                            Boolean(
                                errors[
                                    'coverage.insurance_company_id'
                                ],
                            )
                        "
                        @update:model-value="
                            updateField(
                                'insurance_company_id',
                                $event,
                            )
                        "
                    />
                </div>

                <div class="col-span-2">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Osobitná kategória
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.special_category"
                        :options="specialCategoryOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Vyberte kategóriu"
                        fluid
                        :invalid="
                            Boolean(
                                errors[
                                    'coverage.special_category'
                                ],
                            )
                        "
                        @update:model-value="
                            updateField(
                                'special_category',
                                $event,
                            )
                        "
                    />

                    <small
                        v-if="
                            errors[
                                'coverage.special_category'
                            ]
                        "
                        class="text-danger"
                    >
                        {{
                            errors[
                                'coverage.special_category'
                            ]
                        }}
                    </small>
                </div>

                <div class="col-span-2">
                    <label
                        :class="[
                            'block text-normal mb-1',
                            disabled && 'opacity-50!',
                        ]"
                    >
                        Spôsob identifikácie
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.identification_method"
                        :options="identificationMethodOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Vyberte spôsob"
                        fluid
                        :invalid="
                            Boolean(
                                errors[
                                    'coverage.identification_method'
                                ],
                            )
                        "
                        @update:model-value="
                            selectIdentificationMethod
                        "
                    />

                    <small
                        v-if="
                            errors[
                                'coverage.identification_method'
                            ]
                        "
                        class="text-danger"
                    >
                        {{
                            errors[
                                'coverage.identification_method'
                            ]
                        }}
                    </small>
                </div>

                <!-- Druhý riadok: konkrétna identifikácia -->
                <template v-if="usesSlovakIdentifier">
                    <div class="col-span-4">
                        <label
                            :class="[
                                'block text-normal mb-1',
                                disabled && 'opacity-50!',
                            ]"
                        >
                            Rodné číslo alebo BIČ
                        </label>

                        <InputText
                            :disabled="disabled"
                            :model-value="personalNumber"
                            maxlength="20"
                            fluid
                            :invalid="
                                Boolean(slovakIdentifierError)
                            "
                            @update:model-value="
                                updateSlovakIdentifier(
                                    String($event || ''),
                                )
                            "
                        />

                        <small
                            v-if="slovakIdentifierError"
                            class="text-danger"
                        >
                            {{ slovakIdentifierError }}
                        </small>
                    </div>
                </template>

                <template v-else-if="usesForeignIdentification">
                    <div class="col-span-4">
                        <label
                            :class="[
                                'block text-normal mb-1',
                                disabled && 'opacity-50!',
                            ]"
                        >
                            Štát poistenia
                        </label>

                        <Select
                            :disabled="disabled"
                            :loading="countriesLoading"
                            :model-value="modelValue.member_state_code"
                            :options="countryOptions"
                            option-label="label"
                            option-value="code"
                            filter
                            placeholder="Vyberte štát"
                            fluid
                            :invalid="
                                Boolean(
                                    errors[
                                        'coverage.member_state_code'
                                    ],
                                )
                            "
                            @update:model-value="selectCountry"
                        />

                        <small
                            v-if="
                                errors[
                                    'coverage.member_state_code'
                                ]
                            "
                            class="text-danger"
                        >
                            {{
                                errors[
                                    'coverage.member_state_code'
                                ]
                            }}
                        </small>
                    </div>

                    <div class="col-span-4">
                        <label
                            :class="[
                                'block text-normal mb-1',
                                disabled && 'opacity-50!',
                            ]"
                        >
                            Identifikačné číslo poistenca
                        </label>

                        <InputText
                            :disabled="disabled"
                            :model-value="
                                modelValue.foreign_insured_id
                            "
                            maxlength="20"
                            fluid
                            :invalid="
                                Boolean(
                                    errors[
                                        'coverage.foreign_insured_id'
                                    ],
                                )
                            "
                            @update:model-value="
                                updateField(
                                    'foreign_insured_id',
                                    String($event || ''),
                                )
                            "
                        />

                        <small
                            v-if="
                                errors[
                                    'coverage.foreign_insured_id'
                                ]
                            "
                            class="block text-danger"
                        >
                            {{
                                errors[
                                    'coverage.foreign_insured_id'
                                ]
                            }}
                        </small>
                    </div>
                </template>
            </template>
        </div>
    </div>
</template>
