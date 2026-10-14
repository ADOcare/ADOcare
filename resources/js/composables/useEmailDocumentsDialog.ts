import { markRaw } from 'vue'
import EmailDocumentsDialog from '@/components/EmailDocumentsDialog.vue'
import useModal from '@/composables/useModal'

export type EmailDialogDocument = {
    id: number
    name?: string
    type?: string
    period?: string
    created_at?: string
    updated_at?: string
}

export type OpenEmailDocumentsDialogOptions = {
    documents: EmailDialogDocument[]
    remote?: { reload?: () => Promise<void>; loadPage?: (page: number) => Promise<void> }
    apiEndpoint?: string
    apiMethod?: 'post' | 'put' | 'patch' | 'delete'
    apiPayload?: Record<string, unknown>
    idKey?: 'ids' | 'invoice_ids'
    header?: string
    buttonLabel?: string
    successMessage?: string
}

export default function useEmailDocumentsDialog() {
    const { openModal } = useModal()

    const openEmailDocumentsDialog = async (options: OpenEmailDocumentsDialogOptions) => {
        const {
            documents,
            remote,
            apiEndpoint = 'v1/documents/email',
            apiMethod = 'post',
            apiPayload = {},
            idKey,
            header = 'Odoslať dokumenty emailom',
            buttonLabel,
            successMessage,
        } = options

        try {
            await openModal(
                markRaw(EmailDocumentsDialog),
                {
                    documents,
                    apiEndpoint,
                    apiMethod,
                    apiPayload,
                    idKey,
                    buttonLabel,
                    successMessage,
                    header,
                },
                { header, style: { width: '40rem' }, closable: true }
            )
        } catch (err) {
            console.error('Failed to open email modal', err)
        } finally {
            if (remote?.reload) {
                await remote.reload()
            } else if (remote?.loadPage) {
                await remote.loadPage(1)
            }
        }
    }

    return { openEmailDocumentsDialog }
}
