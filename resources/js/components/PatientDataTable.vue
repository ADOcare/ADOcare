<script setup lang="ts">
import {
    computed,
    markRaw,
    ref,
    watch,
} from 'vue'

import Select from 'primevue/select'

import UniversalDataTable from '@/components/UniversalDataTable.vue'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'

import DeleteConfirmationForm from '@/pages/Patients/partials/form/DeleteConfirmationForm.vue'
import CreatePatientForm from '@/pages/Patients/partials/form/CreatePatientForm.vue'

import { useAuthStore } from '@/stores/auth'
import { usePatientStore } from '@/stores/patientStore'

import useModal from '@/composables/useModal'

import {
    openPatientDocumentsModal,
    openPatientEditModal,
} from '@/helpers/modalHelpers'

import {
    formatBranchFullName,
    formatUserFullName,
} from '@/utils/formatUtils'

import api from '@/services/api'
import router from '@/router'

import { useToast } from 'primevue/usetoast'

import type {
    Doctor,
    Patient,
    User,
} from '@/types/models'

import type {
    DataTableOptions,
    RemoteTableReturn,
} from '@/types/datatable'

interface Props {
    endpointUrl: string
}

interface PatientsPrintPayload {
    mode: 'selected' | 'filtered'
    selectedPatients?: Patient[]
    endpointUrl?: string
    params?: Record<string, any>
}

type PatientViewMode =
    | 'active'
    | 'deleted'
    | 'dead'

const props = defineProps<Props>()

const endpointUrl = computed(
    () => props.endpointUrl,
)

const patientStore =
    usePatientStore()

const authStore =
    useAuthStore()

const { openModal } =
    useModal()

const toast =
    useToast()

const actionRemote =
    ref<RemoteTableReturn>(
        {} as RemoteTableReturn,
    )

const selectedNurseId =
    ref<number | null>(null)

const viewMode =
    ref<PatientViewMode>('active')

const canDeletePatients =
    computed(
        () => !!authStore.currentRole,
    )

const branchId =
    computed(
        () =>
            authStore.currentBranch?.id ??
            null,
    )

const showDeleted =
    computed(
        () =>
            viewMode.value ===
            'deleted',
    )

const showDead =
    computed(
        () =>
            viewMode.value ===
            'dead',
    )

const tableKey =
    computed(
        () =>
            `patients-${branchId.value ?? 'none'}-${viewMode.value}`,
    )

/* -------------------------------------------------------------------------- */
/* Nurses                                                                     */
/* -------------------------------------------------------------------------- */

const availableNurses =
    computed(() => {
        if (!authStore.isManager) {
            return []
        }

        const remoteItems =
            actionRemote.value
                ?.items as any

        const items =
            Array.isArray(
                remoteItems,
            )
                ? remoteItems
                : remoteItems?.value

        if (
            !items ||
            !Array.isArray(items) ||
            items.length === 0
        ) {
            return []
        }

        const uniqueNurses =
            new Map<
                number,
                User & {
                    display_name?: string
                }
            >()

        items.forEach(
            (patient: any) => {
                if (
                    !patient?.nurse?.id
                ) {
                    return
                }

                uniqueNurses.set(
                    patient.nurse.id,
                    {
                        ...patient.nurse,

                        display_name:
                            formatUserFullName(
                                patient.nurse,
                            ),
                    },
                )
            },
        )

        return Array.from(
            uniqueNurses.values(),
        ).sort(
            (a, b) =>
                (
                    a.display_name ?? ''
                ).localeCompare(
                    b.display_name ?? '',
                ),
        )
    })

function getNurseName(
    nurseId: number,
) {
    const nurse =
        availableNurses.value.find(
            (item) =>
                item.id === nurseId,
        )

    return nurse
        ? formatUserFullName(nurse)
        : ''
}

watch(
    selectedNurseId,

    async (nurseId) => {
        if (
            !authStore.isManager ||
            !actionRemote.value
        ) {
            return
        }

        actionRemote.value
            .setExtraParam?.(
                'filter[nurse_id]',
                nurseId ?? undefined,
            )

        await actionRemote.value
            .loadPage?.(1)
    },
)

watch(
    viewMode,
    () => {
        selectedNurseId.value =
            null
    },
)

/* -------------------------------------------------------------------------- */
/* Patient print                                                              */
/* -------------------------------------------------------------------------- */

function openPatientsPrintPreview(
    payload: PatientsPrintPayload,
) {
    sessionStorage.setItem(
        'patients-print-payload',
        JSON.stringify(payload),
    )

    void router.push({
        name:
            'patients-print',
    })
}

function printSelectedPatients(
    patients: Patient[],
) {
    if (!patients.length) {
        return
    }

    openPatientsPrintPreview({
        mode: 'selected',
        selectedPatients:
            patients,
    })
}

/* -------------------------------------------------------------------------- */
/* Patient actions                                                            */
/* -------------------------------------------------------------------------- */

function openPatientDocuments(
    patientId: number,
) {
    void openPatientDocumentsModal(
        patientId,
    )
}

async function openEditPatient(
    patientId: number,
) {
    await openPatientEditModal(
        patientId,
    )

    actionRemote.value
        ?.reload?.()
}

async function deletePatients(
    rows: Patient[],
    remote?: RemoteTableReturn,
) {
    if (!rows.length) {
        return
    }

    await openModal(
        markRaw(
            DeleteConfirmationForm,
        ),
        {
            title:
                'Vymazať pacientov',

            selectedRows:
                rows,

            remote,
        },
        {
            style: {
                width: '60%',
                maxWidth:
                    'calc(100vw - 2rem)',
            },
        },
    )
}

async function restorePatients(
    rows: Patient[],
    remote?: RemoteTableReturn,
) {
    const ids =
        rows
            .filter(
                (row) =>
                    row.deleted_at,
            )
            .map(
                (row) =>
                    row.id,
            )

    if (!ids.length) {
        return
    }

    try {
        await api.post(
            '/v1/patients/restore',
            {
                ids,
            },
        )

        toast.add({
            severity:
                'success',

            summary:
                'Pacienti boli obnovení',

            detail:
                'Vybraní pacienti boli úspešne obnovení.',

            life: 4000,
        })

        remote?.reload?.()
    } catch (error: any) {
        const personalNumberError =
            error
                ?.response
                ?.data
                ?.errors
                ?.personal_number
                ?.[0]

        toast.add({
            severity: 'error',

            summary:
                personalNumberError
                    ? 'Pacienta nemožno obnoviť'
                    : 'Obnovenie sa nepodarilo',

            detail:
                personalNumberError ??
                error
                    ?.response
                    ?.data
                    ?.message ??
                'Vybraných pacientov sa nepodarilo obnoviť.',

            life:
                personalNumberError
                    ? 10000
                    : 6000,
        })
    }
}

/* -------------------------------------------------------------------------- */
/* Table                                                                      */
/* -------------------------------------------------------------------------- */

const options =
    computed<
        DataTableOptions<Patient>
    >(() => {
        const baseColumns:
            DataTableOptions<Patient>['columns'] =
            [
                {
                    field:
                        'first_name',

                    header:
                        'Meno',

                    sortable:
                        true,

                    render:
                        (
                            value,
                            row,
                        ) => {
                            if (!row) {
                                return value
                            }

                            if (
                                row.deleted_at
                            ) {
                                return `<s>${value}</s>`
                            }

                            if (
                                row.death_date
                            ) {
                                return `${value} †`
                            }

                            return value
                        },
                },

                {
                    field:
                        'last_name',

                    header:
                        'Priezvisko',

                    sortable:
                        true,

                    render:
                        (
                            value,
                            row,
                        ) => {
                            if (!row) {
                                return value
                            }

                            if (
                                row.deleted_at
                            ) {
                                return `<s>${value}</s>`
                            }

                            return value
                        },
                },

                {
                    field:
                        'personal_number',

                    header:
                        'Rodné číslo',

                    sortable:
                        true,

                    render:
                        (value) =>
                            value,
                },

                {
                    field:
                        'doctor',

                    header:
                        'Ošetrujúci lekár',

                    sortable:
                        false,

                    render:
                        (
                            value:
                                Doctor,
                        ) =>
                            value
                                ? `${value.title ?? ''} ${value.first_name ?? ''} ${value.last_name ?? ''}`.trim()
                                : '',
                },
            ]

        /* ------------------------------------------------------------------ */
        /* Mode-specific columns                                              */
        /* ------------------------------------------------------------------ */

        if (showDead.value) {
            baseColumns.splice(
                3,
                0,
                {
                    field:
                        'death_date',

                    header:
                        'Dátum úmrtia',

                    sortable:
                        true,

                    render:
                        (value) =>
                            value
                                ? new Date(
                                    value,
                                ).toLocaleDateString(
                                    'sk-SK',
                                )
                                : '',
                },
            )
        }

        if (
            showDeleted.value
        ) {
            baseColumns.splice(
                3,
                0,
                {
                    field:
                        'deleted_at',

                    header:
                        'Dátum zmazania',

                    sortable:
                        true,

                    render:
                        (value) =>
                            value
                                ? new Date(
                                    value,
                                ).toLocaleDateString(
                                    'sk-SK',
                                )
                                : '',
                },
            )
        }

        if (
            !showDeleted.value &&
            !showDead.value
        ) {
            baseColumns.splice(
                3,
                0,

                {
                    field:
                        'address',

                    header:
                        'Adresa',

                    render:
                        (
                            _value,
                            row,
                        ) => {
                            if (!row) {
                                return ''
                            }

                            const address =
                                String(
                                    row.address ??
                                    '',
                                ).trim()

                            return (
                                address.length >
                                50
                                    ? `${address.substring(
                                        0,
                                        40,
                                    )}...`
                                    : address
                            )
                        },
                },

                {
                    field:
                        'city',

                    header:
                        'Mesto',

                    sortable:
                        true,
                },
            )
        }

        /* ------------------------------------------------------------------ */
        /* Row actions                                                        */
        /* ------------------------------------------------------------------ */

        baseColumns.push({
            field:
                'documents',

            header:
                '',

            width:
                '3rem',

            component:
                markRaw(
                    ActionButtons,
                ),

            componentOptions:
                [
                    {
                        icon:
                            'bi bi-folder',

                        color:
                            'darkgrey',

                        tooltip:
                            'Zobraziť dokumenty',

                        action:
                            (
                                row:
                                    Patient,
                            ) => {
                                openPatientDocuments(
                                    row.id,
                                )
                            },
                    },
                ],
        })

        if (
            !showDeleted.value
        ) {
            baseColumns.push({
                field:
                    'pin',

                header:
                    '',

                width:
                    '3rem',

                component:
                    markRaw(
                        ActionButtons,
                    ),

                componentOptions:
                    [
                        {
                            icon:
                                (
                                    row:
                                        Patient,
                                ) =>
                                    patientStore
                                        .current
                                        ?.id ===
                                    row.id
                                        ? 'bi bi-pin-fill'
                                        : 'bi bi-pin',

                            color:
                                'darkgrey',

                            tooltip:
                                'Pripnúť pacienta',

                            action:
                                (
                                    row:
                                        Patient,
                                ) => {
                                    patientStore
                                        .setPatient(
                                            row,
                                        )

                                    void router.push(
                                        '/patient/points',
                                    )
                                },
                        },
                    ],
            })
        }

        if (
            !showDeleted.value &&
            !showDead.value
        ) {
            baseColumns.push({
                field:
                    'edit',

                header:
                    '',

                width:
                    '3rem',

                component:
                    markRaw(
                        ActionButtons,
                    ),

                componentOptions:
                    [
                        {
                            icon:
                                'bi bi-pencil',

                            color:
                                'darkgrey',

                            tooltip:
                                'Upraviť pacienta',

                            action:
                                (
                                    row:
                                        Patient,
                                ) => {
                                    void openEditPatient(
                                        row.id,
                                    )
                                },
                        },
                    ],
            })
        }

        /* ------------------------------------------------------------------ */
        /* Base table options                                                 */
        /* ------------------------------------------------------------------ */

        const tableOptions:
            DataTableOptions<Patient> =
            {
                rowKey:
                    'id',

                endpointUrl:
                    endpointUrl.value ||
                    '',

                defaultPageSize:
                    25,

                pageSizeOptions:
                    [
                        10,
                        25,
                        50,
                    ],

                selectable:
                    true,

                afterInit:
                    ({
                        remote,
                    }) => {
                        actionRemote.value =
                            remote

                        if (
                            authStore
                                .isManager &&
                            selectedNurseId
                                .value !==
                                null
                        ) {
                            remote
                                .setExtraParam?.(
                                    'filter[nurse_id]',
                                    selectedNurseId
                                        .value,
                                )
                        }
                    },

                extraParams: {
                    ...(
                        authStore
                            .isManager
                            ? {
                                with:
                                    'nurse,doctor,branch',
                            }
                            : {
                                with:
                                    'doctor',
                            }
                    ),

                    ...(
                        showDeleted
                            .value
                            ? {
                                only_deleted:
                                    1,
                            }
                            : {}
                    ),

                    ...(
                        showDead
                            .value
                            ? {
                                only_dead:
                                    1,
                            }
                            : {}
                    ),

                    ...(
                        authStore
                            .isManager &&
                        selectedNurseId
                            .value !==
                            null
                            ? {
                                'filter[nurse_id]':
                                    selectedNurseId
                                        .value,
                            }
                            : {}
                    ),
                },

                columns:
                    baseColumns,

                actions: [
                    {
                        key:
                            'show-active',

                        label:
                            'Aktívni',

                        icon:
                            'bi bi-people',

                        variant:
                            viewMode.value ===
                            'active'
                                ? 'dark'
                                : 'secondary',

                        position:
                            'start',

                        tooltip:
                            'Zobraziť aktívnych pacientov',

                        handler:
                            async ({
                                remote,
                            }) => {
                                if (
                                    viewMode.value ===
                                    'active'
                                ) {
                                    return
                                }

                                viewMode.value =
                                    'active'

                                remote.reload()
                            },
                    },

                    {
                        key:
                            'show-deleted',

                        label:
                            'Zmazaní',

                        icon:
                            'bi bi-person-x',

                        variant:
                            viewMode.value ===
                            'deleted'
                                ? 'dark'
                                : 'secondary',

                        position:
                            'start',

                        tooltip:
                            'Zobraziť zmazaných pacientov',

                        handler:
                            async ({
                                remote,
                            }) => {
                                if (
                                    viewMode.value ===
                                    'deleted'
                                ) {
                                    return
                                }

                                viewMode.value =
                                    'deleted'

                                remote.reload()
                            },
                    },

                    {
                        key:
                            'show-dead',

                        label:
                            'Zosnulí',

                        icon:
                            'bi bi-person-exclamation',

                        variant:
                            viewMode.value ===
                            'dead'
                                ? 'dark'
                                : 'secondary',

                        position:
                            'start',

                        tooltip:
                            'Zobraziť zosnulých pacientov',

                        handler:
                            async ({
                                remote,
                            }) => {
                                if (
                                    viewMode.value ===
                                    'dead'
                                ) {
                                    return
                                }

                                viewMode.value =
                                    'dead'

                                remote.reload()
                            },
                    },
                ],
            }

        /* ------------------------------------------------------------------ */
        /* Active patient actions                                             */
        /* ------------------------------------------------------------------ */

        if (
            !showDeleted.value &&
            !showDead.value
        ) {
            const activeActions:
                NonNullable<
                    typeof tableOptions.actions
                > = [
                    {
                        key:
                            'add',

                        label:
                            'Pridať pacienta',

                        icon:
                            'bi bi-plus-lg',

                        variant:
                            'accent',

                        handler:
                            async ({
                                remote,
                            }) => {
                                const result =
                                    await openModal(
                                        markRaw(
                                            CreatePatientForm,
                                        ),
                                        {},
                                        {
                                            header:
                                                'Pridať pacienta',

                                            style: {
                                                width:
                                                    '90%',

                                                maxWidth:
                                                    'calc(100vw - 2rem)',
                                            },
                                        },
                                    )

                                if (
                                    result
                                ) {
                                    remote.reload()
                                }
                            },
                    },

                    {
                        key:
                            'print',

                        label:
                            'Tlačiť',

                        icon:
                            'bi bi-printer',

                        variant:
                            'accent',

                        disabled:
                            ({
                                selectedRows,
                            }) =>
                                !selectedRows.length,

                        handler:
                            ({
                                selectedRows,
                            }) => {
                                printSelectedPatients(
                                    selectedRows as Patient[],
                                )
                            },
                    },
                ]

            if (
                canDeletePatients.value
            ) {
                activeActions.splice(
                    1,
                    0,
                    {
                        key:
                            'delete',

                        label:
                            'Vymazať',

                        icon:
                            'bi bi-eraser',

                        variant:
                            'danger',

                        disabled:
                            ({
                                selectedRows,
                            }) =>
                                !selectedRows.length,

                        handler:
                            async ({
                                selectedRows,
                                remote,
                            }) => {
                                await deletePatients(
                                    selectedRows as Patient[],
                                    remote,
                                )
                            },
                    },
                )
            }

            tableOptions.actions
                ?.push(
                    ...activeActions,
                )
        }

        /* ------------------------------------------------------------------ */
        /* Deleted patient actions                                            */
        /* ------------------------------------------------------------------ */

        if (
            showDeleted.value
        ) {
            tableOptions.actions
                ?.push({
                    key:
                        'restore',

                    label:
                        'Obnoviť',

                    icon:
                        'bi bi-arrow-counterclockwise',

                    variant:
                        'accent',

                    disabled:
                        ({
                            selectedRows,
                        }) => {
                            const rows =
                                (
                                    selectedRows ??
                                    []
                                ) as Patient[]

                            return (
                                !rows.length ||
                                rows.some(
                                    (row) =>
                                        !row.deleted_at,
                                )
                            )
                        },

                    handler:
                        async ({
                            selectedRows,
                            remote,
                        }) => {
                            await restorePatients(
                                selectedRows as Patient[],
                                remote,
                            )
                        },
                })
        }

        /* ------------------------------------------------------------------ */
        /* Manager-specific table                                             */
        /* ------------------------------------------------------------------ */

        if (
            authStore.isManager
        ) {
            tableOptions.actions =
                tableOptions.actions
                    ?.filter(
                        (action) =>
                            action.key !==
                            'add',
                    )

            tableOptions.columns =
                tableOptions.columns
                    ?.filter(
                        (column) =>
                            ![
                                'personal_number',
                                'address',
                                'city',
                                'pin',
                            ].includes(
                                column.field ??
                                '',
                            ),
                    )

            const doctorIndex =
                tableOptions
                    .columns
                    .findIndex(
                        (column) =>
                            column.field ===
                            'doctor',
                    )

            const managerColumns =
                [
                    {
                        field:
                            'nurse',

                        header:
                            'Sestra',

                        render:
                            (
                                _value:
                                    any,
                                row:
                                    Patient,
                            ) =>
                                row.nurse
                                    ? formatUserFullName(
                                        row.nurse,
                                    )
                                    : '',

                        sortable:
                            false,
                    },

                    {
                        field:
                            'branch',

                        header:
                            'Prevádzka',

                        render:
                            (
                                _value:
                                    any,
                                row:
                                    Patient,
                            ) =>
                                formatBranchFullName(
                                    row.branch,
                                ),

                        sortable:
                            false,
                    },
                ]

            tableOptions.columns.splice(
                doctorIndex + 1,
                0,
                ...managerColumns,
            )
        }

        return tableOptions
    })
</script>

<template>
    <div
        class="
            h-full
            flex
            flex-col
            min-h-0
            max-h-full
            overflow-auto
        "
    >
        <UniversalDataTable
            v-if="endpointUrl"
            ref="tableEl"
            :key="tableKey"
            :options="options"
        >
            <template #toolbar-extra>
                <div
                    v-if="
                        authStore.isManager &&
                        !showDeleted &&
                        !showDead
                    "
                    class="flex items-center gap-2"
                >
                    <Select
                        v-model="selectedNurseId"
                        :options="availableNurses"
                        option-value="id"
                        option-label="display_name"
                        :placeholder="
                            availableNurses.length === 0
                                ? 'Načítavam sestry...'
                                : 'Všetky sestry'
                        "
                        :disabled="
                            availableNurses.length === 0
                        "
                        class="w-64"
                        dropdown-icon="bi bi-chevron-down"
                        :show-clear="true"
                        clear-icon="bi bi-x-lg"
                    >
                        <template #option="{ option }">
                            <span v-if="option">
                                {{
                                    formatUserFullName(
                                        option,
                                    )
                                }}
                            </span>
                        </template>

                        <template #value="{ value }">
                            <span
                                v-if="
                                    value !== null &&
                                    value !== undefined
                                "
                            >
                                {{
                                    getNurseName(
                                        value,
                                    )
                                }}
                            </span>

                            <span
                                v-else
                                class="text-gray-400"
                            >
                                Všetky sestry
                            </span>
                        </template>
                    </Select>
                </div>
            </template>
        </UniversalDataTable>
    </div>
</template>
