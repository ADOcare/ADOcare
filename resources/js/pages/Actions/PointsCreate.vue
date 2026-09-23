<script setup lang="ts">
import { computed, onMounted, ref, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiOverlayStore } from '@/stores/uiOverlay'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const uiOverlayStore = useUiOverlayStore()

const loading = ref(false)
const errorMessage = ref('')

const batchNumber = computed(() => String(route.query.batchNumber ?? ''))
const pointIds = computed<number[]>(() => {
    const raw = route.query.pointIds
    if (!raw) return []
    try {
        const arr = JSON.parse(String(raw))
        return Array.isArray(arr) ? arr.map((x) => Number(x)).filter((x) => x > 0) : []
    } catch {
        return []
    }
})

function buildPayload() {
    const period0 = String(route.query.period0 ?? route.query.periodFrom ?? '')
    const period1 = String(route.query.period1 ?? route.query.periodTo ?? '')

    return {
        batchType: { code: String(route.query.batchTypeCode ?? 'N') },
        insurance: { id: Number(route.query.insuranceId ?? 0) },
        period: [period0, period1],
        branch: { id: authStore.currentBranch?.id },
        company: { id: authStore.currentBranch?.company_id ?? null },
        pointIds: pointIds.value,
        meta: {
            fileName: String(route.query.fileName ?? `davka.${batchNumber.value}.txt`),
            amount: Number(route.query.amount ?? 0),
            performedBy: String(route.query.performedBy ?? ''),
            performedDate: String(route.query.performedDate ?? ''),
            companyName: String(route.query.companyName ?? ''),
            branchName: String(route.query.branchName ?? ''),
            insuranceName: String(route.query.insuranceName ?? ''),
        },
    }
}

async function createBatchAndRedirect() {
    loading.value = true
    errorMessage.value = ''

    try {
        const res = await api.post('/v1/points-batches', buildPayload())
        const documentId = res.data?.data?.document_id

        if (!documentId) {
            throw new Error('Missing document_id')
        }

        await router.replace({
            name: 'documents-points-show',
            params: { documentId },
        })
    } catch (error) {
        console.error('Failed to create points batch:', error)
        errorMessage.value = 'Nepodarilo sa vytvoriť bodovú dávku.'
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    void createBatchAndRedirect()
})

watchEffect(() => {
    uiOverlayStore.setContentLoading(loading.value)
})
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-if="errorMessage" class="text-red-700 text-sm">
            {{ errorMessage }}
        </div>
    </div>
</template>
