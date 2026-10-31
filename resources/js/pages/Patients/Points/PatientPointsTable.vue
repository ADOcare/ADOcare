<script setup lang="ts">
import {
    computed,
    markRaw,
    ref,
    watch,
} from 'vue'

import UniversalDataTable from '@/components/UniversalDataTable.vue'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'

import { usePatientStore } from '@/stores/patientStore'
import api from '@/services/api'
import useModal from '@/composables/useModal'

import PatientPointForm from './PatientPointForm.vue'
import PatientPointBulkEditForm from './PatientPointBulkEditForm.vue'
import { canBatchEdit, type PatientPointApi } from './patientPointBatch'

import type {
    DataTableOptions,
    RemoteTableReturn,
} from '@/types/datatable'

const patientStore = usePatientStore()
patientStore.loadFromStorage()

const pointRemote = ref<RemoteTableReturn>(
    {} as RemoteTableReturn,
)

const selectedPeriod =
    ref<Date | null>(null)

const pointsEndpointUrl = computed(() =>
    patientStore.current?.id
        ? 'v1/patient-points'
        : '',
)

const { openModal } = useModal()

const options = computed<DataTableOptions<PatientPointApi>>(() => ({
    rowKey: 'id',

    endpointUrl: pointsEndpointUrl.value,

    defaultPageSize: 25,

    pageSizeOptions: [
        10,
        25,
        50,
    ],

    selectable: true,

    extraParams: {
        filter: {
            patient_id:
                patientStore.current?.id ?? 0,
        },
    },

    dateRangeFilter: {
        mode: 'single',
        param: 'period',
        placeholder: 'Obdobie',
        view: 'month',
        dateFormat: 'mm/yy',
        value: selectedPeriod.value,
    },

    afterInit: ({ remote }) => {
        pointRemote.value = remote

        remote.setSort?.('-date')
        remote.loadPage?.(1)
    },

    columns: [
        {
            field: 'date',
            header: 'Dátum',
            sortable: true,
            searchable: true,
            render: (value: string | null) =>
                value
                    ? new Date(value)
                        .toLocaleDateString('sk-SK')
                    : '',
        },

        {
            field: 'diagnosis_code',
            header: 'Diagnóza',
            sortable: true,
            searchable: true,
            render: (value: string | null) =>
                value ?? '',
        },

        {
            field: 'procedure_code',
            header: 'Výkon',
            sortable: true,
            searchable: true,
            render: (value: string | null) =>
                value ?? '',
        },

        {
            field: 'quantity',
            header: 'Počet',
            sortable: true,
            render: (value: number | null) =>
                value ?? '',
        },

        {
            field: 'reference_date',
            header: 'Dátum odporučenia',
            sortable: true,
            searchable: true,
            render: (value: string | null) =>
                value
                    ? new Date(value)
                        .toLocaleDateString('sk-SK')
                    : '',
        },

        {
            field: 'edit',
            header: '',
            width: '3rem',
            component: markRaw(ActionButtons),

            componentOptions: [
                {
                    icon: 'bi bi-pencil',
                    color: 'info',
                    tooltip: 'Upraviť záznam',
                    action: (
                        row: PatientPointApi,
                    ) => editRecordFromApiRow(row),
                },
            ],
        },
    ],

    actions: [
        {
            key: 'bulk-edit',
            variant: 'accent',
            label: ({ selectedRows }) => `Upraviť vybrané (${selectedRows.length})`,
            icon: 'bi bi-pencil',
            disabled: ({ selectedRows }) => !canBatchEdit(selectedRows),
            handler: async ({ selectedRows, remote }) => {
                if (!canBatchEdit(selectedRows)) return
                const result = await openModal(markRaw(PatientPointBulkEditForm), {
                    rows: selectedRows,
                }, {
                    header: `Upraviť záznamy`,
                    style: { width: '600px', maxWidth: 'calc(100vw - 2rem)' },
                })
                if (result) await remote.reload()
            },
        },
        {
            key: 'delete',
            label: `Vymazať`,
            icon: 'bi bi-eraser',
            variant: 'danger',

            disabled: ({ selectedRows }) =>
                selectedRows.length === 0,

            confirm:
                'Naozaj si prajete vymazať vybrané záznamy?',

            handler: async ({
                selectedRows,
                remote,
            }) => {
                await api.delete(
                    'v1/patient-points',
                    {
                        data: {
                            ids: selectedRows.map(
                                (row) => row.id,
                            ),
                        },
                    },
                )

                await remote.loadPage(
                    remote.page.value,
                )
            },
        },
    ],
}))

function apiRowToRecordEntry(
    row: PatientPointApi,
) {
    return {
        id: row.id,

        date: row.date,

        diagnosis: {
            id: row.diagnosis_id ?? 0,
            code: row.diagnosis_code ?? '',
            description: '',
        },

        procedure: {
            id: row.procedure_id ?? 0,
            code: row.procedure_code ?? '',
            description: '',
        },

        referralDate: row.reference_date,

        quantity: row.quantity,
    }
}

async function editRecordFromApiRow(
    row: PatientPointApi,
) {
    const point = apiRowToRecordEntry(row)

    const result = await openModal(
        markRaw(PatientPointForm),
        {
            point,
        },
        {
            header: 'Upraviť záznam',
            style: {
                width: '600px',
                maxWidth: 'calc(100vw - 2rem)',
            },
        },
    )

    if (result) {
        pointRemote.value?.reload?.()
    }
}

function reload() {
    pointRemote.value?.reload?.()
}

defineExpose({
    reload,
})

watch(
    () => patientStore.current?.id,
    () => {
        pointRemote.value?.reload?.()
    },
    {
        immediate: true,
    },
)
</script>

<template>
    <UniversalDataTable :options="options" />
</template>