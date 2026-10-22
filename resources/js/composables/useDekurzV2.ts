import { computed, ref, type Ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import api from '@/services/api'
import type {
    DekurzBuilderPayload,
    DekurzCustomTrack,
    DekurzEntry,
    DekurzMacro,
    DekurzProcedure,
    DekurzPropagationRequest,
    DekurzRawBuilderPayload,
    DekurzRawDocument,
    DekurzTextSource,
} from '@/types/dekurz'

type SaveState = 'idle' | 'saving' | 'saved' | 'error'

export function useDekurzV2(
    patientId: Ref<number>,
    month: Ref<string>,
    dekurzNumber: Ref<string>,
) {
    const toast = useToast()
    const builder = ref<DekurzBuilderPayload | null>(null)
    const macros = ref<DekurzMacro[]>([])
    const loading = ref(false)
    const initializing = ref(false)
    const finalizing = ref(false)
    const finalizationErrors = ref<string[]>([])
    const draftInitializationError = ref('')
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
        const supportSelections = [
            { key: 'finding' as const, label: 'Nález', selection: raw.supporting_documents.finding },
            { key: 'proposal' as const, label: 'Návrh', selection: raw.supporting_documents.proposal },
            {
                key: 'previous_dekurz' as const,
                label: 'Dekurz',
                selection: {
                    default: raw.supporting_documents.previous_dekurz,
                    candidates: raw.supporting_documents.previous_dekurz
                        ? [raw.supporting_documents.previous_dekurz]
                        : [],
                },
            },
        ]

        const procedureSources = new Map(
            raw.procedures.map(procedure => [
                procedure.procedure_id,
                procedure.template,
            ]),
        )

        function resolvedEntrySource(entry: DekurzRawBuilderPayload['days'][number]['entries'][number]): DekurzTextSource {
            if (
                entry.track_type !== 'procedure' ||
                entry.source !== 'manual'
            ) {
                return entry.source
            }

            const template = procedureSources.get(entry.procedure_id ?? -1)
            const text = entry.text.trim()
            const patientText = template?.patient_template_text?.trim() ?? ''
            const defaultText = template?.procedure_default_text?.trim() ?? ''

            if (patientText && text === patientText) {
                return 'patient_template'
            }

            if (defaultText && text === defaultText) {
                return 'procedure_default'
            }

            return entry.source
        }

        return {
            patient: {
                id: raw.patient.id,
                full_name: raw.patient.name,
                dekurz_number: raw.patient.dekurz_number,
            },
            month: {
                key: raw.month,
                start: `${raw.month}-01`,
                end: `${raw.month}-01`,
            },
            procedures: raw.procedures.map(procedure => ({
                id: procedure.procedure_id,
                code: procedure.code,
                name: procedure.description ?? '',
                scoring_rows_count: procedure.scoring_rows_count,
                dates: procedure.dates,
                effective_text: procedure.template.text,
                effective_text_source: procedure.template.source,
                patient_template_text: procedure.template.patient_template_text,
                procedure_default_text: procedure.template.procedure_default_text,
            })),
            custom_tracks: raw.custom_tracks.map(track => ({
                id: track.id,
                name: track.name,
                current_text: track.current_text ?? '',
                distribution: track.distribution,
                selected_dates: track.selected_dates ?? [],
            })),
            days: raw.days.map(day => ({
                id: day.id,
                date: day.date,
                entries: day.entries.map(entry => ({
                    ...entry,
                    source: resolvedEntrySource(entry),
                    is_excluded: Boolean(entry.is_excluded),
                })),
            })),
            existing_documents: raw.existing_documents ?? [],
            supporting_documents: supportSelections.map(({ key, label, selection }) => ({
                key,
                label,
                status: selection.default ? 'found' : 'missing',
                document: normalizeDocument(selection.default),
                candidates: selection.candidates.map(document => ({
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
                      dekurz_number: raw.draft.dekurz_number,
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
            if (builder.value.draft?.dekurz_number) {
                dekurzNumber.value = String(builder.value.draft.dekurz_number)
            } else if (builder.value.patient.dekurz_number) {
                dekurzNumber.value = String(builder.value.patient.dekurz_number)
            }
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
        initializing.value = true
        draftInitializationError.value = ''
        try {
            const response = await api.post(`/v1/patients/${patientId.value}/dekurz-drafts`, {
                month: month.value,
            })
            builder.value = normalize(response.data.data as DekurzRawBuilderPayload)
            if (builder.value.draft?.dekurz_number) {
                dekurzNumber.value = String(builder.value.draft.dekurz_number)
            } else if (builder.value.patient.dekurz_number) {
                dekurzNumber.value = String(builder.value.patient.dekurz_number)
            }
            return true
        } catch (errorValue) {
            const response = (errorValue as {
                response?: {
                    data?: {
                        errors?: Record<string, string[]>
                    }
                }
            }).response
            const validationMessage = response?.data?.errors
                ? Object.values(response.data.errors).flat()[0]
                : undefined
            draftInitializationError.value = validationMessage
                ?? messageFrom(errorValue, 'Skontrolujte podklady a skúste to znova.')
            toast.add({
                severity: 'error',
                summary: 'Koncept sa nepodarilo vytvoriť',
                detail: draftInitializationError.value,
                life: 5000,
            })
            return false
        } finally {
            initializing.value = false
        }
    }

    async function saveTemplate(procedure: DekurzProcedure) {
        if (!procedure.effective_text.trim()) return resetTemplate(procedure)
        const key = `template-${procedure.id}`
        saveStates.value[key] = 'saving'
        return track(
            api.put(`/v1/patients/${patientId.value}/dekurz-templates/${procedure.id}`, {
                text: procedure.effective_text,
            }).then(() => {
                procedure.patient_template_text = procedure.effective_text
                procedure.effective_text_source = 'patient_template'
                saveStates.value[key] = 'saved'
            }).catch(errorValue => {
                saveStates.value[key] = 'error'
                throw errorValue
            }),
        )
    }

    async function resetTemplate(procedure: DekurzProcedure) {
        const key = `template-${procedure.id}`
        saveStates.value[key] = 'saving'
        return track(
            api.delete(`/v1/patients/${patientId.value}/dekurz-templates/${procedure.id}`)
                .then(async () => {
                    await load()
                    saveStates.value[key] = 'saved'
                })
                .catch(errorValue => {
                    saveStates.value[key] = 'error'
                    throw errorValue
                }),
        )
    }

    async function createCustomTrack(name: string, date: string, text = '') {
        const response = await api.post(`/v1/patients/${patientId.value}/dekurz-custom-tracks`, {
            month: month.value,
            name,
            current_text: text,
            distribution: 'selected_days',
            selected_dates: [date],
        })

        // Materialize the new section into the existing month draft immediately.
        await initializeDraft()

        return response.data.data
    }

    async function saveCustomTrack(customTrack: DekurzCustomTrack) {
        const key = `custom-track-${customTrack.id}`
        saveStates.value[key] = 'saving'
        return track(
            api.patch(`/v1/patients/${patientId.value}/dekurz-custom-tracks/${customTrack.id}`, {
                month: month.value,
                name: customTrack.name,
                current_text: customTrack.current_text,
                distribution: customTrack.distribution,
                selected_dates: customTrack.selected_dates,
            }).then(() => {
                saveStates.value[key] = 'saved'
            }).catch(errorValue => {
                saveStates.value[key] = 'error'
                throw errorValue
            }),
        )
    }

    async function deleteCustomTrack(customTrack: DekurzCustomTrack) {
        await api.delete(`/v1/patients/${patientId.value}/dekurz-custom-tracks/${customTrack.id}`, {
            params: { month: month.value },
        })
        await load()
    }

    async function saveEntry(entry: DekurzEntry) {
        if (builder.value?.draft?.status === 'completed') {
            const reopened = await initializeDraft()
            if (!reopened) return
        }

        if (!draftId.value || !entry.id) return
        const key = `entry-${entry.id}`
        saveStates.value[key] = 'saving'
        return track(
            api.patch(`/v1/patients/${patientId.value}/dekurz-drafts/${draftId.value}/entries/${entry.id}`, {
                text: entry.text,
            }).then(response => {
                const saved = response.data?.data

                if (saved) {
                    entry.text = saved.text ?? entry.text
                    entry.source = saved.source ?? entry.source
                    entry.source_entry_id = saved.source_entry_id ?? null
                    entry.edited_at = saved.edited_at ?? entry.edited_at
                    entry.is_excluded = Boolean(saved.is_excluded ?? entry.is_excluded)
                }

                saveStates.value[key] = 'saved'
            }).catch(errorValue => {
                saveStates.value[key] = 'error'
                throw errorValue
            }),
        )
    }

    async function deleteEntry(entry: DekurzEntry) {
        if (entry.track_type !== 'custom') {
            toast.add({
                severity: 'warn',
                summary: 'Výkon nie je možné odstrániť',
                detail: 'Odstrániť je možné iba vlastné sekcie. Text patriaci k vykázanému výkonu musí zostať v Dekurze.',
                life: 4000,
            })

            return false
        }

        if (builder.value?.draft?.status === 'completed') {
            const reopened = await initializeDraft()
            if (!reopened) return false
        }

        if (!draftId.value || !entry.id) return false

        try {
            await api.delete(
                `/v1/patients/${patientId.value}/dekurz-drafts/${draftId.value}/entries/${entry.id}`,
            )

            entry.is_excluded = true

            toast.add({
                severity: 'success',
                summary: 'Sekcia bola odstránená',
                detail: 'Vlastná sekcia sa už v tomto dni nebude zobrazovať.',
                life: 3000,
            })

            return true
        } catch (errorValue) {
            toast.add({
                severity: 'error',
                summary: 'Sekciu sa nepodarilo odstrániť',
                detail: messageFrom(errorValue, 'Skúste to znova.'),
                life: 4000,
            })

            return false
        }
    }

    async function propagateEntry(request: DekurzPropagationRequest) {
        if (builder.value?.draft?.status === 'completed') {
            const reopened = await initializeDraft()
            if (!reopened) return false
        }

        if (!draftId.value || !request.entry.id) return false
        try {
            const response = await api.post(
                `/v1/patients/${patientId.value}/dekurz-drafts/${draftId.value}/entries/${request.entry.id}/propagate`,
                {
                    text: request.entry.text,
                    mode: request.mode,
                    target_entry_ids: request.target_entry_ids,
                    target_dates: request.target_dates,
                    save_as_patient_template: request.save_as_patient_template,
                },
            )
            // Custom propagation can create new day entries, so reload the
            // builder instead of trying to patch only already-rendered entries.
            await load()
            if (request.save_as_patient_template) {
                if (request.entry.track_type === 'procedure') {
                    const procedure = builder.value?.procedures.find(item => item.id === request.entry.procedure_id)
                    if (procedure) {
                        procedure.effective_text = request.entry.text
                        procedure.patient_template_text = request.entry.text
                        procedure.effective_text_source = 'patient_template'
                    }
                } else {
                    const custom = builder.value?.custom_tracks.find(item => item.id === request.entry.custom_track_id)
                    if (custom) custom.current_text = request.entry.text
                }
            }
            toast.add({
                severity: 'success',
                summary: 'Text bol použitý',
                detail: `Aktualizované záznamy: ${response.data.data.updated_count}`,
                life: 3000,
            })
            return true
        } catch (errorValue) {
            toast.add({
                severity: 'error',
                summary: 'Text sa nepodarilo použiť',
                detail: messageFrom(errorValue, 'Skúste to znova.'),
                life: 4000,
            })
            return false
        }
    }

    async function finalizeDraft(pageCount?: number) {
        if (!builder.value?.draft) return false

        if (builder.value.draft.status === 'completed') {
            const reopened = await initializeDraft()
            if (!reopened) return false
        }

        if (!draftId.value) return false
        finalizing.value = true
        finalizationErrors.value = []
        try {
            const savesSucceeded = await flushPendingSaves()
            if (!savesSucceeded) return false
            const response = await api.post(
                `/v1/patients/${patientId.value}/dekurz-drafts/${draftId.value}/finalize`,
                {
                    page_count: pageCount,
                },
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
            const responseErrors = (errorValue as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors
            finalizationErrors.value = responseErrors
                ? Object.values(responseErrors).flat()
                : [messageFrom(errorValue, 'Dekurz sa nepodarilo finalizovať.')]
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
        draftInitializationError,
        error,
        saveStates,
        load,
        loadMacros,
        initializeDraft,
        saveTemplate,
        resetTemplate,
        createCustomTrack,
        saveCustomTrack,
        deleteCustomTrack,
        saveEntry,
        deleteEntry,
        propagateEntry,
        finalizeDraft,
        flushPendingSaves,
    }
}
