<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { PropType } from 'vue'
import { LMap, LMarker, LTileLayer } from '@vue-leaflet/vue-leaflet'
import { divIcon } from 'leaflet'

const DEFAULT_LAT = 48.1486
const DEFAULT_LON = 17.1077

const props = defineProps({
    latitude: { type: Number as PropType<number | null>, default: null },
    longitude: { type: Number as PropType<number | null>, default: null },
    disabled: { type: Boolean, default: false }
});

const center = ref<[number, number]>([props.latitude ?? DEFAULT_LAT, props.longitude ?? DEFAULT_LON])

const zoom = ref(13)
const mapRef = ref<any | null>(null)
const containerRef = ref<HTMLElement | null>(null)
const interactionActive = ref(false)
const mapOptions = {
    zoomControl: false,
}
const markerIcon = divIcon({
    className: 'patient-location-marker',
    html: '<i class="bi bi-geo-alt-fill" aria-hidden="true"></i>',
    iconAnchor: [16, 32],
    iconSize: [32, 32],
})
let resizeObserver: ResizeObserver | null = null

const hasCoordinates = computed(() => (
    Number.isFinite(props.latitude) && Number.isFinite(props.longitude)
))

const emit = defineEmits<{
    (e: 'update', payload: { lat: number | null; lon: number | null }): void
}>();

watch(() => [props.latitude, props.longitude], ([lat, lon]) => {
    if (Number.isFinite(lat) && Number.isFinite(lon)) {
        zoom.value = 15
        center.value = [lat as number, lon as number]
    }

    invalidateMapSizeSoon()
})

function getLeafletMap(): any | null {
    return mapRef.value?.leafletObject ?? null
}

function invalidateMapSizeSoon() {
    nextTick(() => {
        requestAnimationFrame(() => {
            const map = getLeafletMap()
            if (map) {
                map.invalidateSize()
            }
        })
    })
}

function onMapReady() {
    getLeafletMap()?.zoomControl?.remove()
    syncMapInteraction()
    invalidateMapSizeSoon()
}

function onMapClick(e: any) {
    const originalEvent = e.originalEvent as MouseEvent | undefined
    if (props.disabled || (!originalEvent?.ctrlKey && !originalEvent?.metaKey)) return

    const lat = e.latlng?.lat ?? null
    const lon = e.latlng?.lng ?? null
    emit('update', { lat, lon })
}

function syncMapInteraction() {
    const map = getLeafletMap()
    if (!map) return

    const enabled = interactionActive.value && !props.disabled
    const handlers = [
        map.dragging,
        map.scrollWheelZoom,
        map.doubleClickZoom,
        map.boxZoom,
        map.keyboard,
        map.touchZoom,
    ]

    handlers.forEach((handler) => {
        if (!handler) return
        if (enabled) {
            handler.enable()
        } else {
            handler.disable()
        }
    })
}

function onModifierKeyChange(event: KeyboardEvent) {
    const active = event.ctrlKey || event.metaKey
    if (interactionActive.value === active) return

    interactionActive.value = active
    syncMapInteraction()
}

function deactivateInteraction() {
    if (!interactionActive.value) return

    interactionActive.value = false
    syncMapInteraction()
}

watch(() => props.disabled, () => {
    syncMapInteraction()
})

onMounted(() => {
    window.addEventListener('keydown', onModifierKeyChange)
    window.addEventListener('keyup', onModifierKeyChange)
    window.addEventListener('blur', deactivateInteraction)

    if (containerRef.value && typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
            invalidateMapSizeSoon()
        })
        resizeObserver.observe(containerRef.value)
    }
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onModifierKeyChange)
    window.removeEventListener('keyup', onModifierKeyChange)
    window.removeEventListener('blur', deactivateInteraction)
    resizeObserver?.disconnect()
    resizeObserver = null
})

</script>


<template>
    <div
        ref="containerRef"
        :class="['relative h-64 overflow-hidden rounded-md', disabled && 'pointer-events-none opacity-50']"
    >
        <LMap
            ref="mapRef"
            :center="center"
            :options="mapOptions"
            :zoom="zoom"
            :useGlobalLeaflet="false"
            style="height:100%"
            @click="onMapClick"
            @ready="onMapReady"
        >
            <LTileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
            <LMarker
                v-if="hasCoordinates"
                :icon="markerIcon as any"
                :lat-lng="[props.latitude as number, props.longitude as number]"
            />
        </LMap>
        <div
            v-if="!disabled && !interactionActive"
            class="pointer-events-none absolute inset-x-0 top-3 right-3 z-[1000] flex justify-end"
        >
            <span class="rounded-md bg-darkgrey px-3 py-1.5 text-xs font-medium text-white shadow">
                Mapu ovládate podržaním Ctrl alebo ⌘
            </span>
        </div>
    </div>
</template>

<style scoped>
:deep(.patient-location-marker) {
    background: transparent;
    border: 0;
    color: var(--c-accent);
    font-size: 2rem;
    line-height: 1;
    text-align: center;
}
</style>
