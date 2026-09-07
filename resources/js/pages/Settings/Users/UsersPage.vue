<script setup lang="ts">
import { computed, markRaw, ref } from 'vue'
import router from '@/router'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import type { Role, User } from '@/types/models'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'
import { useToast } from 'primevue/usetoast'
import useModal from '@/composables/useModal'
import UserForm from './UserForm.vue'
import api from '@/services/api'
import Button from 'primevue/button'
import useAuthStore from '@/stores/auth'
import { useAccessStore } from '@/stores/access'
import type { DataTableOptions, RemoteTableReturn } from '@/types/datatable'

const toast = useToast()
const auth = useAuthStore()
const access = useAccessStore()
const actionRemote = ref<RemoteTableReturn>({} as RemoteTableReturn)

// Allowances come from the plan entitlements resolved by the backend - the UI only mirrors
// them, the API rejects over-limit creation regardless of what is rendered here. Managers are
// a separate account type with their own allowance and never consume a user seat.
const seats = computed(() => access.usage['users'] ?? null)
const managerSeats = computed(() => access.usage['managers'] ?? null)
const seatsExhausted = computed(() => seats.value !== null && !seats.value.can_add_more)

function formatAllowance(usage: { usage: number; limit: number | null; unlimited: boolean } | null): string {
    if (!usage) return ''
    return `${usage.usage} / ${usage.unlimited ? 'neobmedzene' : usage.limit ?? '—'}`
}

function goToBilling() {
    router.push({ name: 'billing' })
}

const { openModal } = useModal()

const roleNameMap: Record<string, string> = {
    manager: 'Manažér',
    nurse: 'Sestra',
    branch_manager: 'Manažér pobočky',
    admin: 'Administrátor',
    superadmin: 'Superadministrátor',
    'super-admin': 'Superadministrátor'
}

const getRoleName = (role: Role | undefined): string => {
    if (!role) return ''
    return roleNameMap[role.position || ''] || role.name || role.position || ''
}

const tableKey = computed(() => `users-${auth.currentBranch?.id ?? 'global'}`)

async function openEditUser(userId: number) {
    const result = await openModal(markRaw(UserForm), { userId }, { header: 'Upraviť používateľa', style: { width: '800px' } })
    if (result) {
        toast.add({ severity: 'success', summary: 'Uložené', detail: 'Používateľ bol uložený', life: 3000 })
        actionRemote.value?.reload()
    }
}

async function openCreateUser() {
    const result = await openModal(markRaw(UserForm), {}, { header: 'Pridať používateľa', style: { width: '800px' } })
    if (result) {
        toast.add({ severity: 'success', summary: 'Vytvorené', detail: 'Používateľ bol vytvorený', life: 3000 })
        actionRemote.value?.reload()
        void access.load()
    }
}

const options = computed<DataTableOptions<User>>(() => ({
    rowKey: 'id',
    endpointUrl: auth.isSuperadmin
        ? `v1/companies/${Number(router.currentRoute.value.params.companyId)}/users`
        : 'v1/my-company/users',
    defaultPageSize: 25,
    pageSizeOptions: [10, 25, 50],
    extraParams: { with: 'role' },
    selectable: true,
    afterInit: ({ remote }) => {
        actionRemote.value = remote
    },
    columns: [
        { field: 'first_name', header: 'Meno', sortable: true },
        { field: 'last_name', header: 'Priezvisko', sortable: true },
        { field: 'title', header: 'Titul', sortable: false },
        { field: 'code', header: 'Kód', sortable: true },
        { field: 'role', header: 'Rola', sortable: false, render: (_value, row) => getRoleName(row.role) },
        {
            field: 'edit', header: '', width: '3rem', component: markRaw(ActionButtons), componentOptions: [
                { icon: 'bi bi-pencil', color: 'info', tooltip: 'Upraviť', action: (row: User) => openEditUser(row.id) }
            ]
        }
    ],
    actions: [
        {
            key: 'delete',
            disabled: ({ selectedRows }) => selectedRows.length === 0 || !access.canMutate,
            icon: 'bi bi-eraser',
            class: 'bg-danger!',
            confirm: 'Naozaj vymazať vybraných používateľov?',
            handler: async ({ selectedRows, remote }) => {
                await api.delete('v1/users', { data: { ids: selectedRows.map((r) => r.id) } })
                await remote.loadPage(remote.page.value)
            }
        },
        {
            key: 'add',
            icon: 'bi bi-plus-lg',
            class: 'bg-accent!',
            disabled: () => !access.canMutate || seatsExhausted.value,
            handler: async () => {
                await openCreateUser()
            }
        }
    ]
}))
</script>

<template>
    <div class="h-full flex flex-col overflow-hidden min-h-0">
        <div v-if="seats || managerSeats" class="mb-4 flex flex-wrap items-center gap-6">
            <div v-if="seats">
                <span class="text-mini uppercase tracking-wide text-lightgrey">Používatelia</span>
                <span class="ml-2 text-normal" :class="seats.over_limit ? 'text-danger' : ''">
                    {{ formatAllowance(seats) }}
                </span>
            </div>
            <div v-if="managerSeats">
                <span class="text-mini uppercase tracking-wide text-lightgrey">Manažéri</span>
                <span class="ml-2 text-normal" :class="managerSeats.over_limit ? 'text-danger' : ''">
                    {{ formatAllowance(managerSeats) }}
                </span>
            </div>
        </div>

        <div
            v-if="seats && seats.over_limit"
            class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md bg-tag3 px-4 py-3 text-normal text-white"
        >
            <span>
                Máte {{ seats.usage }} používateľov, váš balík ich povoľuje {{ seats.limit }}.
                Existujúci používatelia zostávajú zachovaní, ďalších však nie je možné pridať.
            </span>
            <Button label="Zmeniť balík" size="small" severity="contrast" @click="goToBilling" />
        </div>

        <div
            v-else-if="seatsExhausted && seats"
            class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md bg-tag3 px-4 py-3 text-normal text-white"
        >
            <span>Dosiahli ste limit {{ seats.limit }} používateľov vášho aktuálneho balíka.</span>
            <Button label="Zmeniť balík" size="small" severity="contrast" @click="goToBilling" />
        </div>

        <UniversalDataTable :key="tableKey" :options="options" />
    </div>
</template>

<style scoped>
.text-muted {
    color: #6b7280;
}
</style>
