<script setup lang="ts">
import {
    computed,
    defineComponent,
    h,
    markRaw,
    ref,
    type PropType,
    watch,
} from 'vue'

import Select from 'primevue/select'
import Tag from 'primevue/tag'

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

import router from '@/router'

import type {
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

const props = defineProps<Props>()

const PatientStatusBadges = defineComponent({
    inheritAttrs: false,
    props: {
        row: {
            type: Object as PropType<Patient>,
            required: true,
        },
        value: {
            type: String as PropType<string | null>,
            default: null,
        },
    },
    setup(props) {
        return () => h('div', { class: 'flex flex-wrap gap-1' }, [
            props.row.death_date
                ? h(Tag, {
                    value: 'Exitus',
                    class: 'bg-danger!',
                })
                : null,
            (props.row.open_patient_audit_issues_count ?? 0) > 0
                ? h(Tag, {
                    value: 'Problém',
                    severity: 'warn',
                    title: `Nevyriešené problémy: ${props.row.open_patient_audit_issues_count}`,
                })
                : null,
        ])
    },
})

const endpointUrl = computed(
    () => props.endpointUrl,
)

const patientStore =
    usePatientStore()

const authStore =
    useAuthStore()

const { openModal } =
    useModal()

const actionRemote =
    ref<RemoteTableReturn>(
        {} as RemoteTableReturn,
    )

const selectedNurseId =
    ref<number | null>(null)

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

const tableKey =
    computed(
        () =>
            `patients-${branchId.value ?? 'none'}`,
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
                },

                {
                    field:
                        'last_name',

                    header:
                        'Priezvisko',

                    sortable:
                        true,
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
                        'death_date',

                    header:
                        '',

                    component:
                        markRaw(PatientStatusBadges),
                },

            ]

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

                actions: [],
            }

        /* ------------------------------------------------------------------ */
        /* Patient actions                                                    */
        /* ------------------------------------------------------------------ */

        {
            const patientActions:
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
                patientActions.splice(
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
                ...patientActions,
            )
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
                                'pin',
                            ].includes(
                                column.field ??
                                '',
                            ),
                    )

            const actionsIndex =
                tableOptions
                    .columns
                    .findIndex(
                        (column) =>
                            column.field ===
                            'documents',
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
                actionsIndex,
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
                    v-if="authStore.isManager"
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
