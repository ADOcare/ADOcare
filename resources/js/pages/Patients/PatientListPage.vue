<script setup lang="ts">
import { computed } from 'vue'
import PatientDataTable from '@/components/PatientDataTable.vue'
import SecondaryNavbar from '@/components/SecondaryNavbar.vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const branchId = computed(() => authStore.currentBranch?.id ?? null)
const companyId = computed(() => authStore.user?.company?.id ?? null)
const tableKey = computed(() => `patients-${branchId.value ?? 'none'}`)

const endpointUrl = computed(() => {
    if (authStore.isManager) {
        return `v1/companies/${companyId.value}/patients`
    } else {
        return `v1/branches/${branchId.value}/patients`
    }
})
</script>

<template>
    <div class="h-full flex flex-col overflow-hidden min-h-0">
        <SecondaryNavbar class="flex-none" />

        <div class="min-h-0 flex-1">
            <PatientDataTable :endpoint-url="endpointUrl" :key="tableKey" />
        </div>
    </div>
</template>


