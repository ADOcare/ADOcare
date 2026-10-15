import { computed, ref, type Ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import type {
    DekurzBuilderPayload,
    DekurzDay,
    DekurzEntry,
    DekurzMacro,
    DekurzProcedure,
    DekurzRawBuilderPayload,
    DekurzRawDocument,
} from '@/types/dekurz'

type SaveState = 'idle' | 'saving' | 'saved' | 'error'

export function useDekurzV2(patientId: Ref<number>, month: Ref<string>) {
    const toast = useToast()
    const builder = ref<DekurzBuilderPayload | null>(null)
    const macros = ref<DekurzMacro[]>([])
    const loading = ref(false)
    const initializing = ref(false)
    const finalizing = ref(false)
    const finalizationErrors = ref<string[]>([])
    const error = ref('')
    const saveStates = ref<Record<string, SaveState>>({})
    const pendingSaves = new Set<Promise<unknown>>()

    const draftId = computed(() => builder.value?.draft?.id ?? null)

    function messageFrom(errorValue: unknown, fallback: string) {
        const error = errorValue as { response?: { data?: { message?: string } } }
        return error.response?.data?.message ?? fallback
    }

    function track<T>(promise: Promise<T>): Promise<T> {
        pendingSaves.add(promise)
        promise.finally(() => pendingSaves.delete(promise))
        return promise
    }

    function normalizeDocument(document: DekurzRawDocument | null) {
        if (!document) return null
        return {
            id: document.id,
            name: document.name,
            type: document.type,
            date: document.document_date,
            date_source: document.date_source,
            preview_url: document.preview_url,
            download_url: document.download_url,
        }
    }

    function normalize(raw: DekurzRawBuilderPayload): DekurzBuilderPayload {
        const procedureById = new Map(raw.procedures.map((procedure) => [procedure.procedure_id, procedure]))
        const draftDays = new Map((raw.draft?.days ?? []).map((day) => [day.date, day]))
        const supportSelections = [
            { key: 'finding' as const, label: 'Nález', selection: raw.supporting_documents.finding },
            { key: 'proposal' as const, label: 'Návrh', selection: raw.supporting_documents.proposal },
            {
                key: 'previous_dekurz' as const,
                label: 'Predchádzajúci Dekurz',
                selection: {
                    default: raw.supporting_documents.previous_dekurz,
                    candidates: raw.supporting_documents.previous_dekurz ? [raw.supporting_documents.previous_dekurz] : [],
                },
            },
        ]

        return {
            patient: {
                id: raw.patient.id,
                full_name: raw.patient.name,
                dekurz_number: null,
            },
            month: {
                key: raw.month,
                start: `${raw.month}-01`,
                end: `${raw.month}-01`,
            },
            procedures: raw.procedures.map((procedure) => ({
                id: procedure.procedure_id,
                code: procedure.code,
                name: procedure.description ?? '',
                scoring_rows_count: procedure.scoring_rows_count,
                effective_text: procedure.template.text,
                effective_text_source: procedure.template.source,
                patient_template_text: procedure.template.source === 'patient_template' ? procedure.template.text : null,
                procedure_default_text: null,
            })),
            days: raw.days.map((day) => {
                const draftDay = draftDays.get(day.date)
                return {
                    id: draftDay?.id ?? null,
                    date: day.date,
                    note: draftDay?.general_note ?? null,
                    entries: day.entries.map((entry) => ({
                        id: entry.dekurz_entry_id,
                        patient_point_snapshot_id: entry.patient_point_id,
                        patient_point_id: entry.patient_point_id,
                        procedure_id: entry.procedure_id,
                        procedure_code: entry.procedure_code,
                        procedure_name: procedureById.get(entry.procedure_id)?.description ?? '',
                        quantity: entry.quantity,
                        points: null,
                        text: entry.text,
                        source: entry.source === 'saved_draft' ? (entry.saved_source ?? 'manual') : entry.source,
                        edited_at: entry.edited_at,
                    })),
                }
            }),
            supporting_documents: supportSelections.map(({ key, label, selection }) => ({
                key,
                label,
                status: selection.default ? 'found' : 'missing',
                document: normalizeDocument(selection.default),
                candidates: selection.candidates.map((document) => ({
                    id: document.id,
                    name: document.name,
                    type: document.type,
                    date: document.document_date,
                    date_source: document.date_source,
                })),
            })),
            draft: raw.draft
                ? {
                      id: raw.draft.id,
                      status: raw.draft.status,
                      source_period_start: `${raw.draft.period}-01`,
                      source_period_end: `${raw.draft.period}-01`,
                      initialized_at: null,
                      completed_at: raw.draft.completed_at,
                      final_document_id: raw.draft.final_document_id,
                  }
                : null,
            scoring_changes: {
                added: raw.scoring_changes.added.length,
                removed: raw.scoring_changes.removed.length,
                changed: raw.scoring_changes.changed.length,
                has_changes:
                    raw.scoring_changes.added.length > 0 ||
                    raw.scoring_changes.removed.length > 0 ||
                    raw.scoring_changes.changed.length > 0,
            },
        }
    }

    async function load() {
        if (!patientId.value || !month.value) return
        loading.value = true
        error.value = ''
        finalizationErrors.value = []
        try {
            const response = await api.get(`/v1/patients/${patientId.value}/dekurz-builder`, {
                params: { month: month.value },
            })
            builder.value = normalize(response.data.data as DekurzRawBuilderPayload)
        } catch (errorValue) {
            error.value = messageFrom(errorValue, 'Podklady pre Dekurz sa nepodarilo načítať.')
        } finally {
            loading.value = false
        }
    }

    async function loadMacros() {
        try {
            const response = await api.get('/v1/macros', {
                params: { per_page: 100, sort: 'name' },
            })
            const items = response.data?.data?.items ?? response.data?.data ?? []
            macros.value = items.map((item: { id: number; name: string; text: string }) => ({
                key: String(item.id),
                title: item.name,
                body: item.text,
            }))
        } catch {
            macros.value = []
        }
    }

    async function initializeDraft() {
        if (!patientId.value || !month.value) return false
        if (builder.value?.draft) return true
        initializing.value = true
        try {
            const response = await api.post(`/v1/patients/${patientId.value}/dekurz-drafts`, {
                month: month.value,
            })
            builder.value = normalize(response.data.data as DekurzRawBuilderPayload)
            return true
        } catch (errorValue) {
            toast.add({
                severity: 'error',
                summary: 'Koncept sa nepodarilo vytvoriť',
                detail: messageFrom(errorValue, 'Skontrolujte podklady a skúste to znova.'),
                life: 5000,
            })
            return false
        } finally {
            initializing.value = false
        }
    }

    async function saveTemplate(procedure: DekurzProcedure) {
        if (!procedure.effective_text.trim()) {
            await resetTemplate(procedure)
            return
        }

        const key = `template-${procedure.id}`
        saveStates.value[key] = 'saving'
        const request = api
            .put(`/v1/patients/${patientId.value}/dekurz-templates/${procedure.id}`, {
                text: procedure.effective_text,
            })
            .then(() => {
                procedure.patient_template_text = procedure.effective_text
                procedure.effective_text_source = 'patient_template'
                saveStates.value[key] = 'saved'
            })
            .catch((errorValue) => {
                saveStates.value[key] = 'error'
                toast.add({
                    severity: 'error',
                    summary: 'Text sa nepodarilo uložiť',
                    detail: messageFrom(errorValue, 'Skúste to znova.'),
                    life: 4000,
                })
                throw errorValue
            })
        return track(request)
    }

    function resetTemplate(procedure: DekurzProcedure) {
        const key = `template-${procedure.id}`
        saveStates.value[key] = 'saving'
        const request = api
            .delete(`/v1/patients/${patientId.value}/dekurz-templates/${procedure.id}`)
            .then(async () => {
                await load()
                saveStates.value[key] = 'saved'
            })
            .catch((errorValue) => {
                saveStates.value[key] = 'error'
                toast.add({
                    severity: 'error',
                    summary: 'Text sa nepodarilo obnoviť',
                    detail: messageFrom(errorValue, 'Skúste to znova.'),
                    life: 4000,
                })
            })
        return track(request)
    }

    async function saveEntry(entry: DekurzEntry) {
        if (!draftId.value || !entry.id) return
        const key = `entry-${entry.id}`
        saveStates.value[key] = 'saving'
        const request = api
            .patch(`/v1/patients/${patientId.value}/dekurz-drafts/${draftId.value}/entries/${entry.id}`, {
                text: entry.text,
            })
            .then((response) => {
                Object.assign(entry, response.data.data)
                saveStates.value[key] = 'saved'
            })
            .catch((errorValue) => {
                saveStates.value[key] = 'error'
                toast.add({
                    severity: 'error',
                    summary: 'Denný text sa nepodarilo uložiť',
                    detail: messageFrom(errorValue, 'Skúste to znova.'),
                    life: 4000,
                })
                throw errorValue
            })
        return track(request)
    }

    async function saveDay(day: DekurzDay) {
        if (!draftId.value || !day.id) return
        const key = `day-${day.id}`
        saveStates.value[key] = 'saving'
        const request = api
            .patch(`/v1/patients/${patientId.value}/dekurz-drafts/${draftId.value}/days/${day.id}`, {
                general_note: day.note,
            })
            .then((response) => {
                day.note = response.data.data.general_note
                saveStates.value[key] = 'saved'
            })
            .catch((errorValue) => {
                saveStates.value[key] = 'error'
                toast.add({
                    severity: 'error',
                    summary: 'Poznámka sa nepodarila uložiť',
                    detail: messageFrom(errorValue, 'Skúste to znova.'),
                    life: 4000,
                })
                throw errorValue
            })
        return track(request)
    }

    async function applyToUnedited(entry: DekurzEntry) {
        if (!draftId.value || !entry.id) return
        try {
            await saveEntry(entry)
            const response = await api.post(
                `/v1/patients/${patientId.value}/dekurz-drafts/${draftId.value}/entries/${entry.id}/apply-to-unedited`,
            )
            const result = response.data.data as { updated_count: number; updated_entry_ids: number[] }
            const updatedIds = new Set(result.updated_entry_ids)
            const editedAt = new Date().toISOString()
            for (const day of builder.value?.days ?? []) {
                for (const target of day.entries) {
                    if (target.id && updatedIds.has(target.id)) {
                        target.text = entry.text
                        target.source = 'manual'
                        target.edited_at = editedAt
                    }
                }
            }
            toast.add({
                severity: 'success',
                summary: 'Text bol použitý',
                detail: `Aktualizované dni: ${result.updated_count}`,
                life: 3000,
            })
        } catch (errorValue) {
            toast.add({
                severity: 'error',
                summary: 'Text sa nepodarilo použiť',
                detail: messageFrom(errorValue, 'Skúste to znova.'),
                life: 4000,
            })
        }
    }

    async function finalizeDraft() {
        if (!draftId.value || !builder.value?.draft) return false
        finalizing.value = true
        finalizationErrors.value = []
        try {
            const savesSucceeded = await flushPendingSaves()
            if (!savesSucceeded) return false
            const response = await api.post(
                `/v1/patients/${patientId.value}/dekurz-drafts/${draftId.value}/finalize`,
            )
            builder.value.draft.status = 'completed'
            builder.value.draft.completed_at = new Date().toISOString()
            builder.value.draft.final_document_id = Number(response.data.data.document_id)
            toast.add({
                severity: 'success',
                summary: 'Dekurz bol finalizovaný',
                detail: 'Historický dokument a PDF boli vytvorené zo snapshotu.',
                life: 5000,
            })
            return true
        } catch (errorValue) {
            const responseErrors = (errorValue as { response?: { data?: { errors?: Record<string, string[]> } } })
                .response?.data?.errors
            finalizationErrors.value = responseErrors
                ? Object.values(responseErrors).flat()
                : [messageFrom(errorValue, 'Dekurz sa nepodarilo finalizovať.')]
            toast.add({
                severity: 'error',
                summary: 'Finalizácia zlyhala',
                detail: finalizationErrors.value[0],
                life: 5000,
            })
            return false
        } finally {
            finalizing.value = false
        }
    }

    async function flushPendingSaves() {
        while (pendingSaves.size > 0) {
            await Promise.allSettled([...pendingSaves])
        }
        return !Object.values(saveStates.value).includes('error')
    }

    return {
        builder,
        macros,
        loading,
        initializing,
        finalizing,
        finalizationErrors,
        error,
        saveStates,
        load,
        loadMacros,
        initializeDraft,
        saveTemplate,
        resetTemplate,
        saveEntry,
        saveDay,
        applyToUnedited,
        finalizeDraft,
        flushPendingSaves,
    }
}
