<script setup lang="ts">
import { computed, onMounted, ref, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiOverlayStore } from '@/stores/uiOverlay'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const authStore = useAuthStore()
const uiOverlayStore = useUiOverlayStore()

const loading = ref(false)

const batchNumber = computed(() => String(route.query.batchNumber ?? ''))

const patientIds = computed<number[]>(() => {
    const raw = route.query.patientIds

    if (!raw) {
        return []
    }

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
        user: { id: authStore.user?.id },
        branch: { id: authStore.currentBranch?.id },
        company: { id: authStore.currentBranch?.company_id ?? null },
        patients: patientIds.value.map((id) => ({ id })),
        meta: {
            fileName: String(route.query.fileName ?? `davka.${batchNumber.value}.txt`),
            amount: Number(route.query.amount ?? 0),
            totalKilometers: Number(route.query.kilometers ?? 0),
            performedBy: String(route.query.performedBy ?? ''),
            performedDate: String(route.query.performedDate ?? ''),
            companyName: String(route.query.companyName ?? ''),
            branchName: String(route.query.branchName ?? ''),
            insuranceName: String(route.query.insuranceName ?? ''),
        },
    }
}

function showErrorToasts(messages: string[]) {
    messages.slice(0, 8).forEach((message) => {
        toast.add({
            severity: 'error',
            summary: 'Chyba pri vytváraní dávky',
            detail: message,
            life: 20000,
        })
    })

    if (messages.length > 8) {
        toast.add({
            severity: 'warn',
            summary: 'Ďalšie chyby',
            detail: `Našlo sa ešte ${messages.length - 8} ďalších chýb. Skontrolujte údaje dávky.`,
            life: 20000,
        })
    }
}

async function createBatchAndRedirect() {
    loading.value = true

    try {
        const res = await api.post('/v1/kilometers-batches', buildPayload())
        const documentId = res.data?.data?.document_id

        if (!documentId) {
            throw new Error('Missing document_id')
        }

        await router.replace({
            name: 'documents-kilometers-show',
            params: { documentId },
        })
    } catch (error) {
        console.error('Failed to create kilometers batch:', error)

        const errorData = (error as any)?.response?.data
        const errors = errorData?.errors

        const messages = Array.isArray(errors?.kilometers_export)
            ? errors.kilometers_export
            : errors && typeof errors === 'object'
                ? Object.values(errors).flat().map(String)
                : errorData?.message
                    ? [String(errorData.message)]
                    : []

        if (messages.length > 0) {
            showErrorToasts(messages)
            return
        }

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Nepodarilo sa vytvoriť kilometrovú dávku.',
            life: 8000,
        })
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
    <div></div>
</template>
