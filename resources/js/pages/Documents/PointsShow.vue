<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import { usePublicDocument, type PublicDocumentProps } from '@/composables/usePublicDocument'
import { useAuthStore } from '@/stores/auth'
import DocumentShell, { type FileItem } from '@/components/DocumentShell.vue'

type PointsBatchPayload = {
    document_id: number
    batchNumber: string
    batchType: { code: string }
    insurance: { id: number }
    period: string[]
    user?: { id: number }
    branch?: { id: number }
    company?: { id: number | null }
    pointIds?: number[]
    claimBatchId?: number
    patients?: { id: number }[]
    meta?: {
        fileName?: string
        amount?: number
        performedBy?: string
        performedDate?: string
        companyName?: string
        branchName?: string
        insuranceName?: string
    }
}

const props = defineProps<PublicDocumentProps>()
const route = useRoute()
const toast = useToast()
const authStore = useAuthStore()

const { data: payload, previewUrl, getPublicLink } = usePublicDocument<PointsBatchPayload>(props, {
    privateDataUrl: `/v1/points-batches/${route.params.documentId}`,
    privatePreviewUrl: `/v1/points-batches/${route.params.documentId}/preview`,
})

const stored = computed(() => {
    if (!payload.value) {
        return null
    }

    // controller returns { document, points_batch } — unwrap when present
    // otherwise payload.value may already be the points batch
    // support both shapes for robustness
    // @ts-ignore
    return (payload.value.points_batch ?? payload.value) as PointsBatchPayload | null
})

function normalizeDateOnly(value: string): string {
    const match = String(value ?? '').match(/^(\d{4}-\d{2}-\d{2})/)

    if (match) {
        return match[1] ?? ''
    }

    return value
}

function buildDownloadPayloadFromStored(p: PointsBatchPayload) {
    const insuranceId = Number(p.insurance?.id) || 0
    const branchId = Number(p.branch?.id) || Number(authStore.currentBranch?.id) || 0
    const normalizedPeriod = (p.period ?? []).map((d) => normalizeDateOnly(d))
    const storedPointIds = (p.pointIds ?? []).map((id) => Number(id)).filter((id) => id > 0)
    const storedPatients = (p.patients ?? []).map((patient) => ({ id: Number(patient.id) }))

    return {
        batchType: { code: p.batchType?.code ?? 'N' },
        insurance: { id: insuranceId },
        period: normalizedPeriod,
        branch: { id: branchId },
        claimBatchId: Number(p.claimBatchId ?? 0) || undefined,
        ...(storedPointIds.length > 0 ? { pointIds: storedPointIds } : {}),
        ...(storedPatients.length > 0 ? { patients: storedPatients } : {}),
    }
}

function showErrorToasts(messages: string[]) {
    messages.slice(0, 8).forEach((message) => {
        toast.add({
            severity: 'error',
            summary: 'Chyba pri sťahovaní dávky',
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

const downloadFiles = computed<FileItem[]>(() => {
    if (!stored.value) {
        return []
    }

    const fileName = stored.value?.meta?.fileName ?? `davka.${stored.value.batchNumber}.txt`

    const item: FileItem = {
        title: fileName,
        description: `Davka č. ${payload.value?.batchNumber}`,
        downloads: [
            {
                filename: fileName,
                url: props.isPublic ? getPublicLink({ download: true, format: 'txt' }) : '/v1/batches/points/download',
                method: props.isPublic ? 'get' : 'post',
                payload: props.isPublic ? undefined : buildDownloadPayloadFromStored(stored.value),
                fileType: 'TXT',
                contentType: 'text/plain',
            },
        ],
    }

    return [item]
})

const actions = computed(() => [
    {
        id: 'download-batch',
        label: 'Stiahnuť dáta dávky',
        icon: 'bi bi-download',
        adonis: true,
    },
])

async function handleActionClick(actionId: string) {
    if (actionId !== 'download-batch') {
        return
    }

    if (!stored.value) {
        return
    }

    const fileName = stored.value.meta?.fileName ?? `davka.${stored.value.batchNumber}.txt`

    if (props.isPublic) {
        window.open(getPublicLink({ download: true, format: 'txt' }), '_blank')
        return
    }

    try {
        const res = await api.post('/v1/batches/points/download', buildDownloadPayloadFromStored(stored.value), {
            responseType: 'blob',
            headers: { Accept: 'text/plain' },
        })

        const blob = new Blob([res.data], { type: 'text/plain' })
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = fileName
        a.click()
        setTimeout(() => URL.revokeObjectURL(url), 100)
    } catch (err: any) {
        let errorData = err?.response?.data

        if (errorData instanceof Blob) {
            try {
                const text = await errorData.text()
                errorData = JSON.parse(text)
            } catch {
                errorData = null
            }
        }

        const errors = errorData?.errors

        const messages = errors && typeof errors === 'object'
            ? Object.values(errors).flat().map(String)
            : errorData?.message
                ? [String(errorData.message)]
                : []

        if (messages.length > 0) {
            showErrorToasts(messages)
            return
        }

        console.error('Failed to download batch:', err)

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Nepodarilo sa stiahnuť dávku.',
            life: 8000,
        })
    }
}
</script>

<template>
    <DocumentShell
        title="Dávka bodov"
        :previewUrl="previewUrl"
        :files="downloadFiles"
        :actions="actions"
        :showPrintButton="true"
        @actionClick="handleActionClick"
    />
</template>
