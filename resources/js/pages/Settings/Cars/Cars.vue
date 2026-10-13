<script setup lang="ts">
import { computed, markRaw, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import router from '@/router'
import UniversalDataTable from '@/components/UniversalDataTable.vue'
import type { Car, User } from '@/types/models'
import ActionButtons from '@/components/table-columns/ActionButtons.vue'
import { useToast } from 'primevue/usetoast'
import useModal from '@/composables/useModal'
import CarForm from './CarForm.vue'
import api from '@/services/api'
import useAuthStore from '@/stores/auth'
import type { DataTableOptions, RemoteTableReturn } from '@/types/datatable'
import { formatUserFullName } from '@/utils/formatUtils'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()
const actionRemote = ref<RemoteTableReturn>({} as RemoteTableReturn)
const openingCarId = ref<number | null>(null)

const tableKey = computed(() => `cars-${auth.currentBranch?.id ?? 'global'}`)

const { openModal } = useModal()

async function openEditCar(carId: number) {
    const result = await openModal(markRaw(CarForm), { carId }, { header: 'Upraviť auto', style: { width: '90%' } })
    if (result) {
        toast.add({ severity: 'success', summary: 'Uložené', detail: 'Auto uložené', life: 5000 })
        actionRemote.value?.reload()
    }
}

async function openCreateCar() {
    const result = await openModal(CarForm, {}, { header: 'Pridať auto', style: { width: '90%' } })
    if (result) {
        toast.add({ severity: 'success', summary: 'Vytvorené', detail: 'Auto vytvorené', life: 5000 })
        actionRemote.value?.reload()
    }
}

watch(
    () => route.query.car,
    async (value) => {
        const carId = Number(value)

        if (!Number.isInteger(carId) || carId <= 0 || openingCarId.value === carId) {
            return
        }

        openingCarId.value = carId

        await router.replace({
            query: {
                ...route.query,
                car: undefined,
            },
        })

        try {
            await openEditCar(carId)
        } finally {
            openingCarId.value = null
        }
    },
    { immediate: true },
)

const options = computed<DataTableOptions<Car>>(() => ({
    rowKey: 'id',
    endpointUrl: auth.isSuperadmin && router.currentRoute.value.params.companyId
        ? `v1/companies/${Number(router.currentRoute.value.params.companyId)}/cars`
        : 'v1/my-company/cars',
    defaultPageSize: 25,
    pageSizeOptions: [10, 25, 50],
    selectable: true,
    extraParams: { with: 'user' },
    afterInit: ({ remote }) => {
        actionRemote.value = remote
    },
    columns: [
        { field: 'evc', header: 'EVC', sortable: true },
        { field: 'model', header: 'Model', sortable: true },
        { field: 'user', header: 'Používateľ', render: (v: User) => v ? formatUserFullName(v) : '', sortable: false },
        {
            field: 'edit', header: '', width: '3rem', component: markRaw(ActionButtons), componentOptions: [
                { icon: 'bi bi-pencil', color: 'info', tooltip: 'Upraviť', action: (row: Car) => openEditCar(row.id) }
            ]
        }
    ],
    actions: [
        {
            key: 'delete',
            disabled: ({ selectedRows }) => selectedRows.length === 0,
            icon: 'bi bi-eraser',
            class: 'bg-danger!',
            confirm: 'Zmazať vybrané autá?',
            handler: async ({ selectedRows, remote }) => {
                await api.delete('v1/cars', { data: { ids: selectedRows.map((r) => r.id) } })
                await remote.loadPage(remote.page.value)
            }
        },
        {
            key: 'add',
            icon: 'bi bi-plus-lg',
            class: 'bg-accent!',
            handler: async () => {
                await openCreateCar()
            }
        }
    ]
}))
</script>

<template>
    <div class="h-full flex flex-col overflow-hidden min-h-0">
        <UniversalDataTable :key="tableKey" :options="options" />
    </div>
</template>

<style scoped>
.text-muted {
    color: #6b7280;
}
</style>
