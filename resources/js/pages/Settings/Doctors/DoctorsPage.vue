<script setup lang="ts">
import { markRaw, ref } from 'vue'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import type { Doctor } from '@/types/models'
import DoctorForm from './DoctorForm.vue'
import api from '@/services/api'
import useAuthStore from '@/stores/auth'
import { default as ActionButtons, type ActionButtonOptions } from '@/components/table-columns/ActionButtons.vue'
import type { DataTableOptions } from '@/types/datatable'
import { formatBranchFullName } from '@/utils/formatUtils'
import useModal from '@/composables/useModal'

const showFavouritesOnly = ref(false);
const actionRemote = ref<any>(null)
const { openModal } = useModal()

async function openCreate() {
    let response: any = null
    try {
        response = await openModal(markRaw(DoctorForm), { doctor: null }, { header: 'Lekár', style: { width: '640px' }, closable: true })
    } finally {
        if (response) await actionRemote.value?.loadPage(1)
    }
}

async function openEdit(row: Doctor) {
    let response: any = null
    try {
        response = await openModal(markRaw(DoctorForm), { doctor: row }, { header: 'Lekár', style: { width: '640px' }, closable: true })
    } finally {
        if (response) await actionRemote.value?.loadPage(1)
    }
}

// shortcut references we need repeatedly below
const auth = useAuthStore()
const branchId = auth.currentBranch?.id

const options = ref<DataTableOptions<Doctor>>({
    rowKey: 'id',
    // default to the global doctors list; managers/superadmins override below
    endpointUrl: `v1/doctors`,
    extraParams: {
        ...(branchId ? { mark_favourites_for_branch_id: branchId } : {}),
    },
    defaultPageSize: 50,
    pageSizeOptions: [25, 50, 100],
    selectable: false,
    afterInit: ({ remote }) => {
        actionRemote.value = remote
    },
    columns: [
        { field: 'first_name', header: 'Meno', sortable: true },
        { field: 'last_name', header: 'Priezvisko', sortable: true },
        { field: 'title', header: 'Titul' },
        { field: 'zpr', header: 'ZPR' },
        { field: 'pzs', header: 'PZS' },
        {
            field: 'is_favourite',
            // header: 'Obľúbený',
            width: '4rem',
            component: markRaw(ActionButtons),
            componentOptions: [
                {
                    type: 'toggle-icon',
                    icon: (row: Doctor & { is_favourite: boolean }) => row.is_favourite ? 'bi bi-heart-fill' : 'bi bi-heart',
                    tooltip: 'Označiť/odznačiť ako obľúbeného',
                    async action(row: Doctor & { is_favourite: boolean }) {
                        const doctorId = row.id;

                        const branchId = useAuthStore().currentBranch?.id
                        if (!branchId) return

                        const isFavourite = !row.is_favourite;

                        const url = `/v1/branches/${branchId}/favourite-doctors/${doctorId}`
                        if (isFavourite) {
                            // add to favourites
                            await api.post(url)
                        } else {
                            // remove from favourites
                            await api.delete(url)
                        }

                        // update local state
                        row.is_favourite = isFavourite


                    },
                },
            ],

        },
    ],
    actions: [
        //     {
        //         key: 'edit',
        //         label: '',
        //         icon: 'bi bi-pencil',
        //         disabled: ({ selectedRows }) => !selectedRows || selectedRows.length !== 1,
        //         handler: async (ctx: any) => openEdit(ctx),
        //     },
        //     {
        //         key: 'delete',
        //         label: '',
        //         icon: 'bi bi-eraser',
        //         disabled: ({ selectedRows }) => !selectedRows || selectedRows.length === 0,
        //         confirm: 'Naozaj vymazať vybraných lekárov?',
        //         handler: async ({ remote, selectedRows }: any) => {
        //             try {
        //                 for (const r of selectedRows ?? []) {
        //                     await api.delete(`/v1/doctors/${r.id}`)
        //                 }
        //             } catch (err) {
        //                 console.error('Delete failed', err)
        //             } finally {
        //                 await remote.loadPage(1)
        //             }
        //         },
        //     },
        //     {
        //         key: 'add',
        //         label: '',
        //         icon: 'bi bi-plus',
        //         handler: async (ctx: any) => openCreate(ctx.remote),
        //     },
        {
            key: 'show_favourites_only',
            icon: () => (showFavouritesOnly.value ? 'bi bi-heart-fill' : 'bi bi-heart'),
            variant: 'accent',
            label: 'Zobraziť len obľúbených',
            handler: async ({ remote }) => {
                showFavouritesOnly.value = !showFavouritesOnly.value
                remote.setExtraParam(
                    'filter',
                    showFavouritesOnly.value ? { is_favourite: 1 } : undefined
                )
                await remote.loadPage(1)
            }
        },
    ],
})

// adjust for role-specific scoping
if (auth.isSuperadmin) {
    options.value.endpointUrl = 'v1/doctors'
    options.value.extraParams = undefined
    options.value.selectable = true
    options.value.columns = options.value.columns?.filter(c => c.field !== 'is_favourite')
    options.value.columns?.push({
        field: 'edit',
        header: ' ',
        width: '3rem',
        component: markRaw(ActionButtons),
        componentOptions: [{
            color: 'info',
            icon: 'bi bi-pencil',
            tooltip: 'Upraviť lekára',
            action: async (row: Doctor) => {
                openEdit(row)
            }
        }] as ActionButtonOptions[]
    })
    options.value.actions = [
        {
            key: 'delete',
            label: '',
            icon: 'bi bi-eraser',
            class: 'bg-danger!',
            tooltip: 'Vymazať vybraných lekárov',
            disabled: ({ selectedRows }) => !selectedRows || selectedRows.length === 0,
            confirm: 'Naozaj vymazať vybraných lekárov?',
            handler: async ({ remote, selectedRows }: any) => {
                try {
                    for (const r of selectedRows ?? []) {
                        await api.delete(`/v1/doctors/${r.id}`)
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
            label: '',
            tooltip: 'Pridať nového lekára',
            icon: 'bi bi-plus',
            class: 'bg-accent!',
            handler: async () => {
                openCreate()
            },
        },
    ]
} else if (auth.isManager) {
    // managers should only see doctors that are assigned to a branch, so
    // we switch to the endpoint that applies company-level filtering by
    // assigned branches (the old "my-company" shortcut).
    options.value.endpointUrl = 'v1/my-company/doctors'
    options.value.columns = options.value.columns?.filter(c => !['is_favourite'].includes(c.field ?? ''));
    options.value.actions = options.value.actions?.filter(a => a.key !== 'show_favourites_only');
    options.value.extraParams = { count: 'assigned_patients', with: 'assigned_branches', };

    // Add Pocet pacientov and pobocky columns
    options.value.columns?.push(
        { field: 'assigned_patients_count', header: 'Počet pacientov', sortable: true },
        { field: 'assigned_branches', header: 'Pobočky', sortable: false, width: '35%' },
    );
}



</script>

<template>
    <div class="h-full flex flex-col overflow-hidden min-h-0">
        <UniversalDataTable :options="options">
            <template #col-assigned_branches="{ row }">
                <div class="flex flex-wrap max-h-24 overflow-y-auto">
                    <template v-if="row.assigned_branches && row.assigned_branches.length">
                        <tag v-for="branch in row.assigned_branches" :key="branch.id"
                            class="mr-1 mb-1 bg-accent! text-white! text-normal! border-none! py-1! px-2! rounded-md!"
                            :value="formatBranchFullName(branch)">
                        </tag>
                    </template>
                    <span v-else>-</span>
                </div>
            </template>
        </UniversalDataTable>


        <!-- Modal opened via provider; no inline form component here -->
    </div>
</template>

<style scoped>
.text-heading {
    font-weight: 600;
    font-size: 1.125rem
}
</style>
