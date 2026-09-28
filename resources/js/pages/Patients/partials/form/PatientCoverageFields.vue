<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
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
const domesticUsesBic = ref(/[A-Za-z]/.test(String(props.personalNumber || '')))

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
    { value: 'statutory_entitlement_9_3', label: 'Osoba s potvrdeným nárokom podľa § 9 ods. 3' },
    { value: 'temporary_sk_card_foreign', label: 'Dočasný slovenský preukaz s potvrdeným zahraničným nárokom' },
    { value: 'manager_review', label: 'Iná alebo nejasná situácia – posúdi manažér' },
]

const euDocumentOptions = [
    { value: 'EHIC', label: 'Európsky preukaz zdravotného poistenia (EPZP/EHIC)' },
    { value: 'REPLACEMENT_CERTIFICATE', label: 'Náhradný certifikát k EPZP' },
    { value: 'S1', label: 'Formulár S1' },
    { value: 'S2', label: 'Formulár S2' },
    { value: 'OTHER_ENTITLEMENT_DOCUMENT', label: 'Iný potvrdený nárokový doklad' },
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
const usesBicInput = computed(() => !isDomestic.value || domesticUsesBic.value)
const isTreatyCase = computed(() => props.modelValue.other_subtype === 'treaty_document')
const usesForeignIdentification = computed(() => props.modelValue.identification_method === 'foreign_triad')
const needsEntitlementDocument = computed(() => isEu.value || isTreatyCase.value || props.modelValue.other_subtype === 'temporary_sk_card_foreign')
const needsConfirmation = computed(() => props.modelValue.regime === 'eu' || props.modelValue.regime === 'special')

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

    return options.sort((left, right) => left.name.localeCompare(right.name, 'sk'))
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
        return [{ value: 'TEMPORARY_SK_CARD', label: 'Dočasný slovenský preukaz' }]
    }

    if (isTreatyCase.value) {
        return treatyDocuments[String(props.modelValue.member_state_code || '').toUpperCase()] ?? []
    }

    return euDocumentOptions
})

const verificationAvailable = computed(() => {
    return props.canVerifyInsurance
        && !!props.personalNumber?.trim()
        && isDomestic.value
        && !domesticUsesBic.value
})

const insuranceCompanyLabel = computed(() => {
    return isDomestic.value
        ? 'Zdravotná poisťovňa *'
        : 'Slovenská vykazujúca poisťovňa *'
})

const verificationHint = computed(() => {
    if (domesticUsesBic.value) {
        return 'Overenie cez ÚDZS nie je dostupné pre pridelený BIČ.'
    }

    if (!props.personalNumber?.trim()) {
        return 'Najprv vyplňte rodné číslo pacienta.'
    }

    if (!props.modelValue.insurance_company_id) {
        return 'Najprv vyberte poisťovňu.'
    }

    return 'ÚDZS porovná poistný vzťah s údajmi, ktoré sú práve vo formulári.'
})

const sexLabel = computed(() => {
    if (props.sex === 'M') {
        return 'Muž'
    }

    if (props.sex === 'F') {
        return 'Žena'
    }

    return 'Pohlavie ešte nie je vyplnené v osobných údajoch.'
})

const incompleteMessage = computed(() => {
    if (props.modelValue.regime !== 'unclassified') {
        return null
    }

    if (isNonEu.value) {
        return 'Bez potvrdeného nároku nie je možné pacienta zaradiť do dávky. Záznam môžete uložiť, export však zostane zablokovaný.'
    }

    return 'Túto situáciu musí pred vykázaním posúdiť manažér. Záznam môžete uložiť, export však zostane zablokovaný.'
})

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
    set: (value) => updateField('valid_from', formatApiDate(value)),
})

const validToDate = computed<Date | null>({
    get: () => parseApiDate(props.modelValue.valid_to),
    set: (value) => updateField('valid_to', formatApiDate(value)),
})

function emitCoverage(next: PatientCoverageForm, errorKeys: string[] = []) {
    emit('update:modelValue', next)
    errorKeys.forEach((key) => emit('clear-error', `coverage.${key}`))
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
    } else if (category === 'eu') {
        next.regime = 'eu'
        next.identification_method = 'foreign_triad'
    } else if (category === 'homeless') {
        next.regime = 'special'
        next.identification_method = 'slovak_identifier'
        next.special_category = 'homeless'
        next.legal_basis = '§ 9 ods. 4'
    } else if (category === 'non_eu') {
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
        next.other_subtype = 'unconfirmed'
        next.legal_basis = 'Nárok zatiaľ nie je potvrdený – export blokovaný'
    } else {
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
    }

    emitCoverage(next, ['category', 'regime', 'identification_method'])
}

function updateField<K extends keyof PatientCoverageForm>(key: K, value: PatientCoverageForm[K]) {
    const next = {
        ...props.modelValue,
        [key]: value,
    }

    if (key === 'insurance_company_id') {
        next.is_verified = false
    }

    emitCoverage(next, [String(key)])
}

function updatePersonalIdentifier(value: string | null) {
    const normalized = isDomestic.value && !domesticUsesBic.value
        ? String(value || '').replace(/\D+/g, '')
        : String(value || '').replace(/\s+/g, '').toUpperCase()

    emit('update:personalNumber', normalized)
    emit('clear-error', 'personal_number')

    if (props.modelValue.is_verified) {
        emitCoverage({
            ...props.modelValue,
            is_verified: false,
        })
    }
}

function toggleDomesticIdentifier(event: Event) {
    domesticUsesBic.value = (event.target as HTMLInputElement).checked
    emit('update:personalNumber', '')
    emitCoverage({
        ...props.modelValue,
        is_verified: false,
    })
    emit('clear-error', 'personal_number')
}

function selectCountry(code: string | null) {
    const normalizedCode = code ? String(code).toUpperCase() : null
    const next = {
        ...props.modelValue,
        member_state_code: normalizedCode,
        entitlement_document_type: null,
        entitlement_document_number: null,
        entitlement_confirmed: false,
    }

    if (isTreatyCase.value && normalizedCode && !treatyCountryCodes.has(normalizedCode)) {
        next.other_subtype = 'unconfirmed'
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
        next.legal_basis = 'Nárok zatiaľ nie je potvrdený – export blokovaný'
    }

    emitCoverage(next, ['member_state_code', 'entitlement_document_type'])
}

function selectNonEuPath(path: string) {
    const next = {
        ...props.modelValue,
        other_subtype: path,
        entitlement_confirmed: false,
        entitlement_document_type: null,
        entitlement_document_number: null,
        special_category: null,
        legal_basis: null,
    }

    if (path === 'treaty_document') {
        next.regime = 'eu'
        next.identification_method = 'foreign_triad'
        next.legal_basis = 'Medzinárodná zmluva – potvrdený nárokový doklad'
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

    emitCoverage(next, ['regime', 'identification_method', 'special_category', 'legal_basis'])
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
    } else {
        next.regime = 'unclassified'
        next.identification_method = 'incomplete'
        next.legal_basis = 'Nejasná situácia – vyžaduje posúdenie manažérom'
    }

    emitCoverage(next, ['other_subtype', 'regime', 'identification_method'])
}

function updateEntitlementConfirmed(event: Event) {
    const checked = (event.target as HTMLInputElement).checked
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

watch(
    () => props.modelValue.category,
    (category) => {
        if (category === 'domestic') {
            domesticUsesBic.value = /[A-Za-z]/.test(String(props.personalNumber || ''))
        }
    },
)

watch(
    () => props.personalNumber,
    (value) => {
        if (isDomestic.value && /[A-Za-z]/.test(String(value || ''))) {
            domesticUsesBic.value = true
        }
    },
)
</script>

<template>
    <section class="space-y-5">
        <div>
            <h3 class="font-medium text-darkgrey">Poistenie pacienta</h3>
            <p class="text-sm text-gray-500">
                Vyberte situáciu pacienta. Formulár zobrazí iba údaje, ktoré treba doplniť.
            </p>
        </div>

        <div>
            <label class="mb-1 block text-sm">Kto hradí zdravotnú starostlivosť? *</label>
            <Select
                :disabled="disabled"
                :model-value="modelValue.category"
                :options="categoryOptions"
                option-label="label"
                option-value="value"
                class="w-full"
                placeholder="Vyberte kategóriu poistenca"
                @update:model-value="selectCategory"
            />
            <small v-if="errors['coverage.category']" class="text-danger">
                {{ errors['coverage.category'] }}
            </small>
        </div>

        <div v-if="modelValue.category" class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm">{{ insuranceCompanyLabel }}</label>
                <Select
                    :disabled="disabled"
                    :model-value="modelValue.insurance_company_id"
                    :options="insuranceCompanies"
                    option-label="name"
                    option-value="id"
                    placeholder="Vyberte poisťovňu"
                    class="w-full"
                    @update:model-value="updateField('insurance_company_id', $event)"
                />
                <small v-if="errors['coverage.insurance_company_id']" class="text-danger">
                    {{ errors['coverage.insurance_company_id'] }}
                </small>
            </div>

            <div v-if="modelValue.identification_method === 'slovak_identifier'">
                <label class="mb-1 block text-sm">
                    {{ usesBicInput ? 'Pridelený BIČ *' : 'Rodné číslo *' }}
                </label>
                <InputText
                    :disabled="disabled"
                    :model-value="personalNumber"
                    :maxlength="usesBicInput ? 20 : 11"
                    :inputmode="usesBicInput ? 'text' : 'numeric'"
                    :pattern="usesBicInput ? undefined : '[0-9]*'"
                    class="w-full"
                    @update:model-value="updatePersonalIdentifier(String($event || ''))"
                />
                <small v-if="errors.personal_number" class="text-danger">
                    {{ errors.personal_number }}
                </small>
                <label v-if="isDomestic" class="mt-2 flex items-center gap-2 text-sm text-gray-600">
                    <input
                        type="checkbox"
                        :disabled="disabled"
                        :checked="domesticUsesBic"
                        @change="toggleDomesticIdentifier"
                    >
                    Pacient nemá rodné číslo a má pridelený BIČ
                </label>
            </div>

            <div v-if="isNonEu">
                <label class="mb-1 block text-sm">Štát poistenia *</label>
                <Select
                    :disabled="disabled"
                    :loading="countriesLoading"
                    :model-value="modelValue.member_state_code"
                    :options="countryOptions"
                    option-label="label"
                    option-value="code"
                    filter
                    placeholder="Vyhľadajte štát"
                    class="w-full"
                    @update:model-value="selectCountry"
                />
                <small v-if="errors['coverage.member_state_code']" class="text-danger">
                    {{ errors['coverage.member_state_code'] }}
                </small>
            </div>

            <div v-if="isNonEu" class="md:col-span-2">
                <label class="mb-1 block text-sm">Aký nárok na úhradu bol potvrdený? *</label>
                <Select
                    :disabled="disabled"
                    :model-value="nonEuPath"
                    :options="nonEuPathOptions"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                    @update:model-value="selectNonEuPath"
                />
                <small v-if="isTreatyCase && modelValue.member_state_code && !treatyCountryCodes.has(String(modelValue.member_state_code))" class="text-danger">
                    Pre vybraný štát nie je v systéme overený nárokový doklad zmluvného štátu.
                </small>
            </div>

            <div v-if="isOther" class="md:col-span-2">
                <label class="mb-1 block text-sm">Konkrétna situácia pacienta *</label>
                <Select
                    :disabled="disabled"
                    :model-value="modelValue.other_subtype"
                    :options="otherSituationOptions"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                    placeholder="Vyberte situáciu"
                    @update:model-value="selectOtherSituation"
                />
                <small v-if="errors['coverage.other_subtype']" class="text-danger">
                    {{ errors['coverage.other_subtype'] }}
                </small>
            </div>

            <div v-if="(isEu || modelValue.other_subtype === 'temporary_sk_card_foreign')">
                <label class="mb-1 block text-sm">Štát poistenia *</label>
                <Select
                    :disabled="disabled"
                    :loading="countriesLoading"
                    :model-value="modelValue.member_state_code"
                    :options="countryOptions"
                    option-label="label"
                    option-value="code"
                    filter
                    placeholder="Vyhľadajte štát"
                    class="w-full"
                    @update:model-value="selectCountry"
                />
                <small v-if="errors['coverage.member_state_code']" class="text-danger">
                    {{ errors['coverage.member_state_code'] }}
                </small>
            </div>

            <template v-if="usesForeignIdentification">
                <div>
                    <label class="mb-1 block text-sm">Identifikačné číslo poistenca *</label>
                    <InputText
                        :disabled="disabled"
                        :model-value="modelValue.foreign_insured_id"
                        maxlength="20"
                        class="w-full"
                        @update:model-value="updateField('foreign_insured_id', String($event || ''))"
                    />
                    <small class="text-gray-500">Nie je to číslo samotnej karty alebo dokladu.</small>
                    <small v-if="errors['coverage.foreign_insured_id']" class="block text-danger">
                        {{ errors['coverage.foreign_insured_id'] }}
                    </small>
                </div>

                <div>
                    <label class="mb-1 block text-sm">Pohlavie použité pri vykázaní *</label>
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                        {{ sexLabel }}
                    </div>
                    <small v-if="errors.sex" class="text-danger">{{ errors.sex }}</small>
                </div>
            </template>

            <template v-if="needsEntitlementDocument">
                <div>
                    <label class="mb-1 block text-sm">Druh nárokového dokladu *</label>
                    <Select
                        :disabled="disabled || (isTreatyCase && !treatyCountryCodes.has(String(modelValue.member_state_code || '')))"
                        :model-value="modelValue.entitlement_document_type"
                        :options="documentOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Vyberte doklad"
                        class="w-full"
                        @update:model-value="updateField('entitlement_document_type', $event)"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-sm">Číslo nárokového dokladu *</label>
                    <InputText
                        :disabled="disabled"
                        :model-value="modelValue.entitlement_document_number"
                        class="w-full"
                        @update:model-value="updateField('entitlement_document_number', String($event || ''))"
                    />
                </div>
            </template>

            <template v-if="modelValue.regime !== 'unclassified'">
                <div>
                    <label class="mb-1 block text-sm">Platnosť poistenia alebo dokladu od</label>
                    <DatePicker
                        v-model="validFromDate"
                        :disabled="disabled"
                        date-format="dd.mm.yy"
                        :manual-input="false"
                        :max-date="validToDate ?? undefined"
                        class="w-full"
                        input-class="w-full!"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-sm">Platnosť poistenia alebo dokladu do</label>
                    <DatePicker
                        v-model="validToDate"
                        :disabled="disabled"
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
            </template>

            <label v-if="needsConfirmation" class="flex items-start gap-2 md:col-span-2">
                <input
                    type="checkbox"
                    class="mt-1"
                    :disabled="disabled"
                    :checked="modelValue.entitlement_confirmed"
                    @change="updateEntitlementConfirmed"
                >
                <span>
                    Skontrolovala som nárokový doklad a potvrdzujem nárok pacienta na úhradu.
                    <small class="block text-gray-500">Bez potvrdenia sa pacient nedá zaradiť do exportu dávky.</small>
                </span>
            </label>
        </div>

        <div v-if="incompleteMessage" class="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm text-warning">
            {{ incompleteMessage }}
        </div>

        <div v-if="isDomestic" class="flex flex-col gap-2 rounded-md bg-gray-50 p-3 md:flex-row md:items-center md:justify-between">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <span v-if="modelValue.is_verified" class="inline-flex rounded-md bg-success/15 px-2 py-1 text-sm text-success">
                        Poistenie overené
                    </span>
                    <span v-else-if="verificationHasUnsavedChanges" class="inline-flex rounded-md bg-tag3 px-2 py-1 text-sm text-accent">
                        Načítané údaje čakajú na uloženie
                    </span>
                    <span v-else class="inline-flex rounded-md bg-warning/15 px-2 py-1 text-sm text-warning">
                        Poistenie nie je overené
                    </span>
                </div>
                <p class="text-sm text-gray-500">{{ verificationHint }}</p>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2">
                <Button
                    type="button"
                    label="Načítať poistný vzťah"
                    icon="bi bi-cloud-download"
                    :loading="loadingInsurance"
                    :disabled="disabled || !canLoadInsurance || loadingInsurance"
                    class="bg-accent! text-white! hover:bg-darkgrey! border-0!"
                    @click="emit('load-insurance')"
                />
                <Button
                    type="button"
                    label="Overiť poistenca"
                    icon="bi bi-shield-check"
                    :loading="verifying"
                    :disabled="disabled || !verificationAvailable || verifying"
                    class="bg-accent! text-white! hover:bg-darkgrey! border-0!"
                    @click="emit('verify')"
                />
            </div>
        </div>
    </section>
</template>
