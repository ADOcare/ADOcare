<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import PatientPointForm from './PatientPointForm.vue'
import { canBatchEdit, type PatientPointApi } from './patientPointBatch'
import { parseDateInput } from '@/utils/dateUtils'
import { useApi } from '@/composables/useApi'
import FormField from '@/components/FormField.vue'

type DayOption = PatientPointApi & { label: string }
type MatchingResponse = {
    data: { items: PatientPointApi[]; meta: { last_page: number } }
}

const props = defineProps<{
    rows: PatientPointApi[]
    modalResolve?: (value?: boolean) => void
}>()
const first = props.rows[0]
const point = first ? {
    id: first.id,
    diagnosis: { id: first.diagnosis_id, code: first.diagnosis_code, description: '' },
    procedure: { id: first.procedure_id, code: first.procedure_code, description: '' },
    referralDate: first.reference_date,
    quantity: first.quantity,
} : null
const { get } = useApi()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const candidates = ref<DayOption[]>([])
const selectedDays = ref<DayOption[]>(props.rows.map(toDayOption))
let disposed = false

const selectedIds = computed(() => [...new Set(selectedDays.value.map((row) => row.id))])
const pickerDisabled = computed(() => loading.value || saving.value || !!loadError.value)
const allSelected = computed(() => candidates.value.length > 0
    && candidates.value.every((row) => selectedIds.value.includes(row.id)))
const validSelection = computed(() => selectedIds.value.length >= 1
    && selectedIds.value.length <= 1000
    && !!first
    && canBatchEdit([first, ...selectedDays.value])
    && selectedDays.value.every((row) => candidates.value.some((candidate) =>
        candidate.id === row.id && canBatchEdit([first, candidate]))))
const selectionError = computed(() => {
    if (loading.value || loadError.value) return ''
    if (selectedIds.value.length < 1) return 'Vyberte aspoň jeden deň.'
    if (selectedIds.value.length > 1000) return 'Naraz je možné upraviť najviac 1 000 záznamov.'
    if (!validSelection.value) return 'Niektoré vybrané záznamy už nemajú zhodné hodnoty. Vyberte ich znova zo zoznamu.'
    return ''
})

function toDayOption(row: PatientPointApi): DayOption {
    return {
        ...row,
        label: parseDateInput(row.date)?.toLocaleDateString('sk-SK') ?? `Záznam #${row.id}`,
    }
}

function removeDay(id: number) {
    if (!pickerDisabled.value) selectedDays.value = selectedDays.value.filter((row) => row.id !== id)
}

function isDayDisabled(day: DayOption) {
    return selectedIds.value.length >= 1000 && !selectedIds.value.includes(day.id)
}

function toggleAllDays(checked: boolean) {
    if (!pickerDisabled.value && (!checked || candidates.value.length <= 1000)) {
        selectedDays.value = checked ? [...candidates.value] : []
    }
}

async function loadMatchingDays() {
    loading.value = true
    loadError.value = ''
    try {
        if (!first) throw new Error('Chýba východiskový záznam.')
        const all: DayOption[] = []
        let page = 1
        let lastPage = 1
        do {
            const response = await get<MatchingResponse>(`patient-points/${first.id}/matching`, { page })
            if (response.error) throw response.error
            if (disposed) return
            const data = response.data?.data
            if (!Array.isArray(data?.items) || !Number.isInteger(data.meta?.last_page) || data.meta.last_page < 1) {
                throw new Error('Nepodarilo sa načítať zhodné dni.')
            }
            all.push(...data.items.map(toDayOption))
            lastPage = data.meta.last_page
            page += 1
        } while (page <= lastPage)
        candidates.value = all
    } catch (error) {
        if (disposed) return
        loadError.value = error instanceof Error ? error.message : 'Nepodarilo sa načítať zhodné dni.'
        toast.add({ severity: 'error', summary: 'Chyba', detail: loadError.value, life: 5000 })
    } finally {
        if (!disposed) loading.value = false
    }
}

onMounted(loadMatchingDays)
onBeforeUnmount(() => { disposed = true })
</script>

<template>
    <div class="flex flex-col gap-4">
        <FormField
            label="Vybrať dni"
            required
        >
            <Select
                inputId="bulk-edit-days"
                v-model="selectedDays"
                :options="candidates"
                dataKey="id"
                optionLabel="label"
                multiple
                filter
                filterPlaceholder="Hľadať deň"
                placeholder="Vyberte dni"
                :optionDisabled="isDayDisabled"
                :loading="loading"
                :disabled="pickerDisabled"
                :invalid="!!selectionError"
                class="w-full"
            >
                <template #header>
                    <div class="p-4!">
                        <Checkbox inputId="bulk-edit-all-days" :modelValue="allSelected" binary
                            :disabled="pickerDisabled || (!allSelected && candidates.length > 1000)"
                            @update:modelValue="toggleAllDays" />
                        <label for="bulk-edit-all-days" class="ml-2 text-normal">Vybrať všetky dni</label>
                        <small v-if="candidates.length > 1000" class="block text-accent">
                            Naraz je možné upraviť najviac 1 000 záznamov.
                        </small>
                    </div>
                </template>
                <template #option="{ option }">
                    <div class="flex items-center gap-2">
                        <Checkbox :modelValue="selectedIds.includes(option.id)" binary
                            :tabindex="-1" readonly :disabled="isDayDisabled(option)" />
                        <span>{{ option.label }}</span>
                    </div>
                </template>
                <template #value="{ placeholder }">
                    <div v-if="selectedDays.length" class="flex flex-wrap gap-2">
                        <div v-for="day in selectedDays" :key="day.id"
                            class="inline-flex items-center gap-2 rounded-md bg-darkgrey px-3 py-1 text-normal text-white">
                            <span :title="`Záznam #${day.id}`">{{ day.label }}</span>
                            <button type="button" :disabled="pickerDisabled"
                                :aria-label="`Odobrať ${day.label}`"
                                class="bi bi-x-lg text-[0.65rem] text-lightgrey"
                                @mousedown.stop @keydown.stop @click.stop="removeDay(day.id)" />
                        </div>
                    </div>
                    <span v-else>{{ placeholder }}</span>
                </template>
            </Select>
            <small v-if="selectionError" role="alert" class="text-danger">{{ selectionError }}</small>
            <div v-if="loadError" role="alert" class="text-danger">
                {{ loadError }}
                <button type="button" class="underline" @click="loadMatchingDays">Skúsiť znova</button>
            </div>
        </FormField>

        <PatientPointForm :point="point" :bulkIds="selectedIds"
            :bulkSelectionValid="validSelection && !loading && !loadError"
            :modalResolve="modalResolve" @saving-change="saving = $event" />
    </div>
</template>
