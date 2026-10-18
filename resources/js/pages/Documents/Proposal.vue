<script setup lang="ts">
import { useRoute } from 'vue-router'
import { usePublicDocument, type PublicDocumentProps } from '@/composables/usePublicDocument'
import DocumentShell from '@/components/DocumentShell.vue'

const props = defineProps<PublicDocumentProps>()
const route = useRoute()

const { previewUrl, downloadOptions } = usePublicDocument(props, {
    privatePreviewUrl: `/v1/proposals/${route.params.documentId}/preview`,
    privateDownloadUrl: `/api/v1/proposals/${route.params.documentId}/download`
})
</script>

<template>
    <DocumentShell title="Návrh na poskytovanie ošetrovateľskej starostlivosti" :previewUrl="previewUrl"
        :downloadOptions="downloadOptions" :showPrintButton="true" />
</template>
