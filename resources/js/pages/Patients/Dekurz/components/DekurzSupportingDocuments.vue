<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import Dialog from 'primevue/dialog'
import { useDocumentPreviewLoader } from '@/composables/useDocumentPreviewLoader'
import { fetchBlobUrl } from '@/utils/blobUrl'
import type { DekurzSupportingDocument } from '@/types/dekurz'

const props = defineProps<{
    documents: DekurzSupportingDocument[]
}>()

const dialogVisible = ref(false)
const activeDocument = ref<DekurzSupportingDocument | null>(null)
const { loading, previewUrl, loadPreview } = useDocumentPreviewLoader()

function formatDate(value: string | null | undefined) {
    if (!value) return 'Dátum nie je dostupný'
    return new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString('sk-SK')
}

async function preview(document: DekurzSupportingDocument) {
    if (!document.document) return
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
    activeDocument.value = document
    dialogVisible.value = true
    await loadPreview(document.document.preview_url)
}

async function download(document: DekurzSupportingDocument) {
    if (!document.document) return
    const blobUrl = await fetchBlobUrl(document.document.download_url)
    const anchor = window.document.createElement('a')
    anchor.href = blobUrl
    anchor.download = document.document.name || `${document.label}.pdf`
    anchor.click()
    window.setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
}

onBeforeUnmount(() => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
</script>

<template>
    <section aria-labelledby="supporting-documents-heading">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <h2 id="supporting-documents-heading" class="text-heading">Podporné dokumenty</h2>
                <p class="text-mini text-lightgrey">Podklady vyhľadané pre zvolený mesiac</p>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-3">
            <article
                v-for="document in props.documents"
                :key="document.key"
                class="rounded-md border border-tag3 bg-white p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium text-darkgrey">{{ document.label }}</p>
                        <template v-if="document.document">
                            <p class="mt-1 truncate text-mini text-lightgrey" :title="document.document.name || undefined">
                                {{ document.document.name || document.label }}
                            </p>
                            <p class="text-mini text-lightgrey">{{ formatDate(document.document.date) }}</p>
                        </template>
                        <p v-else class="mt-1 text-mini text-warning">Dokument sa nenašiel</p>
                    </div>
                    <span
                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                        :class="document.document ? 'bg-success/15 text-success' : 'bg-warning/15 text-warning'"
                        :title="document.document ? 'Dokument je dostupný' : 'Dokument chýba'"
                    >
                        <i :class="document.document ? 'bi bi-check-lg' : 'bi bi-exclamation-lg'" />
                    </span>
                </div>

                <div v-if="document.document" class="mt-3 flex gap-2">
                    <Button
                        type="button"
                        icon="bi bi-eye"
                        text
                        size="small"
                        aria-label="Zobraziť náhľad"
                        title="Zobraziť náhľad"
                        @click="preview(document)"
                    />
                    <Button
                        type="button"
                        icon="bi bi-download"
                        text
                        size="small"
                        aria-label="Stiahnuť dokument"
                        title="Stiahnuť dokument"
                        @click="download(document)"
                    />
                </div>
            </article>
        </div>
    </section>

    <Dialog
        v-model:visible="dialogVisible"
        modal
        maximizable
        :header="activeDocument?.label || 'Náhľad dokumentu'"
        :style="{ width: 'min(1000px, 95vw)' }"
    >
        <div class="relative h-[72vh] bg-tag3">
            <div v-if="loading" class="absolute inset-0 flex items-center justify-center text-accent">
                Načítavam náhľad…
            </div>
            <iframe v-else-if="previewUrl" :src="previewUrl" class="h-full w-full border-0" title="Náhľad dokumentu" />
            <div v-else class="flex h-full items-center justify-center text-lightgrey">Náhľad nie je dostupný.</div>
        </div>
    </Dialog>
</template>
