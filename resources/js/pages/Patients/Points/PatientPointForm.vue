<script setup lang="ts">
import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'

import FormField from '@/components/FormField.vue'
import AppButton from '@/components/AppButton.vue'

import api from '@/services/api'
import {
    parseDateInput,
    toApiDate,
} from '@/utils/dateUtils'

type Option = {
    id: number
    code: string
    description: string
}

const props = defineProps<{
    point?: any
    modalResolve?: (value?: any) => void
}>()

const toast = useToast()

const editPoint = ref<any>(
    props.point
        ? { ...props.point }
        : null,
)

const diagnosis = ref<Option | null>(null)
const filteredDiagnoses = ref<Option[]>([])

const procedure = ref<Option | null>(null)
const filteredProcedures = ref<Option[]>([])

const submitted = ref(false)
const saving = ref(false)

if (editPoint.value) {
    editPoint.value.date =
        parseDateInput(editPoint.value.date) ?? null

    editPoint.value.referralDate =
        parseDateInput(editPoint.value.referralDate) ?? null

    diagnosis.value =
        editPoint.value.diagnosis
            ? {
                id:
                    editPoint.value.diagnosis.id ?? 0,
                code:
                    editPoint.value.diagnosis.code ?? '',
                description:
                    editPoint.value.diagnosis.description ?? '',
            }
            : null

    procedure.value =
        editPoint.value.procedure
            ? {
                id:
                    editPoint.value.procedure.id ?? 0,
                code:
                    editPoint.value.procedure.code ?? '',
                description:
                    editPoint.value.procedure.description ?? '',
            }
            : null
}

function dateError() {
    if (!submitted.value) {
        return null
    }

    if (!editPoint.value?.date) {
        return 'Dátum je povinný.'
    }

    return null
}

function referralDateError() {
    if (!submitted.value) {
        return null
    }

    if (!editPoint.value?.referralDate) {
        return 'Dátum odporučenia je povinný.'
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

    const quantity = Number(
        editPoint.value?.quantity ?? 0,
    )

    if (quantity <= 0) {
        return 'Počet je povinný.'
    }

    return null
}

function extractArray(raw: any): any[] {
    if (Array.isArray(raw)) {
        return raw
    }

    const candidates = [
        raw?.data,
        raw?.data?.items,
        raw?.items,
        raw?.data?.data,
    ]

    for (const candidate of candidates) {
        if (Array.isArray(candidate)) {
            return candidate
        }
    }

    return []
}

async function searchOptions(
    endpoint: string,
    query: string,
): Promise<Option[]> {
    try {
        const q = (query ?? '').trim()

        const response = await api.get(
            endpoint,
            {
                params: {
                    q,
                    per_page: 25,
                    page: 1,
                    sort: 'code',
                },
            },
        )

        return extractArray(response.data).map(
            (item: any) => ({
                id: item.id,
                code: item.code ?? '',
                description:
                    item.description ?? '',
            }),
        )
    } catch (error) {
        console.error(
            'Failed to load',
            endpoint,
            error,
        )

        return []
    }
}

async function searchDiagnoses(
    event: { query: string },
) {
    filteredDiagnoses.value =
        await searchOptions(
            'v1/diagnoses',
            event.query,
        )
}

async function searchProcedures(
    event: { query: string },
) {
    filteredProcedures.value =
        await searchOptions(
            'v1/procedures',
            event.query,
        )
}

async function savePoint() {
    if (!editPoint.value) {
        return
    }

    submitted.value = true

    if (
        !editPoint.value.date ||
        !editPoint.value.referralDate ||
        !diagnosis.value ||
        !procedure.value ||
        !editPoint.value.quantity ||
        Number(editPoint.value.quantity) <= 0
    ) {
        return
    }

    saving.value = true

    try {
        const payload = {
            date: toApiDate(
                parseDateInput(
                    editPoint.value.date,
                ) ?? new Date(),
            ),

            diagnosis_code:
                diagnosis.value.code,

            procedure_code:
                procedure.value.code,

            reference_date: toApiDate(
                parseDateInput(
                    editPoint.value.referralDate,
                ) ?? new Date(),
            ),

            quantity:
                Number(editPoint.value.quantity),
        }

        await api.put(
            `v1/patient-points/${editPoint.value.id}`,
            payload,
        )

        toast.add({
            severity: 'success',
            summary: 'Uložené',
            detail: 'Záznam bol upravený',
            life: 3000,
        })

        props.modalResolve?.(true)
    } catch (error: any) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail:
                error?.response?.data?.message ||
                error?.message ||
                'Chyba pri ukladaní',
            life: 4000,
        })
    } finally {
        saving.value = false
    }
}

function close() {
    props.modalResolve?.(false)
}
</script>

<template>
    <form
        v-if="editPoint"
        class="flex flex-col gap-6"
        @submit.prevent="savePoint"
    >
        <div
            class="
                grid
                grid-cols-1
                md:grid-cols-2
                gap-4
                items-start
            "
        >
            <FormField
                label="Dátum"
                required
                :error="dateError()"
                v-slot="{
                    id,
                    describedBy,
                    invalid,
                }"
            >
                <DatePicker
                    :id="id"
                    v-model="editPoint.date"
                    dateFormat="dd.mm.yy"
                    :showIcon="false"
                    :manualInput="false"
                    class="w-full"
                    inputClass="w-full!"
                    :invalid="invalid"
                    :aria-invalid="invalid || undefined"
                    :aria-describedby="describedBy"
                />
            </FormField>

            <FormField
                label="Dátum odporučenia"
                required
                :error="referralDateError()"
                v-slot="{
                    id,
                    describedBy,
                    invalid,
                }"
            >
                <DatePicker
                    :id="id"
                    v-model="editPoint.referralDate"
                    dateFormat="dd.mm.yy"
                    :showIcon="false"
                    :manualInput="false"
                    class="w-full"
                    inputClass="w-full!"
                    :invalid="invalid"
                    :aria-invalid="invalid || undefined"
                    :aria-describedby="describedBy"
                />
            </FormField>
        </div>

        <div
            class="
                grid
                grid-cols-1
                md:grid-cols-3
                gap-4
                items-start
            "
        >
            <FormField
                label="Diagnóza"
                required
                :error="diagnosisError()"
                v-slot="{
                    id,
                    describedBy,
                    invalid,
                }"
            >
                <AutoComplete
                    :id="id"
                    v-model="diagnosis"
                    :suggestions="filteredDiagnoses"
                    :virtualScrollerOptions="{
                        itemSize: 38,
                    }"
                    optionLabel="code"
                    dropdown
                    dropdownMode="blank"
                    :minLength="0"
                    completeOnFocus
                    class="w-full"
                    inputClass="w-full!"
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
                                {{ option.description }}
                            </span>
                        </div>
                    </template>
                </AutoComplete>
            </FormField>

            <FormField
                label="Výkon"
                required
                :error="procedureError()"
                v-slot="{
                    id,
                    describedBy,
                    invalid,
                }"
            >
                <AutoComplete
                    :id="id"
                    v-model="procedure"
                    :suggestions="filteredProcedures"
                    :virtualScrollerOptions="{
                        itemSize: 38,
                    }"
                    optionLabel="code"
                    dropdown
                    dropdownMode="blank"
                    :minLength="0"
                    completeOnFocus
                    class="w-full"
                    inputClass="w-full!"
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
                                {{ option.description }}
                            </span>
                        </div>
                    </template>
                </AutoComplete>
            </FormField>

            <FormField
                label="Počet"
                required
                :error="quantityError()"
                v-slot="{
                    id,
                    describedBy,
                    invalid,
                }"
            >
                <InputNumber
                    :id="id"
                    :modelValue="
                        editPoint.quantity
                    "
                    :min="1"
                    :max="100"
                    class="w-full"
                    inputClass="w-full!"
                    :invalid="invalid"
                    :aria-invalid="invalid || undefined"
                    :aria-describedby="describedBy"
                    @update:modelValue="
                        editPoint.quantity =
                            $event
                                ? Number($event)
                                : null
                    "
                />
            </FormField>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <AppButton
                type="button"
                label="Zrušiť"
                variant="secondary"
                :disabled="saving"
                @click="close"
            />

            <AppButton
                type="submit"
                label="Uložiť"
                :loading="saving"
            />
        </div>
    </form>
</template>