<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import UniversalDataTable from '@/components/UniversalDataTable.vue';
import ActionButtons from '@/components/table-columns/ActionButtons.vue';
import useEmailDocumentsDialog from '@/composables/useEmailDocumentsDialog';
import type { DataTableOptions, RemoteTableReturn } from '@/types/datatable'


const props = defineProps<{ patientId: number }>();

type PatientDocument = {
    id: number;
    name: string;
    type?: string;
    mime_type?: string;
    path?: string;
    url?: string;
    created_at?: string;
};

const loading = ref(false);
const documents = ref<PatientDocument[]>([]);
const error = ref<string | null>(null);
const documentRemote = ref<RemoteTableReturn>({} as RemoteTableReturn);
const { openEmailDocumentsDialog } = useEmailDocumentsDialog();

const loadDocuments = async () => {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get(`v1/patients/${props.patientId}/documents`);
        documents.value = data?.data ?? data ?? [];
    } catch {
        error.value = 'Nepodarilo sa načítať dokumenty.';
    } finally {
        loading.value = false;
    }
};

const openDocument = (doc: PatientDocument) => {
    if (!doc.id) {
        console.error('Document ID is missing');
        return;
    }

    if (doc.type === 'proposal') {
        window.open(`/documents/proposal/${doc.id}`, '_blank');
    } else if (doc.type === 'agreement') {
        window.open(`/documents/agreement/${doc.id}`, '_blank');
    } else if (doc.type === 'dekurz') {
        window.open(`/documents/dekurz/${doc.id}`, '_blank');
    } else if (doc.type === 'leave') {
        window.open(`/documents/leave/${doc.id}`, '_blank');
    } else if (doc.type === 'record') {
        window.open(`/documents/record/${doc.id}`, '_blank');
    } else if (doc.type === 'scan') {
        window.open(`/documents/scan/${doc.id}`, '_blank');
    } else {
        const target = doc.url || doc.path;
        if (target) window.open(target, '_blank');
    }
};

const formatDocumentType = (type?: string) => {
    const typeMap: Record<string, string> = {
        'proposal': 'Návrh',
        'agreement': 'Dohoda',
        'dekurz': 'Dekurz',
        'leave': 'Prepúšťacia správa',
        'record': 'Ošetrovateľský záznam',
        'scan': 'Lekársky nález',
        'other': 'Iné'
    };
    return typeMap[type || ''] || type || '';
};

const formatDateWithTime = (dateStr?: string) => {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const datePart = date.toLocaleDateString('sk-SK');
    const timePart = date.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    return `${datePart} ${timePart}`;
};

const options = computed<DataTableOptions<PatientDocument>>(() => ({
    rowKey: 'id',
    endpointUrl: `v1/patients/${props.patientId}/documents`,
    defaultPageSize: 25,
    pageSizeOptions: [10, 25, 50],
    selectable: true,

    afterInit: ({ remote }) => {
        documentRemote.value = remote
        remote.setSort?.('-created_at')
        remote.loadPage?.(1)
    },

    columns: [
        {
            field: 'type',
            header: 'Typ',
            sortable: true,
            render: (v: string | undefined) => formatDocumentType(v)
        },
        {
            field: 'period',
            header: 'Obdobie',
            sortable: true,
            render: (v: string | undefined) => v || ''
        },
        {
            field: 'updated_at',
            header: 'Naposledy upravené',
            sortable: true,
            render: (v: string | undefined) => formatDateWithTime(v)
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
                    action: (row: PatientDocument) => {
                        openDocument(row);
                    },
                },
            ],
        },
    ],

    actions: [
        {
            key: 'email',
            disabled: ({ selectedRows }: { selectedRows: PatientDocument[] }) => selectedRows.length === 0,
            icon: 'bi bi-send',
            class: 'bg-accent!',
            tooltip: 'Poslať vybrané dokumenty emailom',
            handler: async ({ selectedRows, remote }: { selectedRows: PatientDocument[]; remote: any }) => {
                await openEmailDocumentsDialog({
                    documents: selectedRows,
                    remote,
                });
            },
        },
        {
            key: 'delete',
            disabled: ({ selectedRows }: { selectedRows: PatientDocument[] }) => selectedRows.length === 0,
            icon: 'bi bi-eraser',
            class: 'bg-danger!',
            confirm: 'Vymazať vybrané dokumenty?',
            handler: async ({ selectedRows, remote }: { selectedRows: PatientDocument[]; remote: any }) => {
                try {
                    await api.delete('v1/documents', {
                        data: {
                            ids: selectedRows.map((r) => r.id),
                        },
                    });
                    await remote.loadPage(remote.page);
                } catch (err) {
                    console.error('Error deleting documents:', err);
                }
            },
        },
    ],
}));

onMounted(loadDocuments);
</script>

<template>
    <div class="p-3">
        <UniversalDataTable :options="options">
            <template #actions="{ row }">
                <button @click.stop="openDocument(row)" class="btn btn-sm btn-link p-0" title="View document">
                    <i class="bi bi-eye"></i>
                </button>
            </template>
        </UniversalDataTable>
    </div>
</template>

<style scoped>
:deep(tbody tr) {
    cursor: pointer;
}
</style>
