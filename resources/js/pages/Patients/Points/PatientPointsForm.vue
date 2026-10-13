<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useToast } from 'primevue/usetoast'

import { usePatientStore } from '@/stores/patientStore'
import api from '@/services/api'
import { toApiDate, parseDateInput } from '@/utils/dateUtils'

import FormField from '@/components/FormField.vue'
import AppButton from '@/components/AppButton.vue'
import PresetMultiDatePicker from '@/components/Date/PresetMultiDatePicker.vue'

type Option = {
    id: number
    code: string
    description: string
}

const patientStore = usePatientStore()
patientStore.loadFromStorage()

const { current: currentPatient } = storeToRefs(patientStore)

const toast = useToast()

const dates = ref<Date[]>([])
const referralDate = ref<Date | null>(null)

const diagnosis = ref<Option | null>(null)
const filteredDiagnoses = ref<Option[]>([])

const procedure = ref<Option | null>(null)
const filteredProcedures = ref<Option[]>([])

const quantity = ref<number | null>(1)

const submitted = ref(false)
const saving = ref(false)

const emitted = defineEmits<{
    (e: 'created'): void
}>()

const patientDeathDate = computed<Date | null>(() => {
    const raw = (currentPatient.value as any)?.death_date

    if (!raw || typeof raw !== 'string') {
        return null
    }

    const d = new Date(`${raw.slice(0, 10)}T00:00:00`)

    if (isNaN(d.getTime())) {
        return null
    }

    return new Date(
        d.getFullYear(),
        d.getMonth(),
        d.getDate(),
    )
})

const maxSelectableDate = computed<Date | undefined>(() => {
    return patientDeathDate.value ?? undefined
})

function datesError() {
    if (!submitted.value) {
        return null
    }

    if (!dates.value?.length) {
        return 'Dátum je povinný.'
    }

    return null
}

function diagnosisError() {
    if (!submitted.value) {
        return null
    }

    if (!diagnosis.value) {
        return 'Diagnóza je povinná.'
    }

    return null
}

function procedureError() {
    if (!submitted.value) {
        return null
    }

    if (!procedure.value) {
        return 'Výkon je povinný.'
    }

    return null
}

function quantityError() {
    if (!submitted.value) {
        return null
    }

    if (!quantity.value || quantity.value <= 0) {
        return 'Počet je povinný.'
    }

    return null
}

function referralDateError() {
    if (!submitted.value) {
        return null
    }

    if (!referralDate.value) {
        return 'Dátum je povinný.'
    }

    return null
}

function normalizeSelectedDates(input: unknown): Date[] {
    const arr = Array.isArray(input)
        ? input
        : []

    const normalized = arr
        .map((d) => parseDateInput(d as any))
        .filter((d): d is Date => !!d)
        .map((d) => new Date(
            d.getFullYear(),
            d.getMonth(),
            d.getDate(),
        ))
        .filter((d) => !isAfterPatientDeath(d))

    const map = new Map<string, Date>()

    for (const d of normalized) {
        const dateStr = toApiDate(d)

        if (dateStr) {
            map.set(dateStr, d)
        }
    }

    return Array.from(map.entries())
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([, d]) => d)
}

function truncate(text: string, max = 60) {
    if (!text) {
        return ''
    }

    return text.length > max
        ? text.slice(0, max) + '…'
        : text
}

function isAfterPatientDeath(date: Date | null): boolean {
    if (!date || !patientDeathDate.value) {
        return false
    }

    const left = toApiDate(date)
    const right = toApiDate(patientDeathDate.value)

    return !!left && !!right && left > right
}

function extractArray(raw: any): any[] {
    if (Array.isArray(raw)) {
        return raw
    }

    const candidates = [
        raw?.data,
        raw?.data?.items,
        raw?.data?.data,
        raw?.data?.data?.items,
        raw?.data?.data?.data,
        raw?.items,
        raw?.items?.data,
    ]

    for (const candidate of candidates) {
        if (Array.isArray(candidate)) {
            return candidate
        }
    }

    return []
}

function getNewestReferenceDate(rows: any[]): Date | null {
    let newestDate: Date | null = null
    let newestDateStr: string | null = null

    for (const row of rows) {
        const parsed = parseDateInput(row?.reference_date as any)

        if (!parsed) {
            continue
        }

        const normalized = new Date(
            parsed.getFullYear(),
            parsed.getMonth(),
            parsed.getDate(),
        )

        const normalizedStr = toApiDate(normalized)

        if (!normalizedStr) {
            continue
        }

        if (!newestDateStr || normalizedStr > newestDateStr) {
            newestDate = normalized
            newestDateStr = normalizedStr
        }
    }

    return newestDate
}

let referralDateLoadSeq = 0

async function loadLatestReferralDateForPatient(
    patientId: number | null | undefined,
) {
    const seq = ++referralDateLoadSeq

    if (!patientId) {
        referralDate.value = null
        return
    }

    try {
        const res = await api.get(
            'v1/patient-points',
            {
                params: {
                    filter: {
                        patient_id: patientId,
                    },
                    per_page: 25,
                    page: 1,
                    sort: '-reference_date',
                },
            },
        )

        if (seq !== referralDateLoadSeq) {
            return
        }

        referralDate.value = getNewestReferenceDate(
            extractArray(res.data),
        )
    } catch (e) {
        if (seq !== referralDateLoadSeq) {
            return
        }

        console.error(
            'Failed to load latest patient point reference date',
            e,
        )

        referralDate.value = null
    }
}

watch(
    () => Number((currentPatient.value as any)?.id) || null,
    (patientId) => {
        void loadLatestReferralDateForPatient(patientId)
    },
    {
        immediate: true,
    },
)

async function searchOptions(
    endpoint: string,
    event: { query: string },
    params: Record<string, string | number | null> = {},
) {
    try {
        const q = (event.query ?? '').trim()

        const res = await api.get(
            endpoint,
            {
                params: {
                    q,
                    limit: 25,
                    ...params,
                },
            },
        )

        return extractArray(res.data)
    } catch (e) {
        console.error('Failed to load', endpoint, e)
        return []
    }
}

async function searchDiagnoses(event: { query: string }) {
    const branchId = Number((currentPatient.value as any)?.branch_id) || null

    if (!branchId) {
        filteredDiagnoses.value = []
        return
    }

    const arr = await searchOptions(
        'v1/clinical-suggestions/diagnoses',
        event,
        { branch_id: branchId },
    )

    filteredDiagnoses.value = arr.map((d) => ({
        id: d.id,
        code: d.code ?? '',
        description: d.description ?? '',
    }))
}

async function searchProcedures(event: { query: string }) {
    const branchId = Number((currentPatient.value as any)?.branch_id) || null

    if (!branchId || !diagnosis.value?.id) {
        filteredProcedures.value = []
        return
    }

    const arr = await searchOptions(
        'v1/clinical-suggestions/procedures',
        event,
        {
            branch_id: branchId,
            diagnosis_id: diagnosis.value.id,
        },
    )

    filteredProcedures.value = arr.map((p) => ({
        id: p.id,
        code: p.code ?? '',
        description: p.description ?? '',
    }))
}

watch(
    () => diagnosis.value?.id ?? null,
    () => {
        procedure.value = null
        filteredProcedures.value = []
    },
)

function buildPatientPointPayloadForDate(
    dateOverride: Date,
) {
    if (!currentPatient.value) {
        throw new Error('No patient selected')
    }

    const patient: any = currentPatient.value

    return {
        patient_id: patient.id,
        patient_personal_number:
            patient.personal_number ?? null,
        patient_name:
            `${patient.first_name ?? ''} ${patient.last_name ?? ''}`.trim(),
        date: toApiDate(dateOverride),
        reference_date: toApiDate(
            parseDateInput(referralDate.value) ?? new Date(),
        ),
        diagnosis_code:
            diagnosis.value?.code ?? null,
        procedure_code:
            procedure.value?.code ?? null,
        quantity:
            quantity.value ?? 1,
        branch_id:
            patient.branch_id,
    }
}

async function onSubmit() {
    submitted.value = true

    const normalizedDates = normalizeSelectedDates(
        dates.value,
    )

    dates.value = normalizedDates

    const parsedReferral = parseDateInput(
        referralDate.value,
    )

    if (
        !normalizedDates.length ||
        !parsedReferral ||
        !procedure.value ||
        !diagnosis.value ||
        !quantity.value ||
        quantity.value <= 0
    ) {
        return
    }

    const referralDateStr = toApiDate(parsedReferral)

    const hasDateBeforeReferral = normalizedDates.some((d) => {
        const selectedDateStr = toApiDate(d)

        return (
            !!selectedDateStr &&
            !!referralDateStr &&
            selectedDateStr < referralDateStr
        )
    })

    if (hasDateBeforeReferral) {
        toast.add({
            severity: 'warn',
            summary: 'Neplatné dátumy',
            detail:
                'Dátum odporučenia musí byť rovnaký alebo starší ako všetky vybrané dátumy.',
            life: 3500,
        })

        return
    }

    saving.value = true

    try {
        const payloads = normalizedDates.map((d) =>
            buildPatientPointPayloadForDate(d),
        )

        for (const payload of payloads) {
            await api.post(
                'v1/patient-points',
                payload,
            )
        }

        toast.add({
            severity: 'success',
            summary: 'Vytvorené',
            detail: 'Body boli pridané',
            life: 3000,
        })

        emitted('created')
    } catch (e: any) {
        const status = e?.response?.status

        const detail =
            status === 403
                ? 'Nemáte oprávnenie vytvoriť body pre tohto pacienta. Skontrolujte priradenie sestry k pacientovi.'
                : e?.response?.data?.message ||
                  e?.message ||
                  'Chyba servera'

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail,
            life: 4000,
        })
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <form
        class="flex flex-col gap-6"
        @submit.prevent="onSubmit"
    >
        <section class="bg-tag3 p-6 rounded-md">
            <div class="grid grid-cols-12 gap-4 items-end">
                <!-- Dátumy -->
                <div class="col-span-12 md:col-span-3">
                    <FormField
                        label="Dátum"
                        required
                        :error="datesError()"
                        v-slot="{ describedBy, invalid }"
                    >
                        <PresetMultiDatePicker
                            v-model="dates"
                            class="w-full"
                            :maxDate="maxSelectableDate"
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                </div>

                <!-- Diagnóza -->
                <div class="col-span-12 md:col-span-3">
                    <FormField
                        label="Diagnóza"
                        required
                        :error="diagnosisError()"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <AutoComplete
                            :id="id"
                            v-model="diagnosis"
                            :suggestions="filteredDiagnoses"
                            :virtualScrollerOptions="{ itemSize: 38 }"
                            optionLabel="code"
                            dropdown
                            dropdownMode="blank"
                            :minLength="0"
                            completeOnFocus
                            class="w-full"
                            inputClass="w-full! border-none!"
                            :invalid="invalid"
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                            @complete="searchDiagnoses"
                        >
                            <template #option="{ option }">
                                <div class="flex flex-col">
                                    <span class="font-medium">
                                        {{ option.code }}
                                    </span>

                                    <span class="text-mini text-accent">
                                        {{ truncate(option.description, 40) }}
                                    </span>
                                </div>
                            </template>
                        </AutoComplete>
                    </FormField>
                </div>

                <!-- Výkon -->
                <div class="col-span-12 md:col-span-3">
                    <FormField
                        label="Výkon"
                        required
                        :error="procedureError()"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <AutoComplete
                            :id="id"
                            v-model="procedure"
                            :suggestions="filteredProcedures"
                            :virtualScrollerOptions="{ itemSize: 38 }"
                            optionLabel="code"
                            dropdown
                            dropdownMode="blank"
                            :minLength="0"
                            completeOnFocus
                            :disabled="!diagnosis"
                            class="w-full"
                            inputClass="w-full! border-none!"
                            :invalid="invalid"
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                            @complete="searchProcedures"
                        >
                            <template #option="{ option }">
                                <div class="flex flex-col">
                                    <span class="font-medium">
                                        {{ option.code }}
                                    </span>

                                    <span class="text-mini text-accent">
                                        {{ truncate(option.description, 60) }}
                                    </span>
                                </div>
                            </template>
                        </AutoComplete>
                    </FormField>
                </div>

                <!-- Počet -->
                <div class="col-span-12 md:col-span-1">
                    <FormField
                        label="Počet"
                        required
                        :error="quantityError()"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <InputNumber
                            :id="id"
                            v-model="quantity"
                            :min="1"
                            :max="100"
                            class="w-full"
                            inputClass="w-full! border-none!"
                            :invalid="invalid"
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                </div>

                <!-- Dátum odporučenia -->
                <div class="col-span-12 md:col-span-2">
                    <FormField
                        label="Dátum odporučenia"
                        required
                        :error="referralDateError()"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <DatePicker
                            :id="id"
                            v-model="referralDate"
                            dateFormat="dd.mm.yy"
                            :showIcon="false"
                            :manualInput="false"
                            :maxDate="maxSelectableDate"
                            class="w-full"
                            inputClass="w-full! border-none!"
                            :invalid="invalid"
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                </div>

                <!-- Add button -->
                <div class="col-span-12 md:col-span-12 flex justify-end">
                    <AppButton
                        type="submit"
                        label="Pridať výkony"
                        :loading="saving"
                    />
                </div>
            </div>
        </section>
    </form>
</template>