import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useDocumentPreviewLoader } from './useDocumentPreviewLoader'

export interface PublicDocumentProps {
    publicData?: any
    isPublic?: boolean
    signature?: string
    expires?: string
}

export interface PublicDocumentOptions {
    privateDataUrl?: string
    privatePreviewUrl?: string
    privateDownloadUrl?: string
}

export function usePublicDocument<T = any>(props: PublicDocumentProps, options: PublicDocumentOptions = {}) {
    const route = useRoute()
    const data = ref<T | null>(null)
    const loading = ref(false)
    const documentId = computed(() => route.params.documentId)
    const { previewUrl, loadPreview } = useDocumentPreviewLoader()

    const isInvoice = computed(() => route.name?.toString().includes('invoice'))

    const getPublicLink = (params: { download?: boolean; format?: string } = {}) => {
        const id = documentId.value
        const path = isInvoice.value ? `/invoices/public/${id}` : `/documents/public/${id}`
        const baseUrl = window.location.origin + path

        const query = new URLSearchParams({
            signature: props.signature || '',
            expires: props.expires || '',
            ...(params.download ? { download: '1' } : {}),
            ...(params.format ? { format: params.format } : {}),
        })
        return `${baseUrl}?${query.toString()}`
    }

    onMounted(async () => {
        if (props.isPublic && props.publicData) {
            data.value = props.publicData.payload as T
            loadPreview(getPublicLink({ format: 'html' }))
            return
        }

        if (options.privatePreviewUrl) {
            loadPreview(options.privatePreviewUrl)
        }

        if (options.privateDataUrl) {
            loading.value = true
            try {
                const res = await api.get(options.privateDataUrl)
                data.value = res.data?.data as T
            } catch (err) {
                console.error('Failed to load private document data', err)
            } finally {
                loading.value = false
            }
        }
    })

    const downloadOptions = computed(() => {
        if (props.isPublic && props.signature) {
            return [
                {
                    url: getPublicLink({ download: true, format: 'pdf' }),
                    fileType: 'PDF',
                    contentType: 'application/pdf',
                },
            ]
        }

        if (options.privateDownloadUrl) {
            return [
                {
                    url: options.privateDownloadUrl,
                    fileType: 'PDF',
                    contentType: 'application/pdf',
                },
            ]
        }

        return []
    })

    return {
        data,
        loading,
        getPublicLink,
        documentId,
        previewUrl,
        downloadOptions,
    }
}
