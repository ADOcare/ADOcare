import { computed } from 'vue'
import { useRoute } from 'vue-router'

export function useDocumentEditMode() {
    const route = useRoute()
    const editDocumentId = computed<number | null>(() => {
        const raw = route.query.editDocumentId
        const value = Array.isArray(raw) ? raw[0] : raw
        const id = Number(value)

        return Number.isInteger(id) && id > 0 ? id : null
    })

    return {
        editDocumentId,
        isEditingDocument: computed(() => editDocumentId.value !== null),
    }
}
