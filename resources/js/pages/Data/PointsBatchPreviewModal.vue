<script setup lang="ts">
import { computed, ref } from 'vue'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import type { DataTableOptions } from '@/types/datatable'

type PointCandidate = {
    point_id: number
    patient_id: number
    patient_name: string
    personal_number: string
    service_date: string
    created_at: string
    updated_at: string
    procedure_code: string
    diagnosis_code: string
    quantity: number
    amount: number
    edited: boolean
    added_after_new_batch: boolean
    reasons: string[]
}

const props = defineProps<{
    candidates: PointCandidate[]
    initialSelectedPointIds: number[]
    modalResolve?: (value?: { pointIds: number[] } | null) => void
}>()

const selectedPointIds = ref<number[]>([...props.initialSelectedPointIds])

const selectedCandidates = computed(() => {
    const selectedIds = new Set(selectedPointIds.value)
    return props.candidates.filter((row) => selectedIds.has(row.point_id))
})

const sortedCandidates = computed(() => {
    const selectedIds = new Set(selectedPointIds.value)

    return [...props.candidates].sort((left, right) => {
        const leftSelected = selectedIds.has(left.point_id) ? 1 : 0
        const rightSelected = selectedIds.has(right.point_id) ? 1 : 0

        if (leftSelected !== rightSelected) {
            return rightSelected - leftSelected
        }

        return new Date(right.service_date).getTime() - new Date(left.service_date).getTime()
    })
})

const selectedPatientsCount = computed(() => {
    return new Set(selectedCandidates.value.map((row) => row.patient_id)).size
})

const selectedAmount = computed(() => {
    return selectedCandidates.value.reduce((sum, row) => sum + Number(row.amount ?? 0), 0)
})

function formatAmount(value: number) {
    return Number(value ?? 0).toLocaleString('sk-SK', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

function formatDateTime(value: string) {
    if (!value) return '—'

    const date = new Date(value)

    if (Number.isNaN(date.getTime())) return value

    return date.toLocaleString('sk-SK', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

function renderStatus(_: unknown, row: PointCandidate) {
    const labels: string[] = []

    if (row.added_after_new_batch) {
        labels.push('<span class="inline-flex bg-tag3 rounded-md px-2 py-1 text-mini">Pridaný</span>')
    }

    if (row.edited) {
        labels.push('<span class="inline-flex bg-tag3 rounded-md px-2 py-1 text-mini">Upravený</span>')
    }

    return labels.join(' ') || '—'
}

const tableOptions = computed<DataTableOptions<PointCandidate>>(() => ({
    endpointUrl: '',
    localItems: sortedCandidates.value,
    initialSelectedKeys: props.initialSelectedPointIds,
    resetPageOnLocalItemsChange: true,
    rowKey: 'point_id',
    selectable: true,
    defaultPageSize: 50,
    pageSizeOptions: [10, 25, 50],
    columns: [
        { field: 'service_date', header: 'Dátum výkonu', width: '8rem' },
        { field: 'patient_name', header: 'Pacient' },
        { field: 'personal_number', header: 'Rodné číslo', width: '9rem' },
        { field: 'procedure_code', header: 'Výkon', width: '7rem' },
        { field: 'diagnosis_code', header: 'Diagnóza', width: '7rem' },
        { field: 'quantity', header: 'Počet', align: 'right', width: '5rem' },
        {
            field: 'amount',
            header: 'Suma',
            align: 'right',
            width: '7rem',
            render: (value) => `${formatAmount(Number(value))} €`,
        },
        {
            field: 'updated_at',
            header: 'Posledná úprava',
            width: '11rem',
            render: (value) => formatDateTime(String(value ?? '')),
        },
        { field: 'status', header: 'Stav', width: '14rem', render: renderStatus },
    ],
}))

function onRowsSelected(rows: PointCandidate | PointCandidate[]) {
    const selectedRows = Array.isArray(rows) ? rows : [rows]
    selectedPointIds.value = selectedRows.map((row) => row.point_id)
}

function close() {
    props.modalResolve?.(null)
}

function createBatch() {
    const pointIds = selectedPointIds.value

    if (pointIds.length === 0) return

    props.modalResolve?.({ pointIds })
}
</script>

<template>
    <div class="flex flex-col gap-4 min-h-0">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="bg-tag3/40 rounded-md p-3">
                <div class="text-mini text-darkgrey">Výkony</div>
                <div class="text-normal font-semibold">{{ selectedCandidates.length }}</div>
            </div>
            <div class="bg-tag3/40 rounded-md p-3">
                <div class="text-mini text-darkgrey">Pacienti</div>
                <div class="text-normal font-semibold">{{ selectedPatientsCount }}</div>
            </div>
            <div class="bg-tag3/40 rounded-md p-3">
                <div class="text-mini text-darkgrey">Celková suma</div>
                <div class="text-normal font-semibold">{{ formatAmount(selectedAmount) }} €</div>
            </div>
        </div>

        <div class="h-[55vh] min-h-[24rem]">
            <UniversalDataTable
                :options="tableOptions"
                @row-selected="onRowsSelected"
            />
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <Button
                label="Zrušiť"
                text
                class="text-accent! px-3!"
                @click="close"
            />
            <Button
                label="Vytvoriť dávku"
                :disabled="selectedPointIds.length === 0"
                class="bg-accent! border-0! hover:bg-darkgrey! px-4! text-white!"
                @click="createBatch"
            />
        </div>
    </div>
</template>
