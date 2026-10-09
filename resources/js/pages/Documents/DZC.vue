<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { usePublicDocument, type PublicDocumentProps } from '@/composables/usePublicDocument'
import DocumentShell from '@/components/DocumentShell.vue'
import api from '@/services/api'

const props = defineProps<PublicDocumentProps>()
const route = useRoute()
const { previewUrl, getPublicLink, documentId } = usePublicDocument(props, {
    privatePreviewUrl: `/v1/dzcs/${route.params.documentId}/preview`
})
const visible = ref(false)
const busy = ref(false)
const error = ref('')
const options = ref<any>(null)
const endKm = ref<number | null>(null)
const startKm = computed(() => endKm.value === null || !options.value ? null : Math.round((endKm.value - options.value.calculated_km) * 1000) / 1000)
const difference = computed(() => startKm.value === null || !options.value?.previous ? null : Math.round((startKm.value - Number(options.value.previous.end_km)) * 1000) / 1000)
const valid = computed(() => endKm.value !== null && Number.isFinite(endKm.value) && endKm.value >= 0 && endKm.value <= 999999999 && startKm.value !== null && startKm.value >= 0 && (!options.value?.previous || endKm.value >= Number(options.value.previous.end_km)))
const actions = computed(() => [
    ...(!props.isPublic ? [{ id: 'download-xlsx', icon: 'bi bi-download', label: 'Exportovať XLSX', adonis: true, compact: false }] : []),
    { id: 'download-pdf', icon: 'bi bi-file-earmark-pdf', label: 'PDF', tooltip: 'PDF' },
])
function saveBlob(data: BlobPart, mimeType: string, filename: string) {
    const url = URL.createObjectURL(new Blob([data], { type: mimeType }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = filename
    anchor.click()
    setTimeout(() => URL.revokeObjectURL(url), 100)
}
async function showError(exception: any) {
    let body = exception?.response?.data
    if (body instanceof Blob) {
        try { body = JSON.parse(await body.text()) } catch { body = null }
    }
    error.value = body?.errors ? Object.values(body.errors).flat().join('\n') : (body?.message ?? 'Export sa nepodaril. Skúste ho znova.')
}
async function handleActionClick(actionId: string) {
    if (busy.value) return
    const id = String(documentId.value)
    error.value = ''
    if (actionId === 'download-pdf' && props.isPublic && props.signature) {
        window.open(getPublicLink({ download: true, format: 'pdf' }), '_blank')
        return
    }
    busy.value = true
    try {
        if (actionId === 'download-xlsx' && !props.isPublic) {
            // Every export asks again, even when a previous reading is available.
            options.value = null
            endKm.value = null
            const response = await api.get(`/v1/dzcs/${id}/export-options`)
            options.value = response.data.data
            endKm.value = options.value.end_km
            visible.value = true
        } else if (actionId === 'download-pdf') {
            const response = await api.get(`/v1/dzcs/${id}/download`, { responseType: 'blob' })
            saveBlob(response.data, 'application/pdf', `dzc_${id}.pdf`)
        }
    } catch (exception) {
        await showError(exception)
    } finally {
        busy.value = false
    }
}
async function exportXlsx() {
    if (busy.value || !valid.value || !options.value) return
    busy.value = true
    error.value = ''
    try {
        const response = await api.post(`/v1/dzcs/${documentId.value}/xlsx`, {
            end_km: endKm.value,
            route_fingerprint: options.value.route_fingerprint,
        }, { responseType: 'blob' })
        saveBlob(response.data, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', `kniha_jazd_${documentId.value}.xlsx`)
        visible.value = false
    } catch (exception) {
        await showError(exception)
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <p v-if="error && !visible" role="alert" class="text-danger whitespace-pre-line p-4">{{ error }}</p>
    <DocumentShell title="Denný záznam ciest" :previewUrl="previewUrl" :actions="actions" :showPrintButton="true" @actionClick="handleActionClick" />
    <Dialog v-model:visible="visible" header="Tachometer pre export XLSX" modal :closable="!busy" :style="{ width: '35rem', maxWidth: '96vw' }">
        <form v-if="options" class="flex flex-col gap-4" @submit.prevent="exportXlsx">
            <p>Vozidlo {{ options.car }} · obdobie {{ options.from }} – {{ options.to }}</p>
            <p>Zadajte stav tachometra po poslednej jazde dňa <strong>{{ options.reading_date }}</strong>. Nejde o dnešný stav vozidla, ak od uvedenej jazdy nasledovali ďalšie jazdy.</p>
            <label for="odometer-end">Konečný stav tachometra (km)</label>
            <InputNumber inputId="odometer-end" v-model="endKm" :min="0" :max="999999999" :maxFractionDigits="3" :useGrouping="false" :disabled="busy" fluid />
            <p>Vypočítaná vzdialenosť: {{ options.calculated_km }} km</p>
            <p v-if="startKm !== null">Dopočítaný počiatočný stav: <strong>{{ startKm }} km</strong></p>
            <p v-if="options.previous">Predchádzajúci zadaný stav: {{ options.previous.end_km }} km k {{ options.previous.period_end }}.</p>
            <p v-if="difference !== null">Rozdiel: {{ difference }} km. Rozdiel sa nepriradí pacientom ani sa ním neupraví vypočítaná trasa.</p>
            <p v-if="options.route_changed">Trasa sa od predchádzajúceho exportu zmenila. Overte zadaný konečný stav pre tento záznam.</p>
            <p>V XLSX bude rozlíšený zadaný konečný stav a dopočítané údaje. Súbor obsahuje adresy bez mien pacientov.</p>
            <p v-if="endKm !== null && !valid" role="alert" class="text-danger">Konečný stav musí pokryť vypočítané kilometre a nesmie byť nižší než predchádzajúci zadaný stav.</p>
            <p v-if="error" role="alert" class="text-danger whitespace-pre-line">{{ error }}</p>
            <Button type="submit" label="Potvrdiť stav a stiahnuť XLSX" :loading="busy" :disabled="busy || !valid" />
        </form>
    </Dialog>
</template>
