<script setup lang="ts">
import {
    computed,
    markRaw,
    ref,
} from 'vue'

import UniversalDataTable from '@/components/UniversalDataTable.vue'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'

import useEmailDocumentsDialog from '@/composables/useEmailDocumentsDialog'

import api from '@/services/api'

import type {
    DataTableOptions,
    RemoteTableReturn,
} from '@/types/datatable'

const props = defineProps<{
    patientId: number
}>()

type PatientDocument = {
    id: number
    name: string
    type?: string
    mime_type?: string
    path?: string
    url?: string
    period?: string
    created_at?: string
    updated_at?: string
}

const documentRemote = ref<RemoteTableReturn>(
    {} as RemoteTableReturn,
)

const {
    openEmailDocumentsDialog,
} = useEmailDocumentsDialog()

/* -------------------------------------------------------------------------- */
/* Document helpers                                                           */
/* -------------------------------------------------------------------------- */

function getDocumentUrl(
    document: PatientDocument,
): string | null {
    if (!document.id) {
        return null
    }

    const routes: Record<string, string> = {
        proposal: `/documents/proposal/${document.id}`,
        agreement: `/documents/agreement/${document.id}`,
        dekurz: `/documents/dekurz/${document.id}`,
        leave: `/documents/leave/${document.id}`,
        record: `/documents/record/${document.id}`,
        scan: `/documents/scan/${document.id}`,
    }

    if (
        document.type &&
        routes[document.type]
    ) {
        return routes[document.type]
    }

    return (
        document.url ??
        document.path ??
        null
    )
}

function openDocument(
    document: PatientDocument,
) {
    const target =
        getDocumentUrl(document)

    if (!target) {
        console.error(
            'Document URL could not be resolved.',
            document,
        )

        return
    }

    window.open(
        target,
        '_blank',
        'noopener,noreferrer',
    )
}

function formatDocumentType(
    type?: string,
) {
    const typeMap: Record<string, string> = {
        proposal: 'Návrh',
        agreement: 'Dohoda',
        dekurz: 'Dekurz',
        leave: 'Prepúšťacia správa',
        record: 'Ošetrovateľský záznam',
        scan: 'Lekársky nález',
        other: 'Iné',
    }

    return (
        typeMap[type ?? ''] ??
        type ??
        ''
    )
}

function formatDateWithTime(
    value?: string,
) {
    if (!value) {
        return ''
    }

    const date = new Date(value)

    if (
        Number.isNaN(
            date.getTime(),
        )
    ) {
        return ''
    }

    const datePart =
        date.toLocaleDateString(
            'sk-SK',
        )

    const timePart =
        date.toLocaleTimeString(
            'sk-SK',
            {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
            },
        )

    return `${datePart} ${timePart}`
}

/* -------------------------------------------------------------------------- */
/* Actions                                                                    */
/* -------------------------------------------------------------------------- */

async function emailDocuments(
    selectedRows: PatientDocument[],
    remote: RemoteTableReturn,
) {
    if (!selectedRows.length) {
        return
    }

    await openEmailDocumentsDialog({
        documents:
            selectedRows,

        remote,
    })
}

async function deleteDocuments(
    selectedRows: PatientDocument[],
    remote: RemoteTableReturn,
) {
    if (!selectedRows.length) {
        return
    }

    await api.delete(
        'v1/documents',
        {
            data: {
                ids: selectedRows.map(
                    (document) =>
                        document.id,
                ),
            },
        },
    )

    await remote.loadPage?.(
        remote.page.value,
    )
}

/* -------------------------------------------------------------------------- */
/* Table                                                                      */
/* -------------------------------------------------------------------------- */

const options =
    computed<
        DataTableOptions<PatientDocument>
    >(() => ({
        rowKey: 'id',

        endpointUrl:
            `v1/patients/${props.patientId}/documents`,

        defaultPageSize: 25,

        pageSizeOptions: [
            10,
            25,
            50,
        ],

        selectable: true,

        afterInit: ({
            remote,
        }) => {
            documentRemote.value =
                remote

            remote.setSort?.(
                '-created_at',
            )
        },

        columns: [
            {
                field: 'type',

                header: 'Typ',

                sortable: true,

                render: (
                    value:
                        string |
                        undefined,
                ) =>
                    formatDocumentType(
                        value,
                    ),
            },

            {
                field: 'period',

                header: 'Obdobie',

                sortable: true,

                render: (
                    value:
                        string |
                        undefined,
                ) =>
                    value ?? '',
            },

            {
                field: 'updated_at',

                header:
                    'Naposledy upravené',

                sortable: true,

                render: (
                    value:
                        string |
                        undefined,
                ) =>
                    formatDateWithTime(
                        value,
                    ),
            },

            {
                field: 'preview',

                header: '',

                width: '3rem',

                component:
                    markRaw(
                        ActionButtons,
                    ),

                componentOptions: [
                    {
                        icon:
                            'bi bi-eye',

                        color:
                            'darkgrey',

                        tooltip:
                            'Zobraziť dokument',

                        action: (
                            row:
                                PatientDocument,
                        ) => {
                            openDocument(row)
                        },
                    },
                ],
            },
        ],

        actions: [
            {
                key: 'email',

                label:
                    'Poslať emailom',

                icon:
                    'bi bi-send',

                variant:
                    'accent',

                tooltip:
                    'Poslať vybrané dokumenty emailom',

                disabled: ({
                    selectedRows,
                }) =>
                    selectedRows.length ===
                    0,

                handler:
                    async ({
                        selectedRows,
                        remote,
                    }) => {
                        await emailDocuments(
                            selectedRows as PatientDocument[],
                            remote,
                        )
                    },
            },

            {
                key: 'delete',

                label:
                    'Vymazať',

                icon:
                    'bi bi-eraser',

                variant:
                    'danger',

                tooltip:
                    'Vymazať vybrané dokumenty',

                disabled: ({
                    selectedRows,
                }) =>
                    selectedRows.length ===
                    0,

                confirm:
                    'Naozaj si prajete vymazať vybrané dokumenty?',

                handler:
                    async ({
                        selectedRows,
                        remote,
                    }) => {
                        await deleteDocuments(
                            selectedRows as PatientDocument[],
                            remote,
                        )
                    },
            },
        ],
    }))
</script>

<template>
    <div class="h-full min-h-0">
        <UniversalDataTable
            :options="options"
        />
    </div>
</template>