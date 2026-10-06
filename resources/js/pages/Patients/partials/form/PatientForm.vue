<script setup lang="ts">
import { onMounted, ref, watch, computed } from 'vue'
import AddressAutocomplete from '@/components/Address/AddressAutocomplete.vue'
import MapSelector from '@/components/Address/MapSelector.vue'
import { useAddressForm } from '@/composables/address'
import useAuthStore from '@/stores/auth'
import {
    usePatientStore,
    type PatientInsuranceCheckResult,
} from '@/stores/patientStore'
import { useApi } from '@/composables/useApi'
import { useToast } from 'primevue/usetoast'
import type { Branch, Doctor, InsuranceCompany, User } from '@/types/models'
import { formatBranchFullName, formatUserFullName } from '@/utils/formatUtils'
import PatientCoverageFields from './PatientCoverageFields.vue'
import {
    normalizePatientCoverage,
    type PatientCoverageSource,
    type PatientWithCoverage,
} from '@/composables/patientCoverage'

const props = defineProps<{
    patient?: PatientCoverageSource
    submitted?: boolean
    errors?: { [key: string]: string } | null
    disabled?: boolean
    allowAssignmentEditing?: boolean
    companyId?: number
}>()

const emit = defineEmits<{
    (e: 'update:patient', patient: PatientWithCoverage): void
    (e: 'clear-error', key: string): void
    (e: 'address-valid-change', value: boolean): void
}>()

const submitted = computed(() => !!props.submitted)
const errors = computed(() => props.errors ?? {})

const authStore = useAuthStore()
const patientStore = usePatientStore()
const toast = useToast()
const { list, listScoped } = useApi()

const branchOptions = ref<Branch[]>([])
const nurseOptions = ref<User[]>([])
const branchesError = ref<Error | null>(null)
const nursesError = ref<Error | null>(null)

const emptyPatient: PatientWithCoverage = normalizePatientCoverage()
const localPatient = ref<PatientWithCoverage>(normalizePatientCoverage(props.patient))

const insufficientAddressMessage = 'Vyberte inú adresu, pretože táto adresa nie je dostatočná na uloženie pacienta.'
const localAddressError = ref('')
const selectedAddressIsValid = ref(false)

// -------------------- Doctors / Insurance --------------------
const sexOptions = [
    { label: 'Muž', value: 'M' },
    { label: 'Žena', value: 'F' },
]

const doctorOptions = ref<{ id: number; name: string }[]>([])
const insuranceOptions = ref<{ id: number; name: string }[]>([])
const insuranceVerificationLoading = ref(false)
const insurancePrefillLoading = ref(false)
const lastInsurancePrefillKey = ref('')
const insuranceVerificationBaseline = ref<{
    patientId: number | null
    firstName: string
    lastName: string
    personalNumber: string
    regime: string | null
    insuranceCompanyId: number | null
} | null>(null)

const insuranceVerificationHasUnsavedChanges = computed(() => {
    const baseline = insuranceVerificationBaseline.value

    const currentPatientId = Number(localPatient.value.id || 0) || null

    if (!baseline || baseline.patientId !== currentPatientId) {
        return false
    }

    return baseline.firstName !== String(localPatient.value.first_name ?? '')
        || baseline.lastName !== String(localPatient.value.last_name ?? '')
        || baseline.personalNumber !== String(localPatient.value.personal_number ?? '')
        || baseline.regime !== (localPatient.value.coverage.regime ?? null)
        || baseline.insuranceCompanyId !== (localPatient.value.coverage.insurance_company_id ?? null)
})

const canPrefillInsurance = computed(() => {
    const personalNumber = String(localPatient.value.personal_number ?? '').replace(/\D+/g, '')
    const regime = localPatient.value.coverage.regime

    return [9, 10].includes(personalNumber.length)
        && !!String(localPatient.value.first_name ?? '').trim()
        && !!String(localPatient.value.last_name ?? '').trim()
        && (regime === null || ['domestic', 'unclassified'].includes(regime))
})

const canVerifyInsurance = computed(() => {
    const personalNumber = String(localPatient.value.personal_number ?? '').replace(/\D+/g, '')

    return [9, 10].includes(personalNumber.length)
        && !!String(localPatient.value.first_name ?? '').trim()
        && !!String(localPatient.value.last_name ?? '').trim()
        && localPatient.value.coverage.regime === 'domestic'
        && !!localPatient.value.coverage.insurance_company_id
})

function verificationCompanyLabel(
    company: PatientInsuranceCheckResult['registered_insurance_company'],
) {
    if (!company) return 'nezistená poisťovňa'

    return [company.name, company.code ? `(${company.code})` : null]
        .filter(Boolean)
        .join(' ')
}

function insuranceLookupErrorDetail(result: PatientInsuranceCheckResult) {
    switch (result.reason) {
        case 'configuration_missing':
            return 'Laravel nenačítal EOVERENIE_EMAIL alebo EOVERENIE_PASSWORD. Vyčistite config cache a reštartujte PHP server.'
        case 'authentication_failed':
            return result.http_status
                ? `Prihlásenie do eOverenia zlyhalo (HTTP ${result.http_status}). Skontrolujte prihlasovacie údaje.`
                : 'Prihlásenie do eOverenia zlyhalo. Skontrolujte prihlasovacie údaje.'
        case 'authentication_not_attempted':
            return 'PHP worker nevykonal prihlásenie do eOverenia. Vyčistite Laravel config cache a reštartujte Herd.'
        case 'connection_failed':
            return 'Laravel server sa nedokázal pripojiť k eOvereniu. Skontrolujte internetové pripojenie, DNS a TLS certifikáty servera.'
        case 'unexpected_response':
            return result.http_status
                ? `ÚDZS vrátil neočakávanú odpoveď (HTTP ${result.http_status}).`
                : 'ÚDZS vrátil neočakávanú odpoveď.'
        case 'invalid_response':
            return 'ÚDZS odpovedal, ale odpoveď neobsahovala očakávané údaje.'
        default:
            return 'Služba eOverenie momentálne neposkytla použiteľnú odpoveď.'
    }
}

async function verifyInsurance() {
    if (!canVerifyInsurance.value || insuranceVerificationLoading.value) {
        return
    }

    insuranceVerificationLoading.value = true

    try {
        const result = await patientStore.checkPatientInsuranceData({
            personal_number: String(localPatient.value.personal_number ?? '').replace(/\D+/g, ''),
            first_name: String(localPatient.value.first_name ?? '').trim(),
            last_name: String(localPatient.value.last_name ?? '').trim(),
            insurance_company_id: Number(localPatient.value.coverage.insurance_company_id),
            regime: String(localPatient.value.coverage.regime),
        })
        localPatient.value.coverage.is_verified = result.is_verified

        if (result.status === 'verified') {
            insuranceVerificationBaseline.value = {
                patientId: Number(localPatient.value.id || 0) || null,
                firstName: String(localPatient.value.first_name ?? ''),
                lastName: String(localPatient.value.last_name ?? ''),
                personalNumber: String(localPatient.value.personal_number ?? ''),
                regime: localPatient.value.coverage.regime,
                insuranceCompanyId: localPatient.value.coverage.insurance_company_id,
            }

            toast.add({
                severity: 'success',
                summary: 'Poistenie bolo overené',
                detail: `Pacient je poistencom ${verificationCompanyLabel(result.registered_insurance_company)}.`,
                life: 5000,
            })
        } else if (result.status === 'mismatch') {
            const savedCompany = verificationCompanyLabel(result.saved_insurance_company)
            const registeredCompany = verificationCompanyLabel(result.registered_insurance_company)

            toast.add({
                severity: 'warn',
                summary: 'Poisťovňa sa nezhoduje',
                detail: `Vybraná poisťovňa: ${savedCompany}. ÚDZS: ${registeredCompany}.`,
                life: 8000,
            })
        } else if (result.status === 'not_insured') {
            toast.add({
                severity: 'warn',
                summary: 'Poistný vzťah nebol potvrdený',
                detail: 'ÚDZS pre pacienta nepotvrdil aktuálny poistný vzťah.',
                life: 8000,
            })
        } else if (result.status === 'duplicity') {
            toast.add({
                severity: 'warn',
                summary: 'Duplicitný poistný vzťah',
                detail: 'ÚDZS vrátil duplicitné údaje o poistení. Záznam pacienta je potrebné skontrolovať.',
                life: 8000,
            })
        } else if (result.status === 'identity_mismatch') {
            const registeredName = result.patient_name ?? 'iného pacienta'

            toast.add({
                severity: 'warn',
                summary: 'Meno pacienta sa nezhoduje',
                detail: `Pre zadané rodné číslo ÚDZS vrátil meno ${registeredName}. Skontrolujte rodné číslo a osobné údaje.`,
                life: 8000,
            })
        } else if (result.status === 'not_applicable') {
            toast.add({
                severity: 'info',
                summary: 'Overenie nie je dostupné',
                detail: 'Automatické overenie je dostupné pre tuzemského poistenca.',
                life: 5000,
            })
        } else {
            toast.add({
                severity: 'error',
                summary: 'Overenie sa nepodarilo',
                detail: insuranceLookupErrorDetail(result),
                life: 7000,
            })
        }
    } catch (error) {
        console.error('[EOVERENIE] Manual insurance check failed', error)
        toast.add({
            severity: 'error',
            summary: 'Overenie sa nepodarilo',
            detail: 'Poistný vzťah sa nepodarilo overiť. Skúste to znova.',
            life: 7000,
        })
    } finally {
        insuranceVerificationLoading.value = false
    }
}

async function prefillInsurance(force = false) {
    const personalNumber = String(localPatient.value.personal_number ?? '').replace(/\D+/g, '')
    const firstName = String(localPatient.value.first_name ?? '').trim()
    const lastName = String(localPatient.value.last_name ?? '').trim()
    const regime = localPatient.value.coverage.regime

    if (
        insurancePrefillLoading.value
        || ![9, 10].includes(personalNumber.length)
        || !firstName
        || !lastName
        || (regime !== null && !['domestic', 'unclassified'].includes(regime))
    ) {
        return
    }

    const requestKey = `${personalNumber}:${firstName}:${lastName}`.toLocaleLowerCase('sk-SK')

    if (!force && lastInsurancePrefillKey.value === requestKey) {
        return
    }

    insurancePrefillLoading.value = true
    lastInsurancePrefillKey.value = requestKey

    try {
        const result = await patientStore.prefillPatientInsurance({
            personal_number: personalNumber,
            first_name: firstName,
            last_name: lastName,
        })

        if (result.status === 'found') {
            const company = result.registered_insurance_company

            if (!result.local_insurance_company_found || !company?.id) {
                lastInsurancePrefillKey.value = ''
                toast.add({
                    severity: 'warn',
                    summary: 'Poisťovňa nie je nakonfigurovaná',
                    detail: `ÚDZS vrátil poisťovňu ${verificationCompanyLabel(company)}, ale jej kód nie je v databáze poisťovní.`,
                    life: 8000,
                })
                return
            }

            localPatient.value.coverage = {
                ...localPatient.value.coverage,
                regime: 'domestic',
                category: 'domestic',
                identification_method: 'slovak_identifier',
                insurance_company_id: company.id,
                member_state_code: null,
                foreign_insured_id: null,
                special_category: null,
                other_subtype: null,
                legal_basis: null,
                entitlement_confirmed: true,
                entitlement_document_type: null,
                entitlement_document_number: null,
                valid_from: result.valid_from ?? null,
                valid_to: result.valid_to ?? null,
                is_verified: false,
            }

            toast.add({
                severity: 'success',
                summary: 'Poistenie bolo doplnené',
                detail: `Poisťovňa ${verificationCompanyLabel(company)} bola načítaná z ÚDZS.`,
                life: 5000,
            })
        } else if (result.status === 'identity_mismatch') {
            toast.add({
                severity: 'warn',
                summary: 'Meno pacienta sa nezhoduje',
                detail: `Pre zadané rodné číslo ÚDZS vrátil meno ${result.patient_name ?? 'iného pacienta'}. Poistenie nebolo doplnené.`,
                life: 8000,
            })
        } else if (result.status === 'duplicity') {
            toast.add({
                severity: 'warn',
                summary: 'Duplicitný poistný vzťah',
                detail: 'ÚDZS vrátil duplicitné údaje. Poistenie nebolo automaticky doplnené.',
                life: 8000,
            })
        } else if (result.status === 'not_insured') {
            toast.add({
                severity: 'warn',
                summary: 'Poistný vzťah nebol potvrdený',
                detail: 'Pre zadané rodné číslo nebol potvrdený aktuálny poistný vzťah.',
                life: 8000,
            })
        } else {
            lastInsurancePrefillKey.value = ''
            toast.add({
                severity: 'error',
                summary: 'Poistenie sa nepodarilo načítať',
                detail: insuranceLookupErrorDetail(result),
                life: 7000,
            })
        }
    } catch (error) {
        lastInsurancePrefillKey.value = ''
        console.error('[EOVERENIE] Automatic insurance prefill failed', error)
        toast.add({
            severity: 'error',
            summary: 'Poistenie sa nepodarilo načítať',
            detail: 'Skúste údaje načítať znova.',
            life: 7000,
        })
    } finally {
        insurancePrefillLoading.value = false
    }
}

function doctorOptionLabel(doc: Partial<Doctor>) {
    return `${doc.title ?? ''} ${doc.first_name ?? ''} ${doc.last_name ?? ''}`.replace(/\s+/g, ' ').trim()
}

async function ensureSelectedDoctorOption(selectedId: number | null | undefined) {
    if (!selectedId) {
        return
    }

    if (doctorOptions.value.some((o) => o.id === selectedId)) {
        return
    }

    const { data, error } = await list<Doctor>(`/doctors/${selectedId}`)

    if (error || !data) {
        return
    }

    const doctor = data as unknown as Doctor

    doctorOptions.value = [
        ...doctorOptions.value,
        {
            id: doctor.id,
            name: doctorOptionLabel(doctor),
        },
    ]
}

const canEditAssignments = computed(
    () => (props.allowAssignmentEditing ?? (authStore.isManager || authStore.isSuperadmin)),
)

async function loadFavouriteDoctors() {
    const branchId = (localPatient.value.branch_id as unknown as number | null | undefined) ?? authStore.currentBranch?.id
    const selectedId = localPatient.value.doctor_id as unknown as number | null | undefined

    if (!branchId) {
        await ensureSelectedDoctorOption(selectedId)
        return { data: [] as Doctor[], error: null }
    }

    const { data, error } = await listScoped<Doctor>(`favourite-doctors`, { branchId })

    doctorOptions.value = (data ?? []).map((doc) => ({
        id: doc.id,
        name: doctorOptionLabel(doc),
    }))

    await ensureSelectedDoctorOption(selectedId)

    return { data, error }
}

async function loadInsuranceCompanies() {
    const { data, error } = await list<InsuranceCompany>('/insurance-companies', { all: true })

    insuranceOptions.value = (data ?? []).map((ic) => ({
        id: ic.id,
        name: ic.name ?? '<Neznáma poisťovňa>',
    }))

    return { data, error }
}

async function loadBranches(companyId?: number) {
    branchesError.value = null

    try {
        const { data, error } = await listScoped<Branch>('/branches', companyId, { all: true })

        if (error) {
            throw error
        }

        branchOptions.value = data ?? []
        return { data: branchOptions.value, error: null }
    } catch (error) {
        branchesError.value = error as Error
        branchOptions.value = []
        return { data: branchOptions.value, error: branchesError.value }
    }
}

async function loadNursesForBranch(branchId: number | null | undefined, companyId?: number) {
    nurseOptions.value = []
    nursesError.value = null

    if (!branchId) {
        return { data: nurseOptions.value, error: null }
    }

    try {
        const { data, error } = await listScoped<User>(`/nurses`, { branchId, companyId })

        if (error) {
            throw error
        }

        nurseOptions.value = data
        return { data: nurseOptions.value, error: null }
    } catch (error) {
        nursesError.value = error as Error
        nurseOptions.value = []
        return { data: nurseOptions.value, error: nursesError.value }
    }
}

watch(
    () => authStore.currentBranch?.id,
    async () => {
        await loadFavouriteDoctors()
    },
    { immediate: true },
)

watch(
    canEditAssignments,
    async (enabled) => {
        if (!enabled) {
            return
        }

        const { error: branchesError } = await loadBranches(props.companyId)

        if (branchesError) {
            console.error('Failed to load branches', branchesError)
        }

        const { error: nursesError } = await loadNursesForBranch(localPatient.value.branch_id)

        if (nursesError) {
            console.error('Failed to load nurses', nursesError)
        }
    },
)

onMounted(async () => {
    await loadFavouriteDoctors()
    await loadInsuranceCompanies()

    if (canEditAssignments.value) {
        const { error: branchesError } = await loadBranches(props.companyId)

        if (branchesError) {
            console.error('Failed to load branches', branchesError)
        }

        const { error: nursesError } = await loadNursesForBranch(localPatient.value.branch_id)

        if (nursesError) {
            console.error('Failed to load nurses', nursesError)
        }
    }
})

watch(
    () => localPatient.value.branch_id,
    (branchId) => {
        void loadFavouriteDoctors().then(({ error }) => {
            if (error) {
                console.error('Failed to load favourite doctors', error)
            }
        })

        if (canEditAssignments.value) {
            void loadNursesForBranch(branchId).then(({ error }) => {
                if (error) {
                    console.error('Failed to load nurses', error)
                }
            })
        }
    },
)

watch(
    () => localPatient.value.personal_number,
    (val) => {
        if (!val) {
            return
        }

        if (localPatient.value.coverage?.category !== 'domestic') {
            return
        }

        const clean = /[A-Za-z]/.test(val)
            ? val.replace(/\s+/g, '').toUpperCase()
            : val.replace(/\D+/g, '')

        if (val !== clean) {
            localPatient.value.personal_number = clean
        }
    },
)

// -------------------- Address --------------------
type AddressPlace = {
    address?: string | null
    street?: string | null
    city?: string | null
    zip?: string | null
    psc?: string | null
    latitude?: number | null
    longitude?: number | null
    lat?: number | null
    lon?: number | null
}

const addressEntity = ref<Record<string, any> | null>(null)
const { addressQuery, init: initAddressForm, onMapClick: addressOnMapClick } = useAddressForm(addressEntity)

const addressIsDatabaseReady = computed(() => {
    return hasRequiredAddressParts(localPatient.value)
})


function normalizeText(value: unknown): string {
    return String(value ?? '').trim()
}

function hasRequiredAddressParts(patient: Partial<PatientWithCoverage>): boolean {
    return normalizeText(patient.address).length > 0 && normalizeText(patient.city).length > 0
}

function getPlaceAddress(place: AddressPlace | null | undefined): string {
    if (!place) {
        return ''
    }

    const street = normalizeText(place.street)
    const address = normalizeText(place.address)

    return street || address
}

function getPlaceCity(place: AddressPlace | null | undefined): string {
    return normalizeText(place?.city)
}

function getPlaceZip(place: AddressPlace | null | undefined): string {
    return normalizeText(place?.zip ?? place?.psc)
}

function getPlaceLatitude(place: AddressPlace | null | undefined): number | null {
    const value = place?.latitude ?? place?.lat

    return typeof value === 'number' ? value : null
}

function getPlaceLongitude(place: AddressPlace | null | undefined): number | null {
    const value = place?.longitude ?? place?.lon

    return typeof value === 'number' ? value : null
}

function isSufficientAddressPlace(place: AddressPlace | null | undefined): boolean {
    return getPlaceAddress(place).length > 0 && getPlaceCity(place).length > 0
}

function formatAddressLabel(place: AddressPlace) {
    const address = getPlaceAddress(place)
    const city = getPlaceCity(place)
    const zip = getPlaceZip(place)

    return [address, city, zip]
        .filter((part) => part.length > 0)
        .join(', ')
}

function setAddressValid(value: boolean) {
    selectedAddressIsValid.value = value
    emit('address-valid-change', value)
}

function setInsufficientAddressError() {
    localAddressError.value = insufficientAddressMessage
    setAddressValid(false)
}

function clearAddressValidationError() {
    localAddressError.value = ''
    emit('clear-error', 'address')
    emit('clear-error', 'city')
    emit('clear-error', 'zip')
    emit('clear-error', 'coordinates')
}

function clearStoredAddressFields() {
    localPatient.value.address = ''
    localPatient.value.city = ''
    localPatient.value.zip = ''
    localPatient.value.latitude = null
    localPatient.value.longitude = null

    addressEntity.value = {
        ...(addressEntity.value ?? {}),
        address: '',
        city: '',
        psc: '',
        latitude: null,
        longitude: null,
    }
}

function applyValidAddressPlace(place: AddressPlace) {
    const address = getPlaceAddress(place)
    const city = getPlaceCity(place)
    const zip = getPlaceZip(place)
    const latitude = getPlaceLatitude(place)
    const longitude = getPlaceLongitude(place)

    localPatient.value.address = address
    localPatient.value.city = city
    localPatient.value.zip = zip

    if (latitude !== null) {
        localPatient.value.latitude = latitude
    }

    if (longitude !== null) {
        localPatient.value.longitude = longitude
    }

    addressEntity.value = {
        ...(addressEntity.value ?? {}),
        address,
        city,
        psc: zip,
        latitude: localPatient.value.latitude,
        longitude: localPatient.value.longitude,
    }

    // Only the street/address is displayed in the autocomplete input.
    addressQuery.value = address

    clearAddressValidationError()
    setAddressValid(true)
}

function onAutocompleteAddressSelected(place: AddressPlace) {
    if (!isSufficientAddressPlace(place)) {
        clearStoredAddressFields()
        addressQuery.value = formatAddressLabel(place)
        setInsufficientAddressError()
        return
    }

    applyValidAddressPlace(place)
}

async function onMapAddressUpdate(geo: { lat: number | null; lon: number | null }) {
    try {
        const place = await addressOnMapClick(geo) as AddressPlace | null

        if (!place || !isSufficientAddressPlace(place)) {
            clearStoredAddressFields()
            localPatient.value.latitude = geo.lat
            localPatient.value.longitude = geo.lon
            addressQuery.value = place ? formatAddressLabel(place) : ''
            setInsufficientAddressError()
            return
        }

        applyValidAddressPlace({
            ...place,
            latitude: place.latitude ?? geo.lat,
            longitude: place.longitude ?? geo.lon,
        })
    } catch (err) {
        console.error('Map address update failed', err)

        clearStoredAddressFields()
        localPatient.value.latitude = geo.lat
        localPatient.value.longitude = geo.lon
        setInsufficientAddressError()
    }
}

const doctorSelectRef = ref<any>(null)

const onDoctorSelectShow = async () => {
    await loadFavouriteDoctors()
}

const openDoctorsSettingsFromFooter = async () => {
    doctorSelectRef.value?.hide?.()
    window.open('/settings/doctors', '_blank', 'noopener,noreferrer')
}

// -------------------- Watch --------------------
watch(
    () => props.patient,
    (p) => {
        const next = normalizePatientCoverage(p)
        localPatient.value = next

        if (insuranceVerificationBaseline.value?.patientId !== Number(next.id || 0)) {
            insuranceVerificationBaseline.value = {
                patientId: Number(next.id || 0) || null,
                firstName: String(next.first_name ?? ''),
                lastName: String(next.last_name ?? ''),
                personalNumber: String(next.personal_number ?? ''),
                regime: next.coverage.regime,
                insuranceCompanyId: next.coverage.insurance_company_id,
            }
        }

        addressEntity.value = { ...next, psc: next.zip }
        initAddressForm()
        addressQuery.value = normalizeText(next.address)

        if (hasRequiredAddressParts(next)) {
            setAddressValid(true)
            localAddressError.value = ''
        } else {
            setAddressValid(false)
        }
    },
    { immediate: true },
)

watch(
    () => [
        localPatient.value.first_name,
        localPatient.value.last_name,
        localPatient.value.personal_number,
        localPatient.value.coverage.regime,
        localPatient.value.coverage.insurance_company_id,
    ],
    () => {
        if (insuranceVerificationHasUnsavedChanges.value) {
            localPatient.value.coverage.is_verified = false
        }
    },
)

watch(
    localPatient,
    (val) => {
        try {
            const parentVal = props.patient ?? emptyPatient

            if (JSON.stringify(val) !== JSON.stringify(parentVal)) {
                emit('update:patient', { ...val })
            }
        } catch {
            emit('update:patient', { ...val })
        }
    },
    { deep: true },
)

watch(
    addressEntity,
    (val) => {
        if (!val) {
            return
        }

        localPatient.value.address = val.address ?? localPatient.value.address
        localPatient.value.city = val.city ?? localPatient.value.city
        localPatient.value.zip = val.psc ?? localPatient.value.zip
        localPatient.value.latitude = val.latitude ?? localPatient.value.latitude
        localPatient.value.longitude = val.longitude ?? localPatient.value.longitude

        if (hasRequiredAddressParts(localPatient.value)) {
            localAddressError.value = ''
            setAddressValid(true)
        }
    },
    { deep: true },
)

watch(
    () => [
        localPatient.value.address,
        localPatient.value.city,
        localPatient.value.zip,
    ],
    ([address]) => {
        if (
            address
            && addressQuery.value !== String(address)
        ) {
            addressQuery.value = String(address)
        }

        if (!hasRequiredAddressParts(localPatient.value)) {
            setAddressValid(false)
        }
    },
)

defineExpose({
    addressIsDatabaseReady,
    selectedAddressIsValid,
})
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12">
                <label class="block text-normal text-accent">Osobné údaje</label>
            </div>

            <div class="col-span-4">
                <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                    Meno
                </label>
                <InputText
                    :disabled="disabled"
                    v-model.trim="localPatient.first_name"
                    fluid
                    :invalid="submitted && !localPatient.first_name"
                    :class="{ 'bg-transparent!': disabled, 'opacity-50!': disabled }"
                    @blur="prefillInsurance()"
                />
                <small v-if="submitted && errors.first_name" class="text-danger">{{ errors.first_name }}</small>
            </div>

            <div class="col-span-4">
                <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                    Priezvisko
                </label>
                <InputText
                    :disabled="disabled"
                    v-model.trim="localPatient.last_name"
                    fluid
                    :invalid="submitted && !localPatient.last_name"
                    :class="{ 'bg-transparent!': disabled, 'opacity-50!': disabled }"
                    @blur="prefillInsurance()"
                />
                <small v-if="submitted && errors.last_name" class="text-danger">{{ errors.last_name }}</small>
            </div>

            <div class="col-span-4">
                <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                    Titul
                </label>
                <InputText
                    :disabled="disabled"
                    v-model.trim="localPatient.title"
                    fluid
                    :class="{ 'opacity-50!': disabled }"
                />
            </div>

            <div class="col-span-4">
                <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                    Pohlavie
                </label>
                <Select
                    :disabled="disabled"
                    v-model="localPatient.sex"
                    :options="sexOptions"
                    optionLabel="label"
                    optionValue="value"
                    fluid
                    :invalid="submitted && !localPatient.sex"
                    :class="{ 'opacity-50!': disabled }"
                />
                <small v-if="submitted && errors.sex" class="text-danger">{{ errors.sex }}</small>
            </div>

            <div class="col-span-8">
                <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                    Kontakt
                </label>
                <InputText
                    :disabled="disabled"
                    v-model.trim="localPatient.contact"
                    fluid
                    :class="{ 'opacity-50!': disabled }"
                />
                <small v-if="submitted && errors.contact" class="text-danger">{{ errors.contact }}</small>
            </div>
        </div>

        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12">
                <label class="block text-normal text-accent">Zdravotné detaily</label>
            </div>

            <div class="col-span-12">
                <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                    Lekár
                </label>

                <Select
                    ref="doctorSelectRef"
                    :disabled="disabled"
                    v-model="localPatient.doctor_id"
                    :options="doctorOptions"
                    optionLabel="name"
                    optionValue="id"
                    fluid
                    filter
                    :invalid="submitted && !localPatient.doctor_id"
                    :class="{ 'opacity-50!': disabled }"
                    @show="onDoctorSelectShow"
                >
                    <template #footer>
                        <div class="p-2">
                            <Button
                                label="Pridať nového lekára"
                                class="w-full! bg-accent! text-white! text-normal! rounded-md hover:bg-darkgrey! border-0!"
                                icon="bi bi-plus"
                                type="button"
                                @click.prevent.stop="openDoctorsSettingsFromFooter"
                            />
                        </div>
                    </template>
                </Select>

                <small v-if="submitted && errors.doctor_id" class="text-danger">
                    {{ errors.doctor_id }}
                </small>
            </div>

        </div>

        <PatientCoverageFields
            v-model="localPatient.coverage"
            v-model:personal-number="localPatient.personal_number"
            :insurance-companies="insuranceOptions"
            :errors="errors"
            :disabled="disabled"
            :sex="localPatient.sex"
            :verifying="insuranceVerificationLoading"
            :loading-insurance="insurancePrefillLoading"
            :can-load-insurance="canPrefillInsurance"
            :can-verify-insurance="canVerifyInsurance"
            :verification-has-unsaved-changes="insuranceVerificationHasUnsavedChanges"
            @clear-error="emit('clear-error', $event)"
            @verify="verifyInsurance"
            @load-insurance="prefillInsurance(true)"
        />

        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12">
                <label class="block text-normal text-accent">Adresa</label>
            </div>

            <!-- Address / Google autocomplete -->
            <div class="col-span-6">
                <label
                    :class="[
                        'block text-normal mb-1',
                        disabled && 'opacity-50!',
                    ]"
                >
                    Adresa
                </label>

                <AddressAutocomplete
                    v-model="addressQuery"
                    class="w-full"
                    :disabled="disabled"
                    :invalid="
                        Boolean(localAddressError)
                            || Boolean(errors.address)
                            || (submitted && !localPatient.address)
                    "
                    :class="{ 'opacity-50!': disabled }"
                    @selected="onAutocompleteAddressSelected"
                />

                <small
                    v-if="localAddressError"
                    class="text-danger"
                >
                    {{ localAddressError }}
                </small>

                <small
                    v-else-if="submitted && errors.address"
                    class="text-danger"
                >
                    {{ errors.address }}
                </small>

                <small
                    v-else-if="submitted && !localPatient.address"
                    class="text-danger"
                >
                    Vyberte adresu zo zoznamu.
                </small>
            </div>

            <!-- City -->
            <div class="col-span-4">
                <label
                    :class="[
                        'block text-normal mb-1',
                        disabled && 'opacity-50!',
                    ]"
                >
                    Mesto
                </label>

                <InputText
                    v-model.trim="localPatient.city"
                    :disabled="disabled"
                    fluid
                    :invalid="
                        Boolean(errors.city)
                            || (submitted && !localPatient.city)
                    "
                    :class="{
                        'bg-transparent!': disabled,
                        'opacity-50!': disabled,
                    }"
                    @input="emit('clear-error', 'city')"
                />

                <small
                    v-if="submitted && errors.city"
                    class="text-danger"
                >
                    {{ errors.city }}
                </small>

                <small
                    v-else-if="submitted && !localPatient.city"
                    class="text-danger"
                >
                    Zadajte mesto.
                </small>
            </div>

            <!-- ZIP -->
            <div class="col-span-2">
                <label
                    :class="[
                        'block text-normal mb-1',
                        disabled && 'opacity-50!',
                    ]"
                >
                    PSČ
                </label>

                <InputText
                    v-model.trim="localPatient.zip"
                    :disabled="disabled"
                    fluid
                    :invalid="Boolean(errors.zip)"
                    :class="{
                        'bg-transparent!': disabled,
                        'opacity-50!': disabled,
                    }"
                    @input="emit('clear-error', 'zip')"
                />

                <small
                    v-if="submitted && errors.zip"
                    class="text-danger"
                >
                    {{ errors.zip }}
                </small>
            </div>

            <!-- Map -->
            <div class="col-span-12">
                <MapSelector
                    :latitude="localPatient.latitude"
                    :longitude="localPatient.longitude"
                    :disabled="authStore.currentRole === 'manager'"
                    @update="onMapAddressUpdate"
                />

                <small
                    v-if="submitted && errors.coordinates"
                    class="text-danger"
                >
                    {{ errors.coordinates }}
                </small>
            </div>
        </div>

        <div v-if="canEditAssignments" class="grid grid-cols-12 gap-4">
            <div class="col-span-6">
                <label class="block text-normal mb-1">Prevádzka</label>
                <Select
                    v-model="localPatient.branch_id"
                    :options="branchOptions"
                    optionLabel="address"
                    optionValue="id"
                    fluid
                    :invalid="submitted && !localPatient.branch_id"
                >
                    <template #value="slotProps">
                        <span v-if="slotProps.value">
                            {{ formatBranchFullName(branchOptions.find(b => b.id === slotProps.value) as Branch) }}
                        </span>
                        <span v-else>Vybrať prevádzku</span>
                    </template>
                    <template #option="slotProps">
                        <span v-if="slotProps.option">
                            {{ formatBranchFullName(slotProps.option) }}
                        </span>
                    </template>
                </Select>
                <small v-if="submitted && errors.branch_id" class="text-danger">{{ errors.branch_id }}</small>
            </div>

            <div class="col-span-6">
                <label class="block text-normal mb-1">Zdravotná Sestra</label>
                <Select
                    v-model="localPatient.nurse_id"
                    :options="nurseOptions"
                    optionLabel="first_name"
                    optionValue="id"
                    fluid
                    :invalid="submitted && !localPatient.nurse_id"
                >
                    <template #value="slotProps">
                        <span v-if="slotProps.value">
                            {{ formatUserFullName(nurseOptions.find(n => n.id === slotProps.value) as User) }}
                        </span>
                        <span v-else>Vybrať sestru</span>
                    </template>
                    <template #option="slotProps">
                        <span v-if="slotProps.option">
                            {{ formatUserFullName(slotProps.option) }}
                        </span>
                    </template>
                </Select>
                <small v-if="submitted && errors.nurse_id" class="text-danger">{{ errors.nurse_id }}</small>
            </div>
        </div>
    </div>
</template>
