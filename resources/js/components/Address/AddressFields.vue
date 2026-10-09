<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import InputText from 'primevue/inputtext'
import AddressAutocomplete from './AddressAutocomplete.vue'
import MapSelector from './MapSelector.vue'
import { useAddressForm } from '@/composables/address'

const INSUFFICIENT_ADDRESS_MESSAGE = 'Vyberte inú adresu, pretože táto adresa nie je dostatočná na uloženie.'

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

const props = withDefaults(defineProps<{
    address?: string | null
    city?: string | null
    zip?: string | null
    latitude?: number | null
    longitude?: number | null
    errors?: Record<string, string>
    submitted?: boolean
    disabled?: boolean
    mapDisabled?: boolean
}>(), {
    address: null,
    city: null,
    zip: null,
    latitude: null,
    longitude: null,
    errors: () => ({}),
    submitted: false,
    disabled: false,
    mapDisabled: false,
})

const emit = defineEmits<{
    'update:address': [value: string]
    'update:city': [value: string]
    'update:zip': [value: string]
    'update:latitude': [value: number | null]
    'update:longitude': [value: number | null]
    'clear-error': [key: string]
    'valid-change': [value: boolean]
}>()

const localAddressError = ref('')
const addressEntity = ref<Record<string, unknown> | null>(null)
const { addressQuery, init: initAddressForm, onMapClick: addressOnMapClick } = useAddressForm(addressEntity)

const cityModel = computed({
    get: () => props.city ?? '',
    set: (value: string) => {
        emit('update:city', value)
        emit('clear-error', 'city')
    },
})

const zipModel = computed({
    get: () => props.zip ?? '',
    set: (value: string) => {
        emit('update:zip', value)
        emit('clear-error', 'zip')
    },
})

const isValid = computed(() => (
    normalizeText(props.address).length > 0
    && normalizeText(props.city).length > 0
    && props.latitude !== null
    && props.longitude !== null
))

watch(
    () => [props.address, props.city, props.zip, props.latitude, props.longitude],
    () => {
        addressEntity.value = {
            address: props.address ?? '',
            city: props.city ?? '',
            psc: props.zip ?? '',
            latitude: props.latitude,
            longitude: props.longitude,
        }

        if (addressQuery.value !== (props.address ?? '')) {
            initAddressForm()
            addressQuery.value = props.address ?? ''
        }
    },
    { immediate: true },
)

watch(isValid, (value) => emit('valid-change', value), { immediate: true })

function normalizeText(value: unknown): string {
    return String(value ?? '').trim()
}

function getPlaceAddress(place: AddressPlace | null | undefined): string {
    return normalizeText(place?.street) || normalizeText(place?.address)
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

function formatAddressLabel(place: AddressPlace): string {
    return [getPlaceAddress(place), getPlaceCity(place), getPlaceZip(place)]
        .filter(Boolean)
        .join(', ')
}

function emitAddressValues(place: AddressPlace) {
    emit('update:address', getPlaceAddress(place))
    emit('update:city', getPlaceCity(place))
    emit('update:zip', getPlaceZip(place))
    emit('update:latitude', getPlaceLatitude(place))
    emit('update:longitude', getPlaceLongitude(place))
}

function clearStoredAddressFields() {
    emitAddressValues({
        address: '',
        city: '',
        zip: '',
        latitude: null,
        longitude: null,
    })
}

function clearValidationErrors() {
    localAddressError.value = ''
    for (const key of ['address', 'city', 'zip', 'coordinates']) {
        emit('clear-error', key)
    }
}

function applyValidAddressPlace(place: AddressPlace) {
    emitAddressValues(place)
    addressEntity.value = {
        address: getPlaceAddress(place),
        city: getPlaceCity(place),
        psc: getPlaceZip(place),
        latitude: getPlaceLatitude(place),
        longitude: getPlaceLongitude(place),
    }
    addressQuery.value = getPlaceAddress(place)
    clearValidationErrors()
}

function onAutocompleteAddressSelected(place: AddressPlace) {
    if (!isSufficientAddressPlace(place)) {
        clearStoredAddressFields()
        addressQuery.value = formatAddressLabel(place)
        localAddressError.value = INSUFFICIENT_ADDRESS_MESSAGE
        return
    }

    applyValidAddressPlace(place)
}

async function onMapAddressUpdate(geo: { lat: number | null; lon: number | null }) {
    try {
        const place = await addressOnMapClick(geo) as AddressPlace | null

        if (!place || !isSufficientAddressPlace(place)) {
            clearStoredAddressFields()
            emit('update:latitude', geo.lat)
            emit('update:longitude', geo.lon)
            addressQuery.value = place ? formatAddressLabel(place) : ''
            localAddressError.value = INSUFFICIENT_ADDRESS_MESSAGE
            return
        }

        applyValidAddressPlace({
            ...place,
            latitude: place.latitude ?? geo.lat,
            longitude: place.longitude ?? geo.lon,
        })
    } catch (error) {
        console.error('Map address update failed', error)
        clearStoredAddressFields()
        emit('update:latitude', geo.lat)
        emit('update:longitude', geo.lon)
        localAddressError.value = INSUFFICIENT_ADDRESS_MESSAGE
    }
}
</script>

<template>
    <div class="grid grid-cols-12 gap-4">
        <div class="col-span-12">
            <label class="block text-normal text-accent">Adresa</label>
        </div>

        <div class="col-span-6">
            <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">Adresa</label>
            <AddressAutocomplete
                v-model="addressQuery"
                class="w-full"
                :disabled="disabled"
                :invalid="Boolean(localAddressError) || Boolean(errors.address) || (submitted && !address)"
                :class="{ 'opacity-50!': disabled }"
                @selected="onAutocompleteAddressSelected"
            />
            <small v-if="localAddressError" class="text-danger">{{ localAddressError }}</small>
            <small v-else-if="submitted && errors.address" class="text-danger">{{ errors.address }}</small>
            <small v-else-if="submitted && !address" class="text-danger">Vyberte adresu zo zoznamu.</small>
        </div>

        <div class="col-span-4">
            <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">Mesto</label>
            <InputText
                v-model.trim="cityModel"
                :disabled="disabled"
                fluid
                :invalid="Boolean(errors.city) || (submitted && !city)"
                :class="{ 'bg-transparent!': disabled, 'opacity-50!': disabled }"
            />
            <small v-if="submitted && errors.city" class="text-danger">{{ errors.city }}</small>
            <small v-else-if="submitted && !city" class="text-danger">Zadajte mesto.</small>
        </div>

        <div class="col-span-2">
            <label :class="['block text-normal mb-1', disabled && 'opacity-50!']">PSČ</label>
            <InputText
                v-model.trim="zipModel"
                :disabled="disabled"
                fluid
                :invalid="Boolean(errors.zip)"
                :class="{ 'bg-transparent!': disabled, 'opacity-50!': disabled }"
            />
            <small v-if="submitted && errors.zip" class="text-danger">{{ errors.zip }}</small>
        </div>

        <div class="col-span-12">
            <MapSelector
                :latitude="latitude"
                :longitude="longitude"
                :disabled="disabled || mapDisabled"
                @update="onMapAddressUpdate"
            />
            <small v-if="submitted && errors.coordinates" class="text-danger">
                {{ errors.coordinates }}
            </small>
        </div>
    </div>
</template>
