import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useUiOverlayStore } from '@/stores/uiOverlay'
import axios from 'axios'
import api from '@/services/api'

/**
 * Composable for loading document previews with loading state and error handling
 * Displays toast messages in Slovak
 */
export function useDocumentPreviewLoader() {
    const toast = useToast()
    const uiOverlayStore = useUiOverlayStore()

    const loading = ref(false)
    const previewUrl = ref('')

    async function loadPreview(endpoint: string) {
        loading.value = true
        uiOverlayStore.setContentLoading(true)

        try {
            const response = endpoint.startsWith('http')
                ? await axios.get<string>(endpoint, {
                    responseType: 'text',
                })
                : await api.get<string>(endpoint, {
                    responseType: 'text',
                })

            previewUrl.value = response.data
        } catch (error) {
            console.error('Failed to load preview:', error)
            previewUrl.value = ''

            toast.add({
                severity: 'error',
                summary: 'Chyba',
                detail: 'Nepodarilo sa načítať náhľad dokumentu',
                life: 3000,
            })
        } finally {
            loading.value = false
            uiOverlayStore.setContentLoading(false)
        }
    }

    return {
        loading,
        previewUrl,
        loadPreview,
    }
}
