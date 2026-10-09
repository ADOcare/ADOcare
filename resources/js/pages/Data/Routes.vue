<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import TransportCreateForm from '@/components/Transport/TransportCreateForm.vue'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'
import useEmailDocumentsDialog from '@/composables/useEmailDocumentsDialog'
import type { DataTableOptions } from '@/types/datatable'

interface BatchType {
    code: string
    name: string
}

type Document = {
    id: number
    name: string
    period?: string
    type?: string
    mime_type?: string
    path?: string
    created_at?: string
}

const authStore = useAuthStore()
const { openEmailDocumentsDialog } = useEmailDocumentsDialog()


const branchId = computed(() => authStore.currentBranch?.id)
const batchType = ref<BatchType | null>(null)
const now = new Date()
const dates = ref<Date | null>(new Date(now.getFullYear(), now.getMonth() - 1, 1));
const documentIdCp = ref<number | null>(null)
const dialogVisibleCp = ref(false)
const documentIdDzc = ref<number | null>(null)
const dialogVisibleDzc = ref(false)



const getDocumentUrl = (doc: { id: number; type?: string }) => {
    if (!doc.id) return null
    if (doc.type === 'cp') return `/documents/cp/${doc.id}`
    if (doc.type === 'dzc') return `/documents/dzc/${doc.id}`
    return null
}

const openDocumentInNewTab = (doc: Document) => {
    const url = getDocumentUrl(doc)
    if (!url) {
        console.warn('Unknown document type:', doc.type)
        return
    }
    window.open(url, '_blank', 'noopener,noreferrer')
}

const formatDocumentType = (type?: string) => {
    const typeMap: Record<string, string> = {
        cp: 'Cestovný príkaz',
        dzc: 'Denný záznam ciest',
    }
    return typeMap[type || ''] || type || ''
}

const formatDateWithTime = (dateStr?: string) => {
    if (!dateStr) return ''
    const date = new Date(dateStr)
    const datePart = date.toLocaleDateString('sk-SK')
    const timePart = date.toLocaleTimeString('sk-SK', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    })
    return `${datePart} ${timePart}`
}

const formatPeriod = (period?: string) => {
    if (!period) return '-'

    const [year, month] = period.split('-').map(Number)
    if (!year || !month) return period

    const date = new Date(year, month - 1, 1)
    return date.toLocaleDateString('sk-SK', {
        month: '2-digit',
        year: 'numeric',
    })
}

const formatLocalDate = (date: Date) => {
    const y = date.getFullYear()
    const m = String(date.getMonth() + 1).padStart(2, '0')
    const d = String(date.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
}

async function checkDocumentExists() {
    if (!batchType.value || !dates.value) return

    const monthDate = dates.value as Date
    const year = monthDate.getFullYear()
    const month = monthDate.getMonth()
    const startDate = new Date(year, month, 1)

    const startStr = formatLocalDate(startDate)

    try {
        if (batchType.value.code === 'CP') {
            const res = await api.post('/v1/documents/check-exists', {
                type: 'cp',
                date: startStr,
                branch_id: branchId.value
            })
            if (res.data.exists) {
                documentIdCp.value = res.data.document_id ?? null
                dialogVisibleCp.value = true
            }
        } else if (batchType.value.code === 'DZC') {
            const res = await api.post('/v1/documents/check-exists', {
                type: 'dzc',
                date: startStr,
                branch_id: branchId.value
            })
            if (res.data.exists) {
                documentIdDzc.value = res.data.document_id ?? null
                dialogVisibleDzc.value = true
            }
        }
    } catch (err) {
        console.error('Failed to check document existence:', err)
    }
}


const options = computed<DataTableOptions<Document>>(() => ({
    rowKey: 'id',
    endpointUrl: 'v1/documents/travel',
    extraParams: branchId.value
        ? { branch_id: branchId.value }
        : {},
    dateRangeFilter: {
        mode: 'single',
        param: 'period',
        placeholder: 'Obdobie',
        view: 'month',
        dateFormat: 'mm/yy',
        value: dates.value,
    },
    defaultPageSize: 25,
    pageSizeOptions: [10, 25, 50],
    selectable: true,

    columns: [
        {
            field: 'type',
            header: 'Typ',
            sortable: true,
            render: (v: string | undefined) => formatDocumentType(v),
        },
        {
            field: 'period',
            header: 'Obdobie',
            sortable: true,
            render: (v: string | undefined) => formatPeriod(v),
            width: '8rem',
        },
        {
            field: 'updated_at',
            header: 'Naposledy upravené',
            sortable: true,
            render: (v: string | undefined) => formatDateWithTime(v),
        },
        {
            field: 'preview',
            header: '',
            width: '3rem',
            component: ActionButtons,
            componentOptions: [
                {
                    icon: 'bi bi-eye',
                    color: 'info',
                    tooltip: 'Zobraziť dokument',
                    action: (row: Document) => {
                        openDocumentInNewTab(row) // ✅ table -> new tab
                    },
                },
            ],
        },
    ],

    actions: [
        {
            key: 'email',
            disabled: ({ selectedRows }: { selectedRows: Document[] }) => selectedRows.length === 0,
            icon: 'bi bi-send',
            class: 'bg-accent!',
            tooltip: 'Poslať vybrané dokumenty emailom',
            handler: async ({ selectedRows, remote }: { selectedRows: Document[]; remote: any }) => {
                await openEmailDocumentsDialog({
                    documents: selectedRows,
                    remote,
                })
            },
        },
        {
            key: 'delete',
            disabled: ({ selectedRows }: { selectedRows: Document[] }) => selectedRows.length === 0,
            icon: 'bi bi-eraser',
            class: 'bg-danger!',
            confirm: 'Naozaj chcete zmazať vybrané dokumenty?',
            handler: async ({ selectedRows, remote }: { selectedRows: Document[]; remote: any }) => {
                try {
                    await api.delete('v1/documents', {
                        data: {
                            ids: selectedRows.map((r) => r.id),
                        },
                    })
                    await remote.loadPage(remote.page)
                } catch (err) {
                    console.error('Error deleting documents:', err)
                }
            },
        },
    ],
}))


watch([() => batchType.value, () => dates.value], () => {
    checkDocumentExists()
})
</script>

<template>
    <div class="flex flex-col gap-6">
        <TransportCreateForm kind="journal" />
        <section>
            <UniversalDataTable ref="tableRef" :options="options">
                <template #actions="{ row }">
                    <button @click.stop="openDocumentInNewTab(row)" class="btn btn-sm btn-link p-0"
                        title="Otvoriť dokument">
                        <i class="bi bi-eye"></i>
                    </button>
                </template>
            </UniversalDataTable>
        </section>
    </div>
</template>
