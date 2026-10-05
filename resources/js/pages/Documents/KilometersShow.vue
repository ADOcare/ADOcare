<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import { usePublicDocument, type PublicDocumentProps } from '@/composables/usePublicDocument'
import { useAuthStore } from '@/stores/auth'
import DocumentShell, { type FileItem } from '@/components/DocumentShell.vue'

type KilometersBatchPayload = {
    document_id: number
    batchNumber: string
    batchType: { code: string }
    insurance: { id: number }
    period: string[]
    user?: { id: number }
    branch?: { id: number }
    company?: { id: number | null }
    patients?: { id: number }[]
    meta?: {
        fileName?: string
        amount?: number
        totalKilometers?: number
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

const { data: payload, previewUrl, getPublicLink } = usePublicDocument<KilometersBatchPayload>(props, {
    privateDataUrl: `/v1/kilometers-batches/${route.params.documentId}`,
    privatePreviewUrl: `/v1/kilometers-batches/${route.params.documentId}/preview`,
})

const stored = computed(() => {
    if (!payload.value) {
        return null
    }

    // controller may return { document, kilometers_batch } or directly the batch
    // prefer the wrapped `kilometers_batch` when present
    // @ts-ignore
    return (payload.value.kilometers_batch ?? payload.value) as KilometersBatchPayload | null
})

function normalizeDateOnly(value: string): string {
    const match = String(value ?? '').match(/^(\d{4}-\d{2}-\d{2})/)

    if (match) {
        return match[1] ?? ''
    }

    return value
}

function buildDownloadPayloadFromStored(p: any) {
    const insuranceId = Number(p.insurance?.id || p.insurance_id || 0)
    const branchId = Number(p.branch?.id || p.branch_id || authStore.currentBranch?.id || 0)
    const userId = Number(p.user?.id || p.user_id || authStore.user?.id || 0)
    const companyId = p.company?.id || p.company_id || authStore.currentBranch?.company_id || null

    const batchTypeCode = p.batchType?.code || p.batch_type_code || 'N'

    const normalizedPeriod = (p.period ?? [])
        .map((d: string) => normalizeDateOnly(d))
        .filter(Boolean)

    const patients = (p.patients ?? [])
        .map((x: any) => {
            const id = x && typeof x === 'object' ? (x.id || x.patient_id) : x
            return { id: id ? Number(id) : 0 }
        })
        .filter((x: any) => x.id > 0)

    return {
        batchType: { code: batchTypeCode },
        insurance: { id: insuranceId },
        period: normalizedPeriod,
        user: { id: userId },
        branch: { id: branchId },
        company: companyId ? { id: Number(companyId) } : null,
        patients,
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

const files = computed<FileItem[]>(() => {
    if (!stored.value) {
        return []
    }

    const fileName = stored.value.meta?.fileName ?? `davka.${stored.value.batchNumber}.txt`

    return [
        {
            title: fileName,
            description: 'Vykázaný súbor',
            downloads: [
                {
                    url: props.isPublic ? getPublicLink({ download: true, format: 'txt' }) : '/v1/batches/kilometers/download',
                    method: props.isPublic ? 'get' : 'post',
                    payload: props.isPublic ? undefined : buildDownloadPayloadFromStored(stored.value),
                    fileType: 'TXT',
                    contentType: 'text/plain',
                    filename: fileName,
                },
            ],
        },
    ]
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
        const res = await api.post('/v1/batches/kilometers/download', buildDownloadPayloadFromStored(stored.value), {
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

        console.error('Failed to download kilometers batch:', err)

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Nepodarilo sa stiahnuť dáta dávky. Skúste to prosím neskôr.',
            life: 8000,
        })
    }
}
</script>

<template>
    <DocumentShell
        title="Dávka kilometre"
        :previewUrl="previewUrl"
        :files="files"
        :actions="actions"
        :showPrintButton="true"
        @actionClick="handleActionClick"
    />
</template>
