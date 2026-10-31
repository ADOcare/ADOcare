import api from '@/services/api'
import { ref, type Ref } from 'vue'

export type ParsedAddress = {
    street: string
    city: string
    zip: string
}

export type PlaceData = {
    address: string
    street: string
    city: string
    zip: string
    latitude: number | null
    longitude: number | null
    place_id?: string
}

export type AddressAutocompleteSuggestion = {
    label: string
    place_id: string
    source?: string
    address?: string
    street?: string
    city?: string
    zip?: string
    lat?: number | null
    lng?: number | null
}

export type PlaceDetailResponse = {
    address_components: Array<{
        long_name: string
        short_name: string
        types: string[]
    }>
    geometry: {
        location: {
            lat: number
            lng: number
        }
    }
    formatted_address: string
    place_id: string
}

const CITY_COMPONENT_TYPES = [
    'locality',
    'postal_town',
    'administrative_area_level_3',
    'administrative_area_level_2',
    'sublocality',
    'sublocality_level_1',
    'neighborhood',
]

/**
 * Calculate straight-line distance in kilometres, or null for unavailable coordinates.
 */
export function addressDistanceKm(
    latitude: number | null | undefined,
    longitude: number | null | undefined,
    branchLatitude: number | null | undefined,
    branchLongitude: number | null | undefined,
): number | null {
    const validLatitude = (value: unknown): value is number =>
        typeof value === 'number' && Number.isFinite(value) && Math.abs(value) <= 90
    const validLongitude = (value: unknown): value is number =>
        typeof value === 'number' && Number.isFinite(value) && Math.abs(value) <= 180

    if (!validLatitude(latitude) || !validLongitude(longitude)
        || !validLatitude(branchLatitude) || !validLongitude(branchLongitude)) {
        return null
    }

    const radians = (degrees: number) => degrees * Math.PI / 180
    const a = Math.sin(radians(branchLatitude - latitude) / 2) ** 2
        + Math.cos(radians(latitude)) * Math.cos(radians(branchLatitude))
        * Math.sin(radians(branchLongitude - longitude) / 2) ** 2
    return 6371 * 2 * Math.asin(Math.sqrt(Math.min(1, Math.max(0, a))))
}

/**
 * Query the server for autocomplete predictions for a partial address.
 *
 * Returns an array of suggestion objects ({ label, place_id }) or an empty array
 * when the input is shorter than 3 characters.
 *
 * @param {string} text Partial address text (min length 3)
 * @returns {Promise<Array<{label: string, place_id: string}>>}
 */
export async function searchAutocomplete(text: string) {
    if (!text || text.length < 3) return []
    const res = await api.get('/v1/geocode/autocomplete', { params: { text } })
    const preds = res.data.data?.predictions ?? []
    return preds.map((p: any): AddressAutocompleteSuggestion => ({
        label: p.description,
        place_id: p.place_id,
        source: p.source,
        address: p.address,
        street: p.street,
        city: p.city,
        zip: p.zip,
        lat: typeof p.lat === 'number' ? p.lat : null,
        lng: typeof p.lng === 'number' ? p.lng : null,
    }))
}

/**
 * Fetch detailed place data for a Google Place `place_id`.
 *
 * @param {string} place_id Google Place ID
 * @returns {Promise<PlaceDetailResponse|null>} Place details or `null` when `place_id` is empty
 */
export async function fetchPlaceDetails(place_id: string): Promise<PlaceDetailResponse | null> {
    if (!place_id) return null
    const res = await api.get('/v1/geocode/details', { params: { place_id } })
    return res.data.data?.result ?? null
}

/**
 * Convert Google Place details into a normalized PlaceData shape used by the UI.
 *
 * @param {PlaceDetailResponse|null} details Raw place detail response from the API
 * @returns {PlaceData} Normalized place data with address, street, city, zip and coordinates
 */
export function parsePlaceDetailsToData(details: PlaceDetailResponse | null): PlaceData {
    if (!details) return { address: '', street: '', city: '', zip: '', latitude: null, longitude: null }
    const parsed = parseComponents(details.address_components || [])
    const geo = details.geometry?.location
    return {
        address: details.formatted_address || '',
        street: parsed.street,
        city: parsed.city,
        zip: parsed.zip,
        latitude: geo?.lat ?? null,
        longitude: geo?.lng ?? null,
    }
}

function pickComp(components: any[], type: string) {
    const c = (components ?? []).find((x: any) => (x.types ?? []).includes(type))
    return (c?.long_name ?? '').trim()
}

function pickFirstComponent(components: PlaceDetailResponse['address_components'], types: string[]): string {
    for (const type of types) {
        const value = pickComp(components, type)

        if (value) {
            return value
        }
    }

    return ''
}

/**
 * Parse Google address components returned by the API into a small object
 * containing { street, city, zip }.
 *
 * @param {PlaceDetailResponse['address_components']} components Raw address components
 * @returns {ParsedAddress}
 */
export function parseComponents(components: PlaceDetailResponse['address_components']): ParsedAddress {
    let streetNumber = pickComp(components, 'street_number')
    let route = pickComp(components, 'route')

    const city = pickFirstComponent(components, CITY_COMPONENT_TYPES).trim()

    const zip = (pickComp(components, 'postal_code') || '').replace(/\s+/g, '').trim()

    if (!streetNumber) {
        const premise = pickComp(components, 'premise')
        if (premise) {
            streetNumber = premise
        }
    }

    if (!route && streetNumber) {
        route = city
    }
    let street = [route, streetNumber].filter(Boolean).join(' ').trim()

    if (!street && city) {
        street = city
    }

    return { street, city, zip }
}

/**
 * Reverse-geocode a latitude/longitude pair via the API and return normalized PlaceData.
 *
 * @param {number} lat Latitude
 * @param {number} lon Longitude
 * @returns {Promise<PlaceData|null>} Normalized place data or null if nothing found
 */
export async function reverseGeocode(lat: number, lon: number): Promise<PlaceData | null> {
    if (lat == null || lon == null) return null
    const place = await api.get('/v1/geocode/reverse', { params: { lat, lon } })
    const placeData = place?.data.data ?? null
    if (!placeData) return null
    const { address, street, city, zip, latitude, longitude, place_id } = placeData
    return { address, street, city, zip, latitude, longitude, place_id }
}


export function useAddressForm(entity: Ref<Record<string, any> | null>) {
    const addressQuery = ref<string | null>(null)

    function computeAddressQuery(ent: Record<string, any> | null, explicitAddress?: string | null) {
        // precedence: explicitAddress -> merged parts (street, city, psc) -> raw address -> null
        if (explicitAddress && String(explicitAddress).trim() !== '') return String(explicitAddress).trim()
        const merged = mergeAddressParts(ent?.address, ent?.city, ent?.psc)
        if (merged) {
            // strip duplicate city if our street fallback inserted it twice
            const parts = merged.split(',').map(p => p.trim()).filter(Boolean)
            if (parts.length > 1 && parts[0] === parts[1]) {
                parts.splice(1, 1)
            }
            return parts.join(', ')
        }
        if (ent?.address) return ent.address.trim().replace(/\s*,\s*/g, ', ')
        return null
    }

    function init() {
        if (!entity.value) return
        addressQuery.value = computeAddressQuery(entity.value)
    }

    function onAutocompleteSelected(place: PlaceData) {
        const { address, street, city, zip, latitude, longitude } = place;
        entity.value = {
            ...entity.value,
            city: city || entity.value?.city,
            address: street || entity.value?.address,
            psc: zip || entity.value?.psc,
            latitude: latitude ?? entity.value?.latitude,
            longitude: longitude ?? entity.value?.longitude,
        }
        addressQuery.value = computeAddressQuery(entity.value, address)
    }

    async function onMapClick(geo: { lat: number | null, lon: number | null }) {
        entity.value = {
            ...entity.value,
            latitude: geo.lat,
            longitude: geo.lon,
        }

        if (geo.lat == null || geo.lon == null) return null

        const place = await reverseGeocode(geo.lat, geo.lon)
        if (!place) return null

        const { address, city, street, zip } = place
        entity.value = {
            ...entity.value,
            city: city || entity.value?.city,
            address: street || entity.value?.address,
            psc: zip || entity.value?.psc,
        }
        addressQuery.value = computeAddressQuery(entity.value, address)
        return place
    }

    async function resolveBeforeSave(): Promise<void> {
        if (!entity.value) return
        const needResolve = !!entity.value.address && (!entity.value.city || !entity.value.psc || !entity.value.latitude || !entity.value.longitude)
        if (!needResolve) return

        try {
            const preds = await searchAutocomplete(entity.value.address as string)
            if (preds && preds.length > 0) {
                const first = preds[0]
                const details = await fetchPlaceDetails(first.place_id)
                if (details) {
                    const parsed = parseComponents(details.address_components || [])
                    entity.value.address = first.label || entity.value.address
                    entity.value.city = parsed.city || entity.value.city
                    entity.value.psc = parsed.zip || entity.value.psc
                    entity.value.latitude = details.geometry?.location?.lat ?? entity.value.latitude
                    entity.value.longitude = details.geometry?.location?.lng ?? entity.value.longitude
                    addressQuery.value = computeAddressQuery(entity.value)
                }
            }
        } catch {
            // swallow; save will continue with existing data
        }
    }

    return { addressQuery, init, onAutocompleteSelected, onMapClick, resolveBeforeSave }
}

// small helper (kept here to avoid utility coupling)
function mergeAddressParts(street?: string, city?: string, psc?: string) {
    // ensure single space after commas and no accidental repetition
    return [street, city, psc]
        .filter(Boolean)
        .map(p => String(p).trim())
        .join(', ') || ''
}
