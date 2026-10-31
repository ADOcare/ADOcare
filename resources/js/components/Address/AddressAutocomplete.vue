<script setup lang="ts">
import { ref, watch } from 'vue'
import type { PlaceData } from '@/composables/address'
import { searchAutocomplete, fetchPlaceDetails, parsePlaceDetailsToData } from '@/composables/address'
import type { AutoCompleteCompleteEvent, AutoCompleteOptionSelectEvent } from 'primevue/autocomplete'

type AutocompleteSuggestion = {
    label?: string
    place_id?: string
    source?: string
    address?: string
    street?: string
    city?: string
    zip?: string
    lat?: number | null
    lng?: number | null
}

const props = defineProps<{ modelValue: string | null }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: string | null): void; (e: 'selected', v: PlaceData): void }>();

const query = ref(props.modelValue ?? '')
const suggestions = ref<any[]>([])

watch(() => props.modelValue, (v) => {
    query.value = v ?? ''
})

async function onComplete(e: AutoCompleteCompleteEvent) {
    suggestions.value = await searchAutocomplete(e.query ?? '')
}

function normalizeSuggestionToPlaceData(suggestion: AutocompleteSuggestion): PlaceData | null {
    const address = suggestion.address ?? suggestion.label ?? ''
    const street = suggestion.street ?? ''
    const city = suggestion.city ?? ''
    const zip = suggestion.zip ?? ''
    const latitude = typeof suggestion.lat === 'number' ? suggestion.lat : null
    const longitude = typeof suggestion.lng === 'number' ? suggestion.lng : null

    if (!address && !street && !city) {
        return null
    }

    return {
        address,
        street,
        city,
        zip,
        latitude,
        longitude,
        place_id: suggestion.place_id,
    }
}

async function onSelect(e: AutoCompleteOptionSelectEvent) {
    const sel = (e?.value ?? null) as AutocompleteSuggestion | null
    if (!sel) return

    if (sel.source === 'nominatim') {
        const payload = normalizeSuggestionToPlaceData(sel)

        if (!payload) {
            return
        }

        emit('update:modelValue', sel.label || payload.address || null)
        emit('selected', payload)
        return
    }

    if (!sel?.place_id) return
    try {
        const details = await fetchPlaceDetails(sel.place_id)
        const payload = parsePlaceDetailsToData(details)

        // update model with the full suggestion label (user-visible address)
        emit('update:modelValue', sel.label || payload.address || null)
        emit('selected', payload)
    } catch (err) {
        console.error('Address select failed', err)
    }
}

function onInput(v: any) {
    // PrimeVue AutoComplete may call @input with an InputEvent rather than the current value.
    if (typeof v === 'string') {
        emit('update:modelValue', v || null)
        return
    }

    // Try to extract value from common event shapes
    const possible = v?.target?.value ?? v?.target?.textContent ?? undefined
    if (typeof possible === 'string') {
        emit('update:modelValue', possible || null)
        return
    }

    // Fallback: stringify
    try {
        emit('update:modelValue', String(v ?? '') || null)
    } catch {
        emit('update:modelValue', null)
    }
}
</script>

<template>
    <AutoComplete v-model="query" :suggestions="suggestions" optionLabel="label" @complete="onComplete"
        @option-select="onSelect" @input="onInput" class="w-full" />
</template>