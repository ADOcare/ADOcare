<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'

import api from '@/services/api'
import type { PatientCoverageForm } from '@/composables/patientCoverage'

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
    bic?: string | null
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
    bic: null,
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
    'update:bic': [value: string]
    'clear-error': [key: string]
    'verify': []
    'load-insurance': []
}>()

const countries = ref<CountryOption[]>([])
const countriesLoading = ref(false)

const euCountryCodes = new Set([
    'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
    'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SI',
    'ES', 'SE', 'IS', 'LI', 'NO', 'CH',
])

const treatyCountryCodes = new Set(['RS', 'MK', 'ME'])

const categoryOptions = [
    { value: 'domestic', label: 'Tuzemský poistenec' },
    { value: 'eu', label: 'Poistenec EÚ, EHP alebo Švajčiarska' },
    { value: 'non_eu', label: 'Poistenec mimo EÚ' },
    { value: 'homeless', label: 'Bezdomovec s potvrdeným nárokom' },
    { value: 'other', label: 'Iná osobitná situácia' },
]

const nonEuPathOptions = [
    { value: 'treaty_document', label: 'Má nárokový doklad zmluvného štátu' },
    { value: 'special_entitlement', label: 'Má potvrdený osobitný nárok na úhradu' },
    { value: 'unconfirmed', label: 'Nárok zatiaľ nie je potvrdený' },
]

const otherSituationOptions = [
    {
        value: 'statutory_entitlement_9_3',
        label: 'Osoba s potvrdeným nárokom podľa § 9 ods. 3',
    },
    {
        value: 'temporary_sk_card_foreign',
        label: 'Dočasný slovenský preukaz s potvrdeným zahraničným nárokom',
    },
    {
        value: 'manager_review',
        label: 'Iná alebo nejasná situácia – posúdi manažér',
    },
]

const euDocumentOptions = [
    {
        value: 'EHIC',
        label: 'Európsky preukaz zdravotného poistenia (EPZP/EHIC)',
    },
    {
        value: 'REPLACEMENT_CERTIFICATE',
        label: 'Náhradný certifikát k EPZP',
    },
    {
        value: 'S1',
        label: 'Formulár S1',
    },
    {
        value: 'S2',
        label: 'Formulár S2',
    },
    {
        value: 'OTHER_ENTITLEMENT_DOCUMENT',
        label: 'Iný potvrdený nárokový doklad',
    },
]

const treatyDocuments: Record<string, Array<{ value: string, label: string }>> = {
    RS: [
        { value: 'SRB/SK 111', label: 'SRB/SK 111' },
        { value: 'SRB/SK 123', label: 'SRB/SK 123' },
    ],
    MK: [
        { value: 'RM/SK 111', label: 'RM/SK 111' },
        { value: 'RM/SK 112', label: 'RM/SK 112' },
        { value: 'RM/SK 123', label: 'RM/SK 123' },
    ],
    ME: [
        { value: 'MNE/SK 111', label: 'MNE/SK 111' },
        { value: 'MNE/SK 112', label: 'MNE/SK 112' },
        { value: 'MNE/SK 123', label: 'MNE/SK 123' },
    ],
}

const isDomestic = computed(() => props.modelValue.category === 'domestic')
const isEu = computed(() => props.modelValue.category === 'eu')
const isNonEu = computed(() => props.modelValue.category === 'non_eu')
const isOther = computed(() => props.modelValue.category === 'other')

const hasPersonalNumber = computed(() => !!props.personalNumber?.trim())
const hasBic = computed(() => !!props.bic?.trim())

const slovakIdentifierError = computed(() => {
    return props.errors.bic
        || props.errors.personal_number
        || props.errors['coverage.bic']
        || null
})

const isTreatyCase = computed(() => {
    return props.modelValue.other_subtype === 'treaty_document'
})

const usesForeignIdentification = computed(() => {
    return props.modelValue.identification_method === 'foreign_triad'
})

const needsEntitlementDocument = computed(() => {
    return isEu.value
        || isTreatyCase.value
        || props.modelValue.other_subtype === 'temporary_sk_card_foreign'
})

const needsEntitlementConfirmation = computed(() => {
    return needsEntitlementDocument.value
})

const countryOptions = computed(() => {
    const options = countries.value
        .filter((country) => {
            const code = country.code.toUpperCase()

            if (isEu.value) {
                return euCountryCodes.has(code)
            }

            if (isNonEu.value) {
                return code !== 'SK' && !euCountryCodes.has(code)
            }

            return code !== 'SK'
        })
        .map((country) => ({
            ...country,
            label: `${country.name} (${country.code.toUpperCase()})`,
        }))

    return options.sort((left, right) => {
        return left.name.localeCompare(right.name, 'sk')
    })
})

const nonEuPath = computed(() => {
    if (props.modelValue.other_subtype === 'treaty_document') {
        return 'treaty_document'
    }

    if (props.modelValue.regime === 'special') {
        return 'special_entitlement'
    }

    return 'unconfirmed'
})

const documentOptions = computed(() => {
    if (props.modelValue.other_subtype === 'temporary_sk_card_foreign') {
        return [
            {
                value: 'TEMPORARY_SK_CARD',
                label: 'Dočasný slovenský preukaz',
            },
        ]
    }

    if (isTreatyCase.value) {
        const code = String(
            props.modelValue.member_state_code || '',
        ).toUpperCase()

        return treatyDocuments[code] ?? []
    }

    return euDocumentOptions
})

const verificationAvailable = computed(() => {
    return props.canVerifyInsurance
        && hasPersonalNumber.value
        && isDomestic.value
})

const showInsuranceActions = computed(() => {
    return isDomestic.value && !hasBic.value
})

const showLoadInsuranceAction = computed(() => showInsuranceActions.value)
const showVerifyInsuranceAction = computed(() => showInsuranceActions.value)

const insuranceTypeColumnClass = computed(() => {
    const visibleActions = Number(showLoadInsuranceAction.value)
        + Number(showVerifyInsuranceAction.value)

    if (visibleActions === 2) {
        return 'col-span-6'
    }

    if (visibleActions === 1) {
        return 'col-span-9'
    }

    return 'col-span-12'
})

const insuranceCompanyLabel = computed(() => {
    return isDomestic.value
        ? 'Zdravotná poisťovňa'
        : 'Slovenská vykazujúca poisťovňa'
})

function parseApiDate(value: string | null): Date | null {
    if (!value) {
        return null
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)

    if (!match) {
        return null
    }

    return new Date(
        Number(match[1]),
        Number(match[2]) - 1,
        Number(match[3]),
    )
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
    set: (value) => updateField('valid_from', formatApiDate(value)),
})

const validToDate = computed<Date | null>({
    get: () => parseApiDate(props.modelValue.valid_to),
    set: (value) => updateField('valid_to', formatApiDate(value)),
})

function emitCoverage(
    next: PatientCoverageForm,
    errorKeys: string[] = [],
) {
    emit('update:modelValue', next)

    errorKeys.forEach((key) => {
        emit('clear-error', `coverage.${key}`)
    })
}

function resetSharedCoverage(): PatientCoverageForm {
    return {
        ...props.modelValue,
        regime: null,
        identification_method: null,
        member_state_code: null,
        foreign_insured_id: null,
        special_category: null,
        other_subtype: null,
        legal_basis: null,
        entitlement_confirmed: false,
        document_registered: false,
        entitlement_document_type: null,
        entitlement_document_number: null,
        valid_from: null,
        valid_to: null,
        is_verified: false,
    }
}

function selectCategory(category: PatientCoverageForm['category']) {
    const next = {
        ...resetSharedCoverage(),
        category,
    }

    if (category === 'domestic') {
        next.regime = 'domestic'
        next.identification_method = 'slovak_identifier'
        next.entitlement_confirmed = false
        next.document_registered = false
    } else if (category === 'eu') {
        next.regime = 'eu'
        next.identification_method = 'foreign_triad'
        next.entitlement_confirmed = true
        next.document_registered = true
    } else if (category === 'homeless') {
        next.regime = 'special'
        next.identification_method = 'slovak_identifier'
        next.special_category = 'homeless'
        next.legal_basis = '§ 9 ods. 4'
        next.entitlement_confirmed = false
        next.document_registered = false
    } else if (category === 'non_eu') {
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
        next.other_subtype = 'unconfirmed'
        next.legal_basis = 'Nárok zatiaľ nie je potvrdený – export blokovaný'
        next.entitlement_confirmed = false
        next.document_registered = false
    } else {
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
        next.entitlement_confirmed = false
        next.document_registered = false
    }

    emitCoverage(
        next,
        ['category', 'regime', 'identification_method'],
    )
}

function updateField<K extends keyof PatientCoverageForm>(
    key: K,
    value: PatientCoverageForm[K],
) {
    const next = {
        ...props.modelValue,
        [key]: value,
    }

    if (key === 'insurance_company_id') {
        next.is_verified = false
    }

    emitCoverage(next, [String(key)])
}

function markInsuranceUnverified() {
    if (!props.modelValue.is_verified) {
        return
    }

    emitCoverage({
        ...props.modelValue,
        is_verified: false,
    })
}

function updatePersonalNumber(value: string | null) {
    const normalized = String(value || '').replace(/\D+/g, '')

    emit('update:personalNumber', normalized)

    if (normalized) {
        emit('update:bic', '')
    }

    emit('clear-error', 'personal_number')
    emit('clear-error', 'bic')

    markInsuranceUnverified()
}

function updateBic(value: string | null) {
    const normalized = String(value || '')
        .replace(/\s+/g, '')
        .toUpperCase()

    emit('update:bic', normalized)

    if (normalized) {
        emit('update:personalNumber', '')
    }

    emit('clear-error', 'personal_number')
    emit('clear-error', 'bic')

    markInsuranceUnverified()
}

function selectCountry(code: string | null) {
    const normalizedCode = code
        ? String(code).toUpperCase()
        : null

    const next = {
        ...props.modelValue,
        member_state_code: normalizedCode,
        entitlement_document_type: null,
        entitlement_document_number: null,
    }

    if (
        isTreatyCase.value
        && normalizedCode
        && !treatyCountryCodes.has(normalizedCode)
    ) {
        next.other_subtype = 'unconfirmed'
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
        next.legal_basis = 'Nárok zatiaľ nie je potvrdený – export blokovaný'
        next.entitlement_confirmed = false
        next.document_registered = false
    } else if (
        isEu.value
        || (
            isTreatyCase.value
            && normalizedCode
            && treatyCountryCodes.has(normalizedCode)
        )
        || props.modelValue.other_subtype === 'temporary_sk_card_foreign'
    ) {
        next.entitlement_confirmed = true
        next.document_registered = true
    }

    emitCoverage(
        next,
        ['member_state_code', 'entitlement_document_type'],
    )
}

function selectNonEuPath(path: string) {
    const next: PatientCoverageForm = {
        ...props.modelValue,
        other_subtype: path,
        entitlement_confirmed: false,
        document_registered: false,
        entitlement_document_type: null,
        entitlement_document_number: null,
        special_category: null,
        legal_basis: null,
    }

    if (path === 'treaty_document') {
        next.regime = 'eu'
        next.identification_method = 'foreign_triad'
        next.legal_basis = 'Medzinárodná zmluva – potvrdený nárokový doklad'

        const countryCode = String(
            next.member_state_code || '',
        ).toUpperCase()

        const validTreatyCountry = treatyCountryCodes.has(countryCode)

        next.entitlement_confirmed = validTreatyCountry
        next.document_registered = validTreatyCountry

        if (countryCode && !validTreatyCountry) {
            next.other_subtype = 'unconfirmed'
            next.regime = 'unclassified'
            next.identification_method = 'incomplete'
            next.legal_basis = 'Nárok zatiaľ nie je potvrdený – export blokovaný'
        }
    } else if (path === 'special_entitlement') {
        next.regime = 'special'
        next.identification_method = 'slovak_identifier'
        next.special_category = 'non_eu_foreigner'
        next.legal_basis = 'Potvrdený osobitný nárok na úhradu'
    } else {
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
        next.legal_basis = 'Nárok zatiaľ nie je potvrdený – export blokovaný'
    }

    emitCoverage(
        next,
        [
            'regime',
            'identification_method',
            'special_category',
            'legal_basis',
            'entitlement_confirmed',
        ],
    )
}

function selectOtherSituation(situation: string) {
    const next = {
        ...resetSharedCoverage(),
        category: 'other' as const,
        other_subtype: situation,
    }

    if (situation === 'statutory_entitlement_9_3') {
        next.regime = 'special'
        next.identification_method = 'slovak_identifier'
        next.special_category = 'statutory_entitlement_9_3'
        next.legal_basis = '§ 9 ods. 3'
    } else if (situation === 'temporary_sk_card_foreign') {
        next.regime = 'eu'
        next.identification_method = 'foreign_triad'
        next.legal_basis = 'Dočasný slovenský preukaz bez rodného čísla'
        next.entitlement_document_type = 'TEMPORARY_SK_CARD'
        next.entitlement_confirmed = true
        next.document_registered = true
    } else {
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
        next.legal_basis = 'Nejasná situácia – vyžaduje posúdenie manažérom'
    }

    emitCoverage(
        next,
        [
            'other_subtype',
            'regime',
            'identification_method',
            'entitlement_confirmed',
        ],
    )
}

function updateEntitlementConfirmed(checked: boolean) {
    emitCoverage({
        ...props.modelValue,
        entitlement_confirmed: checked,
        document_registered: checked,
    }, ['entitlement_confirmed'])
}

async function loadCountries() {
    countriesLoading.value = true

    try {
        const response = await api.get('/v1/countries')
        const payload = response.data?.data

        countries.value = Array.isArray(payload)
            ? payload
            : (payload?.items ?? [])
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

            <div class="col-span-12 grid grid-cols-12 gap-4">
                <div :class="insuranceTypeColumnClass">
                    <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
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

                <div
                    v-if="showLoadInsuranceAction"
                    class="col-span-3"
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
                        :disabled="disabled || !canLoadInsurance || loadingInsurance"
                        class="w-full! h-7! bg-accent! text-white! text-normal! rounded-md hover:bg-darkgrey! border-0!"
                        @click="emit('load-insurance')"
                    />
                </div>

                <div
                    v-if="showVerifyInsuranceAction"
                    class="col-span-3"
                >
                    <label
                        class="block text-normal mb-1 invisible select-none"
                        aria-hidden="true"
                    >
                        Akcia
                    </label>

                    <Button
                        type="button"
                        label="Overiť poistný vzťah"
                        :loading="verifying"
                        :disabled="disabled || !verificationAvailable || verifying"
                        class="w-full! h-7! bg-accent! text-white! text-normal! rounded-md hover:bg-darkgrey! border-0!"
                        @click="emit('verify')"
                    />
                </div>
            </div>

            <template v-if="modelValue.category">
                <div class="col-span-6">
                    <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                        {{ insuranceCompanyLabel }}
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.insurance_company_id"
                        :options="insuranceCompanies"
                        option-label="name"
                        option-value="id"
                        placeholder="Vyberte poisťovňu"
                        fluid
                        :invalid="Boolean(errors['coverage.insurance_company_id'])"
                        :class="{ 'opacity-50!': disabled }"
                        @update:model-value="updateField('insurance_company_id', $event)"
                    />

                    <small
                        v-if="errors['coverage.insurance_company_id']"
                        class="text-danger"
                    >
                        {{ errors['coverage.insurance_company_id'] }}
                    </small>
                </div>

                <template v-if="modelValue.identification_method === 'slovak_identifier'">
                    <div class="col-span-3">
                        <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                            Rodné číslo
                        </label>

                        <InputText
                            :disabled="disabled"
                            :model-value="personalNumber"
                            maxlength="10"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            fluid
                            :invalid="Boolean(slovakIdentifierError)"
                            :class="{
                                'bg-transparent!': disabled,
                                'opacity-50!': disabled,
                            }"
                            @update:model-value="updatePersonalNumber(String($event || ''))"
                        />
                    </div>

                    <div class="col-span-3">
                        <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                            BIČ
                        </label>

                        <InputText
                            :disabled="disabled"
                            :model-value="bic"
                            maxlength="10"
                            fluid
                            :invalid="Boolean(slovakIdentifierError)"
                            :class="{
                                'bg-transparent!': disabled,
                                'opacity-50!': disabled,
                            }"
                            @update:model-value="updateBic(String($event || ''))"
                        />

                        <small
                            v-if="slovakIdentifierError"
                            class="text-danger"
                        >
                            {{ slovakIdentifierError }}
                        </small>
                    </div>
                </template>

                <div
                    v-if="isNonEu"
                    class="col-span-3"
                >
                    <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
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
                        placeholder="Vyhľadajte štát"
                        fluid
                        :invalid="Boolean(errors['coverage.member_state_code'])"
                        :class="{ 'opacity-50!': disabled }"
                        @update:model-value="selectCountry"
                    />

                    <small
                        v-if="errors['coverage.member_state_code']"
                        class="text-danger"
                    >
                        {{ errors['coverage.member_state_code'] }}
                    </small>
                </div>

                <div
                    v-if="isNonEu"
                    class="col-span-3"
                >
                    <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                        Aký nárok na úhradu bol potvrdený?
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="nonEuPath"
                        :options="nonEuPathOptions"
                        option-label="label"
                        option-value="value"
                        fluid
                        :class="{ 'opacity-50!': disabled }"
                        @update:model-value="selectNonEuPath"
                    />

                    <small
                        v-if="
                            isTreatyCase
                                && modelValue.member_state_code
                                && !treatyCountryCodes.has(
                                    String(modelValue.member_state_code),
                                )
                        "
                        class="text-danger"
                    >
                        Pre vybraný štát nie je v systéme overený nárokový
                        doklad zmluvného štátu.
                    </small>
                </div>

                <div
                    v-if="isOther"
                    class="col-span-12"
                >
                    <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                        Konkrétna situácia pacienta
                    </label>

                    <Select
                        :disabled="disabled"
                        :model-value="modelValue.other_subtype"
                        :options="otherSituationOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Vyberte situáciu"
                        fluid
                        :invalid="Boolean(errors['coverage.other_subtype'])"
                        :class="{ 'opacity-50!': disabled }"
                        @update:model-value="selectOtherSituation"
                    />

                    <small
                        v-if="errors['coverage.other_subtype']"
                        class="text-danger"
                    >
                        {{ errors['coverage.other_subtype'] }}
                    </small>
                </div>

                <div
                    v-if="
                        isEu
                            || modelValue.other_subtype
                                === 'temporary_sk_card_foreign'
                    "
                    class="col-span-3"
                >
                    <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
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
                        placeholder="Vyhľadajte štát"
                        fluid
                        :invalid="Boolean(errors['coverage.member_state_code'])"
                        :class="{ 'opacity-50!': disabled }"
                        @update:model-value="selectCountry"
                    />

                    <small
                        v-if="errors['coverage.member_state_code']"
                        class="text-danger"
                    >
                        {{ errors['coverage.member_state_code'] }}
                    </small>
                </div>

                <template v-if="usesForeignIdentification">
                    <div class="col-span-3">
                        <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                            Identifikačné číslo poistenca
                        </label>

                        <InputText
                            :disabled="disabled"
                            :model-value="modelValue.foreign_insured_id"
                            maxlength="20"
                            fluid
                            :invalid="Boolean(errors['coverage.foreign_insured_id'])"
                            :class="{
                                'bg-transparent!': disabled,
                                'opacity-50!': disabled,
                            }"
                            @update:model-value="
                                updateField(
                                    'foreign_insured_id',
                                    String($event || ''),
                                )
                            "
                        />

                        <small
                            v-if="errors['coverage.foreign_insured_id']"
                            class="block text-danger"
                        >
                            {{ errors['coverage.foreign_insured_id'] }}
                        </small>
                    </div>
                </template>

                <template v-if="needsEntitlementDocument">
                    <div class="col-span-6">
                        <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                            Druh nárokového dokladu
                        </label>

                        <Select
                            :disabled="
                                disabled
                                    || (
                                        isTreatyCase
                                            && !treatyCountryCodes.has(
                                                String(
                                                    modelValue.member_state_code
                                                        || '',
                                                ),
                                            )
                                    )
                            "
                            :model-value="modelValue.entitlement_document_type"
                            :options="documentOptions"
                            option-label="label"
                            option-value="value"
                            placeholder="Vyberte doklad"
                            fluid
                            :class="{ 'opacity-50!': disabled }"
                            @update:model-value="
                                updateField(
                                    'entitlement_document_type',
                                    $event,
                                )
                            "
                        />
                    </div>

                    <div class="col-span-6">
                        <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                            Číslo nárokového dokladu
                        </label>

                        <InputText
                            :disabled="disabled"
                            :model-value="modelValue.entitlement_document_number"
                            fluid
                            :class="{
                                'bg-transparent!': disabled,
                                'opacity-50!': disabled,
                            }"
                            @update:model-value="
                                updateField(
                                    'entitlement_document_number',
                                    String($event || ''),
                                )
                            "
                        />
                    </div>
                </template>

                <template v-if="modelValue.regime !== 'unclassified'">
                    <div class="col-span-6">
                        <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                            Platnosť poistenia alebo dokladu od
                        </label>

                        <DatePicker
                            v-model="validFromDate"
                            :disabled="disabled"
                            date-format="dd.mm.yy"
                            :manual-input="false"
                            :max-date="validToDate ?? undefined"
                            class="w-full"
                            input-class="w-full!"
                            :class="{ 'opacity-50!': disabled }"
                        />
                    </div>

                    <div class="col-span-6">
                        <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                            Platnosť poistenia alebo dokladu do
                        </label>

                        <DatePicker
                            v-model="validToDate"
                            :disabled="disabled"
                            date-format="dd.mm.yy"
                            :manual-input="false"
                            :min-date="validFromDate ?? undefined"
                            class="w-full"
                            input-class="w-full!"
                            :invalid="Boolean(errors['coverage.valid_to'])"
                            :class="{ 'opacity-50!': disabled }"
                        />

                        <small
                            v-if="errors['coverage.valid_to']"
                            class="text-danger"
                        >
                            {{ errors['coverage.valid_to'] }}
                        </small>
                    </div>
                </template>

                <label
                    v-if="needsEntitlementConfirmation"
                    :class="[
                        'col-span-12 flex items-start gap-2 text-normal cursor-pointer',
                        disabled && 'opacity-50! cursor-default',
                    ]"
                >
                    <Checkbox
                        :model-value="modelValue.entitlement_confirmed"
                        :disabled="disabled"
                        binary
                        class="mt-1"
                        @update:model-value="updateEntitlementConfirmed"
                    />

                    <span>
                        Skontrolovala som nárokový doklad a potvrdzujem nárok
                        pacienta na úhradu.
                    </span>
                </label>
            </template>
        </div>
    </div>
</template>