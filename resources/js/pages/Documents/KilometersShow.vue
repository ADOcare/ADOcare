<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import { usePublicDocument, type PublicDocumentProps } from '@/composables/usePublicDocument'
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
    correction_review?: { reason: string; reviewed_at: string; confirmed_rejected: boolean } | null
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

    const fileName = stored.value.meta?.fileName ?? `davka.${stored.value.batchNumber}`

    return [
        {
            title: fileName,
            description: 'Vykázaný súbor',
            downloads: [
                {
                    url: props.isPublic ? getPublicLink({ download: true, format: 'txt' }) : '/v1/batches/kilometers/download',
                    method: props.isPublic ? 'get' : 'post',
                    payload: props.isPublic ? undefined : { document_id: stored.value.document_id ?? Number(route.params.documentId) },
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

    const fileName = stored.value.meta?.fileName ?? `davka.${stored.value.batchNumber}`

    if (props.isPublic) {
        window.open(getPublicLink({ download: true, format: 'txt' }), '_blank')
        return
    }

    try {
        const res = await api.post('/v1/batches/kilometers/download', { document_id: stored.value.document_id ?? Number(route.params.documentId) }, {
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
    <section v-if="!props.isPublic && stored?.correction_review" class="bg-tag3 rounded-md p-4 mb-4">
        <h2 class="font-semibold mb-2">Odôvodnenie reklamácie</h2>
        <p class="whitespace-pre-wrap">{{ stored.correction_review.reason }}</p>
        <p class="text-mini text-darkgrey mt-2">Uložené odôvodnenie nie je súčasťou TXT. Priložte ho k reklamácii spôsobom požadovaným poisťovňou.</p>
    </section>
    <DocumentShell
        title="Dávka kilometre"
        :previewUrl="previewUrl"
        :files="files"
        :actions="actions"
        :showPrintButton="true"
        @actionClick="handleActionClick"
    />
</template>
