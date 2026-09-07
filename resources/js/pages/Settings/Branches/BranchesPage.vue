<script setup lang="ts">
import { computed, markRaw, ref } from 'vue'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import type { Branch, User } from '@/types/models'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'
import { useToast } from 'primevue/usetoast'
import useModal from '@/composables/useModal'
import BranchForm from './BranchForm.vue'
import api from '@/services/api'
import Button from 'primevue/button'
import { useAccessStore } from '@/stores/access'
import type { DataTableOptions, RemoteTableReturn } from '@/types/datatable'

const toast = useToast()
const auth = useAuthStore()
const access = useAccessStore()
const actionRemote = ref<RemoteTableReturn>({} as RemoteTableReturn)

// Plan allowance resolved by the backend; the API rejects over-limit creation regardless.
const branchUsage = computed(() => access.usage['branches'] ?? null)
const branchesExhausted = computed(() => branchUsage.value !== null && !branchUsage.value.can_add_more)

function goToBilling() {
    router.push({ name: 'billing' })
}

const { openModal } = useModal()

async function openEditBranch(branchId: number) {
    const payload: any = { branchId }
    if (auth.isSuperadmin) {
        payload.companyId = Number(router.currentRoute.value.params.companyId)
    }
    const result = await openModal(markRaw(BranchForm), payload, { header: 'Upraviť pobočku', style: { width: '90%' } })
    if (result) {
        toast.add({ severity: 'success', summary: 'Uložené', detail: 'Pobočka bola upravená', life: 3000 })
        actionRemote.value?.reload()
    }
}

async function openCreateBranch() {
    const payload: any = {}
    if (auth.isSuperadmin) {
        payload.companyId = Number(router.currentRoute.value.params.companyId)
    }
    const result = await openModal(markRaw(BranchForm), payload, { header: 'Pridať pobočku', style: { width: '90%' } })
    if (result) {
        toast.add({ severity: 'success', summary: 'Vytvorené', detail: 'Pobočka bola vytvorená', life: 3000 })
        actionRemote.value?.reload()
        void access.load()
    }
}

const options = computed<DataTableOptions<Branch>>(() => ({
    rowKey: 'id',
    endpointUrl: auth.isSuperadmin
        ? `v1/companies/${Number(router.currentRoute.value.params.companyId)}/branches`
        : 'v1/my-company/branches',
    defaultPageSize: 25,
    pageSizeOptions: [10, 25, 50],
    selectable: true,
    extraParams: { with: 'representative', count: 'users' },
    afterInit: ({ remote }) => {
        actionRemote.value = remote
    },
    columns: [
        { field: 'address', header: 'Adresa', sortable: false, render: (_v, row: Branch) => `${row.address || ''} ${row.city ? ', ' + row.city : ''}` },
        { field: 'city', header: 'Mesto', sortable: true },
        { field: 'code', header: 'Kód', sortable: true },
        { field: 'representative', header: 'Obozorný zástupca', sortable: false, render: (v: User) => v ? `${v.first_name} ${v.last_name}` : '' },
        { field: 'users_count', header: 'Počet zamestnancov', sortable: true },
        {
            field: 'edit', header: '', width: '3rem', component: ActionButtons, componentOptions: [
                { icon: 'bi bi-pencil', color: 'info', tooltip: 'Upraviť', action: (row: Branch) => openEditBranch(row.id) }
            ]
        }
    ],
    actions: [
        {
            key: 'delete',
            disabled: ({ selectedRows }) => selectedRows.length === 0 || !access.canMutate,
            icon: 'bi bi-eraser',
            class: 'bg-danger!',
            confirm: 'Zmazať vybrané pobočky?',
            handler: async ({ selectedRows, remote }) => {
                await api.delete('v1/branches', { data: { ids: selectedRows.map((r) => r.id) } })
                await remote.loadPage(remote.page.value)
            }
        },
        {
            key: 'add',
            icon: 'bi bi-plus-lg',
            class: 'bg-accent!',
            disabled: () => !access.canMutate || branchesExhausted.value,
            handler: async () => {
                await openCreateBranch()
            }
        }
    ]
}))
</script>

<template>
    <div class="h-full flex flex-col overflow-hidden min-h-0">
        <div v-if="branchUsage" class="mb-4">
            <span class="text-mini uppercase tracking-wide text-lightgrey">Pobočky</span>
            <span class="ml-2 text-normal" :class="branchUsage.over_limit ? 'text-danger' : ''">
                {{ branchUsage.usage }} / {{ branchUsage.unlimited ? 'neobmedzene' : branchUsage.limit ?? '—' }}
            </span>
        </div>

        <div
            v-if="branchUsage && (branchUsage.over_limit || branchesExhausted)"
            class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md bg-tag3 px-4 py-3 text-normal text-white"
        >
            <span v-if="branchUsage.over_limit">
                Máte {{ branchUsage.usage }} pobočiek, váš balík ich povoľuje {{ branchUsage.limit }}.
                Existujúce pobočky zostávajú plně funkčné, ďalšie však nie je možné pridať.
            </span>
            <span v-else>Dosiahli ste limit {{ branchUsage.limit }} pobočiek vášho aktuálneho balíka.</span>
            <Button label="Zmeniť balík" size="small" severity="contrast" @click="goToBilling" />
        </div>

        <UniversalDataTable :options="options" />
    </div>
</template>

<style scoped>
.text-muted {
    color: #6b7280;
}
</style>
