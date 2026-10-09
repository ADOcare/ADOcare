<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const props = withDefaults(defineProps<{ kind?: 'batch' | 'journal' }>(), { kind: 'batch' })
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const branchId = computed(() => auth.currentBranch?.id)
const now = new Date()
const month = ref<Date | null>(new Date(now.getFullYear(), now.getMonth() - 1, 1))
const character = ref('N')
const insurerId = ref<number | null>(null)
const carId = ref<number | null>(null)
const insurers = ref<{ id: number; name: string }[]>([])
const cars = ref<{ id: number; evc: string; model: string }[]>([])
const busy = ref(false)
const error = ref('')
const preview = ref<any>(null)
const previewRequest = ref<any>(null)
const selectedIds = ref<number[]>([])
const showPreview = ref(false)
let formVersion = 0
const types = [
    { code: 'N', name: 'Nová – tuzemskí (N)' },
    { code: 'O', name: 'Opravná – tuzemskí (O)' },
    { code: 'A', name: 'Aditívna – tuzemskí (A)' },
    { code: 'E', name: 'Nová – zahraničný nárok (E)' },
    { code: 'F', name: 'Opravná – zahraničný nárok (F)' },
    { code: 'G', name: 'Aditívna – zahraničný nárok (G)' },
    { code: 'I', name: 'Nová – osobitný režim (I)' },
    { code: 'J', name: 'Opravná – osobitný režim (J)' },
    { code: 'K', name: 'Aditívna – osobitný režim (K)' },
]
const selected = computed(() => (preview.value?.candidates ?? []).filter((row: any) => selectedIds.value.includes(row.journey_id)))
const totalKm = computed(() => selected.value.reduce((sum: number, row: any) => sum + row.kilometers, 0))
const totalAmount = computed(() => selected.value.reduce((sum: number, row: any) => sum + row.amount, 0).toFixed(2))
const isCorrection = computed(() => ['O', 'F', 'J'].includes(character.value))

function errorMessage(exception: any): string {
    const body = exception?.response?.data
    return body?.errors ? Object.values(body.errors).flat().join('\n') : (body?.message ?? 'Operácia sa nepodarila. Skúste ju znova.')
}
function dateOnly(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
function period(): string[] {
    const value = month.value!
    return [dateOnly(new Date(value.getFullYear(), value.getMonth(), 1)), dateOnly(new Date(value.getFullYear(), value.getMonth() + 1, 0))]
}
watch([branchId, month, character, insurerId, carId], () => {
    formVersion++
    preview.value = null
    previewRequest.value = null
    selectedIds.value = []
    showPreview.value = false
    error.value = ''
})
watch(branchId, async (id) => {
    cars.value = []
    carId.value = null
    if (!id) return
    try {
        const response = await api.get('/v1/transport/cars', { params: { branch_id: id } })
        if (branchId.value !== id) return
        cars.value = response.data?.data ?? []
        if (cars.value.length === 1) carId.value = cars.value[0]!.id
    } catch (exception) {
        if (branchId.value === id) error.value = errorMessage(exception)
    }
}, { immediate: true })
onMounted(async () => {
    if (props.kind !== 'batch') return
    try {
        const response = await api.get('/v1/insurance-companies', { params: { paginate: 0 } })
        const data = response.data?.data
        insurers.value = Array.isArray(data) ? data : (data?.items ?? [])
    } catch (exception) {
        error.value = errorMessage(exception)
    }
})

async function generate() {
    if (busy.value) return
    if (!branchId.value || !month.value || !carId.value || (props.kind === 'batch' && !insurerId.value)) {
        error.value = 'Vyberte prevádzku, obdobie, vozidlo a pri dávke aj poisťovňu.'
        return
    }
    const version = formVersion
    const dates = period()
    busy.value = true
    error.value = ''
    try {
        if (props.kind === 'journal') {
            const response = await api.post('/v1/dzcs', { branch_id: branchId.value, car_id: carId.value, start: dates[0], end: dates[1] })
            await router.push(`/documents/dzc/${response.data.data.document_id}`)
        } else {
            const request = { branch: { id: branchId.value }, insurance: { id: insurerId.value }, batchType: { code: character.value }, period: dates, car_id: carId.value }
            const response = await api.post('/v1/batches/kilometers/preview', request)
            if (version !== formVersion) return
            preview.value = response.data.data
            previewRequest.value = request
            selectedIds.value = preview.value.candidates.filter((row: any) => row.suggested).map((row: any) => row.journey_id)
            showPreview.value = true
        }
    } catch (exception) {
        error.value = errorMessage(exception)
    } finally {
        busy.value = false
    }
}
async function saveBatch() {
    if (busy.value || !previewRequest.value || !selectedIds.value.length || !preview.value?.can_create) return
    busy.value = true
    error.value = ''
    try {
        const response = await api.post('/v1/kilometers-batches', { ...previewRequest.value, journeyIds: selectedIds.value, previewToken: preview.value.previewToken })
        showPreview.value = false
        toast.add({ severity: 'success', summary: 'Dávka uložená', detail: 'Uložený súbor je pripravený na stiahnutie.', life: 4000 })
        await router.push(`/documents/kilometers/${response.data.data.document_id}`)
    } catch (exception) {
        error.value = errorMessage(exception)
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <form class="bg-tag3 p-6 rounded-md flex flex-col gap-4" @submit.prevent="generate">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div v-if="kind === 'batch'">
                <label class="block mb-1" for="transport-type">Typ dávky</label>
                <Select inputId="transport-type" v-model="character" :options="types" optionLabel="name" optionValue="code" :disabled="busy" fluid />
            </div>
            <div v-if="kind === 'batch'">
                <label class="block mb-1" for="transport-insurance">Poisťovňa</label>
                <Select inputId="transport-insurance" v-model="insurerId" :options="insurers" optionLabel="name" optionValue="id" :disabled="busy" fluid />
            </div>
            <div>
                <label class="block mb-1" for="transport-month">Obdobie</label>
                <DatePicker inputId="transport-month" v-model="month" view="month" dateFormat="mm/yy" :manualInput="false" :disabled="busy" fluid />
            </div>
            <div>
                <label class="block mb-1" for="transport-car">Vozidlo používané v tomto období</label>
                <Select inputId="transport-car" v-model="carId" :options="cars" optionLabel="evc" optionValue="id" :disabled="busy" fluid />
                <small v-if="!cars.length">Najprv priraďte vozidlo pracovníkovi v nastaveniach vozidiel.</small>
            </div>
        </div>
        <p class="text-sm">Trasa sa vypočíta automaticky zo všetkých evidovaných návštev. Každá spoločná adresa tvorí jednu zastávku. Kniha jázd obsahuje aj návrat na prevádzku.</p>
        <p v-if="error && !showPreview" role="alert" class="text-danger whitespace-pre-line">{{ error }}</p>
        <Button type="submit" :loading="busy" :disabled="busy" :label="kind === 'batch' ? 'Pripraviť výber dopravy' : 'Vytvoriť denný záznam ciest'" />
    </form>

    <Dialog v-model:visible="showPreview" header="Výber dopravy do dávky" modal :closable="!busy" :style="{ width: '70rem', maxWidth: '96vw' }">
        <div v-if="preview" class="flex flex-col gap-4">
            <p>{{ preview.notice }}</p>
            <p v-if="isCorrection">Predvýber označuje zmenené údaje. Vyberte riadky podľa výsledku kontroly poisťovne; nezmenený zamietnutý riadok možno vybrať tiež.</p>
            <div v-if="preview.blocked.length" class="bg-tag3 p-3 rounded">
                <strong>Vynechané alebo zablokované položky</strong>
                <ul class="list-disc pl-5">
                    <li v-for="(item, index) in preview.blocked" :key="index">{{ item.date }} {{ item.address }}: {{ item.reason }}</li>
                </ul>
            </div>
            <p v-if="!preview.candidates.length">Pre túto dávku nie je dostupná žiadna doprava.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left"><th>Vybrať</th><th>Dátum / jazda</th><th>Pacient / výkon</th><th>Odkiaľ → kam</th><th>Km</th><th>Suma</th></tr></thead>
                    <tbody>
                        <tr v-for="row in preview.candidates" :key="row.journey_id" class="border-b">
                            <td class="p-2"><input v-model="selectedIds" type="checkbox" :value="row.journey_id" :disabled="busy" :aria-label="`Vybrať jazdu ${row.journey_id}`" /></td>
                            <td class="p-2">{{ row.date }} / {{ row.journey_id }}</td>
                            <td class="p-2">{{ row.patient_name }} / {{ row.procedure_code }}<small v-if="row.edited" class="block">Zmenené od uloženia</small></td>
                            <td class="p-2">{{ row.origin }} → {{ row.destination }}</td>
                            <td class="p-2">{{ row.kilometers }}</td>
                            <td class="p-2">{{ Number(row.amount).toFixed(2) }} €</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p>Vybrané: {{ selectedIds.length }} jázd · {{ totalKm }} km · {{ totalAmount }} €</p>
            <p v-if="error" role="alert" class="text-danger whitespace-pre-line">{{ error }}</p>
            <Button label="Uložiť dávku" :loading="busy" :disabled="busy || !selectedIds.length || !preview.can_create" @click="saveBatch" />
        </div>
    </Dialog>
</template>
