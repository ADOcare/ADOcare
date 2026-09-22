<script setup lang="ts">
import { onMounted, ref, watch, computed } from 'vue'
import AddressAutocomplete from '@/components/Address/AddressAutocomplete.vue'
import MapSelector from '@/components/Address/MapSelector.vue'
import { useAddressForm } from '@/composables/address'
import useAuthStore from '@/stores/auth'
import { useApi } from '@/composables/useApi'
import type { Branch, Doctor, InsuranceCompany, Patient, User } from '@/types/models'
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

// -------------------- Personal number --------------------
function onPersonalNumberInput(e: Event) {
    const input = e.target as HTMLInputElement
    const digitsOnly = input.value.replace(/\D+/g, '')
    localPatient.value.personal_number = digitsOnly
}

watch(
    () => localPatient.value.personal_number,
    (val) => {
        if (!val) {
            return
        }

        const clean = val.replace(/\D+/g, '')

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

const addressInvalid = computed(() => {
    return !!localAddressError.value || !!errors.value.address || !!errors.value.city
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

function composeAddressFromFields(address?: string | null, city?: string | null, zip?: string | null): string | null {
    const parts = [address, city, zip]
        .filter((part) => typeof part === 'string' && part.trim().length > 0)
        .map((part) => String(part).trim())

    return parts.length > 0 ? parts.join(', ') : null
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
    localPatient.value.zip = zip || localPatient.value.zip
    localPatient.value.latitude = latitude ?? localPatient.value.latitude
    localPatient.value.longitude = longitude ?? localPatient.value.longitude

    addressEntity.value = {
        ...(addressEntity.value ?? {}),
        address,
        city,
        psc: zip || localPatient.value.zip,
        latitude: latitude ?? localPatient.value.latitude,
        longitude: longitude ?? localPatient.value.longitude,
    }

    addressQuery.value = formatAddressLabel(place) || composeAddressFromFields(address, city, zip)
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

        addressEntity.value = { ...next, psc: next.zip }
        initAddressForm()

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
    () => [localPatient.value.address, localPatient.value.city, localPatient.value.zip],
    ([address, city, zip]) => {
        const composed = composeAddressFromFields(address as string | null, city as string | null, zip as string | null)

        if (composed) {
            addressQuery.value = composed
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
                    Rodné číslo
                </label>
                <InputText
                    :disabled="disabled"
                    v-model="localPatient.personal_number"
                    maxlength="11"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    fluid
                    :invalid="submitted && !localPatient.personal_number"
                    :class="{ 'opacity-50!': disabled }"
                    @input="onPersonalNumberInput"
                />
                <small v-if="submitted && errors.personal_number" class="text-danger">
                    {{ errors.personal_number }}
                </small>
            </div>

            <div class="col-span-2">
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

            <div class="col-span-6">
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

            <div class="col-span-6">
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
            :insurance-companies="insuranceOptions"
            :errors="errors"
            :allow-unclassified="localPatient.coverage?.regime === 'unclassified'"
            :disabled="disabled"
            @clear-error="emit('clear-error', $event)"
        />

        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12">
                <label class="block text-normal text-accent">Adresa</label>
            </div>

            <div class="col-span-12">
                <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">
                    Adresa (ulica, mesto, PSČ)
                </label>
                <AddressAutocomplete
                    v-model="addressQuery"
                    @selected="onAutocompleteAddressSelected"
                    class="w-full"
                    :disabled="disabled"
                    :invalid="addressInvalid || (submitted && !addressIsDatabaseReady)"
                    :class="{ 'opacity-50!': disabled }"
                />
                <small v-if="localAddressError" class="text-danger">
                    {{ localAddressError }}
                </small>
                <small v-else-if="submitted && !addressIsDatabaseReady" class="text-danger">
                    Vyberte adresu zo zoznamu tak, aby obsahovala ulicu/adresu a mesto.
                </small>
                <small v-else-if="submitted && errors.address" class="text-danger">
                    {{ errors.address }}
                </small>
                <small v-else-if="submitted && errors.city" class="text-danger">
                    {{ errors.city }}
                </small>
            </div>

            <div class="col-span-12">
                <MapSelector
                    :latitude="localPatient.latitude"
                    :longitude="localPatient.longitude"
                    :disabled="authStore.currentRole === 'manager'"
                    @update="onMapAddressUpdate"
                />
                <small v-if="submitted && errors.coordinates" class="text-danger">{{ errors.coordinates }}</small>
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
