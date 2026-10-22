<script setup lang="ts">
import { markRaw, ref } from 'vue'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import MacroForm from './MacroForm.vue'
import type { Macro } from '@/types/models'
import api from '@/services/api'
import type { DataTableOptions } from '@/types/datatable'
import { default as ActionButtons, type ActionButtonOptions } from '@/components/table-columns/ActionButtons.vue'
import useModal from '@/composables/useModal'

const { openModal } = useModal()
const actionRemote = ref<any>(null)

async function openCreate() {
    try {
        await openModal(markRaw(MacroForm), { macro: null }, { header: 'Makro', style: { width: '640px' }, closable: true })
    } finally {
        if (actionRemote.value?.loadPage) await actionRemote.value.loadPage(1)
    }
}

async function openEdit(row: Macro) {
    try {
        await openModal(markRaw(MacroForm), { macro: row }, { header: 'Makro', style: { width: '640px' }, closable: true })
    } finally {
        if (actionRemote.value?.loadPage) await actionRemote.value.loadPage(1)
    }
}

// onSave/onClose handled by modal resolve and openCreate/openEdit's finally block

// typed options for macros table
const options = ref<DataTableOptions<Macro>>({
    rowKey: 'id',
    endpointUrl: 'v1/macros',
    defaultPageSize: 25,
    pageSizeOptions: [10, 25, 50],
    selectable: true,
    afterInit: ({ remote }) => {
        actionRemote.value = remote;
    },
    columns: [
        { field: 'name', header: 'Názov', sortable: true },
        { field: 'abbreviation', header: 'Skratka' },
        { field: 'text', width: '50%', header: 'Text' },
        {
            field: 'edit', header: ' ', width: '3rem', component: markRaw(ActionButtons), componentOptions: [{
                color: 'info',
                icon: 'bi bi-pencil',
                tooltip: 'Upraviť makro',
                action: async (row: Macro) => {
                    openEdit(row);
                }
            }] as ActionButtonOptions[]
        }
    ],
    actions: [
        {
            key: 'delete',
            label: 'Vymazať',
            icon: 'bi bi-eraser',
            variant: 'danger',
            tooltip: 'Vymazať vybrané makrá',
            disabled: ({ selectedRows }) => !selectedRows || selectedRows.length === 0,
            confirm: 'Naozaj vymazať vybrané makrá?',
            handler: async ({ remote, selectedRows }: any) => {
                try {
                    for (const r of selectedRows ?? []) {
                        await api.delete(`/v1/macros/${r.id}`)
                    }
                } catch (err) {
                    console.error('Delete failed', err)
                } finally {
                    await remote.loadPage(1)
                }
            },
        },
        {
            key: 'add',
            label: 'Pridať nové makro',
            tooltip: 'Pridať nové makro',
            icon: 'bi bi-plus-lg',
            variant: 'accent',
            handler: async () => {
                openCreate()
            },
        },
    ],
})
</script>

<template>
    <div class="h-full flex flex-col overflow-hidden min-h-0">
        <UniversalDataTable :options="options" />
    </div>
</template>

<style scoped>
.text-heading {
    font-weight: 600;
    font-size: 1.125rem
}
</style>
