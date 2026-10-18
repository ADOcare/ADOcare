<script setup lang="ts">
import { useRoute } from 'vue-router'

import DocumentShell from '@/components/DocumentShell.vue'
import ScanDocumentContent from '@/components/documents/ScanDocumentContent.vue'

import {
    usePublicDocument,
    type PublicDocumentProps,
} from '@/composables/usePublicDocument'

const props =
    defineProps<PublicDocumentProps>()

const route =
    useRoute()

const {
    data,
    loading,
    documentId,
} = usePublicDocument(
    props,
    {
        privateDataUrl:
            `/v1/scan/${route.params.documentId}`,
        privatePreviewUrl:
            `/v1/scan/${route.params.documentId}/preview`,
        privateDownloadUrl:
            `/api/v1/scan/${route.params.documentId}/download`,
    },
)
</script>

<template>
    <DocumentShell
        title="Lekársky nález"
        previewUrl=""
        :downloadOptions="[]"
        :actions="[]"
        :showPrintButton="false"
    >
        <ScanDocumentContent
            :data="data"
            :document-id="documentId"
            :is-public="props.isPublic"
            :loading="loading"
        />
    </DocumentShell>
</template>
