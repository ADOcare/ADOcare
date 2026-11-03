<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'

import api from '@/services/api'
import { fetchBlobUrl } from '@/utils/blobUrl'

import { useDekurzV2 } from '@/composables/useDekurzV2'
import { usePatientStore } from '@/stores/patientStore'

import type {
    DekurzNarrativeSection,
    DekurzSupportingDocument,
} from '@/types/dekurz'

import AppButton from '@/components/AppButton.vue'
import DocumentPreviewViewer from '@/components/DocumentPreviewViewer.vue'
import FormField from '@/components/FormField.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import ReferenceDocumentDrawer from '@/components/ReferenceDocumentDrawer.vue'
import A4DocumentFrame from '@/components/documents/A4DocumentFrame.vue'
import ScanDocumentContent from '@/components/documents/ScanDocumentContent.vue'
import { useDocumentEditMode } from '@/composables/useDocumentEditMode'

import PresetMultiDatePicker from '@/components/Date/PresetMultiDatePicker.vue'
import Popover from 'primevue/popover'
import Textarea from 'primevue/textarea'
import { toApiDate } from '@/utils/dateUtils'

/* -------------------------------------------------------------------------- */
/* Stores                                                                     */
/* -------------------------------------------------------------------------- */

const patientStore =
    usePatientStore()

const router =
    useRouter()

const toast =
    useToast()
const {
    editDocumentId,
    isEditingDocument,
} = useDocumentEditMode()

patientStore.loadFromStorage?.()

const patientId =
    computed(
        () =>
            Number(
                patientStore.current
                    ?.id ??
                    0,
            ),
    )

/* -------------------------------------------------------------------------- */
/* Document identity                                                          */
/* -------------------------------------------------------------------------- */

const initialMonth =
    new Date()

initialMonth.setMonth(
    initialMonth.getMonth() -
        1,
)

const selectedMonth =
    ref<Date>(
        initialMonth,
    )

const editingDocumentMonthKey =
    ref<string | null>(
        null,
    )

const dekurzNumber =
    ref(
        String(
            patientStore.current
                ?.dekurz_number ??
                '',
        ),
    )

const monthKey =
    computed(
        () =>
            `${selectedMonth.value.getFullYear()}-${String(
                selectedMonth.value.getMonth() +
                    1,
            ).padStart(
                2,
                '0',
            )}`,
    )

const patientIdRef =
    computed(
        () =>
            patientId.value,
    )

/* -------------------------------------------------------------------------- */
/* Dekurz                                                                     */
/* -------------------------------------------------------------------------- */

const {
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
    saveSections,
    finalizeDraft,
} = useDekurzV2(
    patientIdRef,
    monthKey,
    dekurzNumber,
)

/* -------------------------------------------------------------------------- */
/* Preview                                                                    */
/* -------------------------------------------------------------------------- */

type TextSection = { id: string; text: string; dates: Date[] }
type SaveState = 'idle' | 'saving' | 'saved' | 'error'

const sections = ref<TextSection[]>([makeSection()])
const sectionErrors = ref<Record<string, string>>({})
const loadingTexts = ref(false)
const patientTextSaveState = ref<SaveState>('idle')
const loadingAiPrefill = ref(false)
const improvingSectionById = ref<Record<string, boolean>>({})
const previousSectionTextById = ref<Record<string, string>>({})
const sectionsBeforeAiPrefill = ref<TextSection[] | null>(null)
let saveTimer: ReturnType<typeof setTimeout> | null = null
let patientTextSaveQueue = Promise.resolve(true)
let draftSectionSaveQueue = Promise.resolve(true)
let pendingDraftSaves = 0
let loadToken = 0
const draftSectionSaving = ref(false)

const hasDraft = computed(() => !!builder.value?.draft)
const sectionSaveState = computed<SaveState>(() => {
    const draftState = builder.value?.draft ? saveStates.value[`sections-${builder.value.draft.id}`] : undefined
    if (patientTextSaveState.value === 'saving' || draftSectionSaving.value) return 'saving'
    if (patientTextSaveState.value === 'error' || draftState === 'error') return 'error'
    if (patientTextSaveState.value === 'saved' || draftState === 'saved') return 'saved'
    return 'idle'
})
const sectionsLocked = computed(() => finalizing.value || loadingTexts.value || loadingAiPrefill.value)
const monthStart = computed(() => new Date(selectedMonth.value.getFullYear(), selectedMonth.value.getMonth(), 1))
const monthEnd = computed(() => new Date(selectedMonth.value.getFullYear(), selectedMonth.value.getMonth() + 1, 0))
const maxSectionDate = computed(() => {
    const death = patientStore.current?.death_date
    const date = death ? new Date(`${String(death).slice(0, 10)}T00:00:00`) : null
    return date && date < monthEnd.value ? date : monthEnd.value
})
const disabledDates = computed(() => {
    const available = new Set(builder.value?.days.map(day => day.date) ?? [])
    const dates: Date[] = []
    for (let day = 1; day <= monthEnd.value.getDate(); day++) {
        const date = new Date(monthStart.value.getFullYear(), monthStart.value.getMonth(), day)
        const apiDate = toApiDate(date)
        if (!apiDate || !available.has(apiDate)) dates.push(date)
    }
    return dates
})

function makeSection(text = '', dates: string[] = []): TextSection {
    return { id: crypto.randomUUID(), text, dates: dates.map(date => new Date(`${date}T00:00:00`)) }
}

function sectionPayload(): DekurzNarrativeSection[] {
    return sections.value.map(section => ({
        text: section.text,
        dates: section.dates.map(date => toApiDate(date)).filter((date): date is string => date !== null),
    }))
}

function errorMessage(value: unknown, fallback: string): string {
    const response = (value as { response?: { data?: { message?: string } } })?.response
    return response?.data?.message || (value instanceof Error ? value.message : '') || fallback
}

async function fetchPatientTexts(id: number): Promise<string[] | null> {
    try {
        const response = await api.get<{ data: { texts: string[] } }>(`/v1/patients/${id}/dekurz-texts`)
        return response.data.data.texts
    } catch (loadError) {
        toast.add({
            severity: 'error',
            summary: 'Texty pacienta sa nepodarilo načítať',
            detail: errorMessage(loadError, 'Skúste načítať Dekurz znova.'),
            life: 5000,
        })
        return null
    }
}

// A month with its own written texts keeps them; otherwise the patient's latest texts are used
// and only the month's selected days are taken from the draft.
function applySections(patientTexts: string[] | null) {
    const saved = builder.value?.draft?.narrative_sections ?? []
    const draftHasText = saved.some(section => section.text.trim() !== '')
    if (draftHasText) {
        sections.value = saved.map(section => makeSection(section.text, section.dates))
    } else if (patientTexts?.length) {
        sections.value = patientTexts.map((text, index) => makeSection(text, saved[index]?.dates ?? []))
    } else if (saved.length) {
        sections.value = saved.map(section => makeSection(section.text, section.dates))
    } else {
        sections.value = [makeSection()]
    }
    sectionErrors.value = {}
    previousSectionTextById.value = {}
    sectionsBeforeAiPrefill.value = null
}

async function syncEditModeWithLoadedDraft() {
    const finalDocumentId =
        Number(
            builder.value
                ?.draft
                ?.final_document_id ??
                0,
        )

    if (
        !Number.isInteger(
            finalDocumentId,
        ) ||
        finalDocumentId <= 0
    ) {
        return
    }

    editingDocumentMonthKey.value =
        monthKey.value

    if (
        editDocumentId.value ===
        finalDocumentId
    ) {
        return
    }

    await router.replace({
        query: {
            ...router.currentRoute.value.query,

            editDocumentId:
                String(
                    finalDocumentId,
                ),
        },
    })
}

function changedSections() {
    sectionErrors.value = {}
    schedulePreview()
    if (saveTimer) clearTimeout(saveTimer)
    saveTimer = setTimeout(() => { void persistSections() }, 800)
}

function savePatientTexts(id: number, texts: string[]): Promise<boolean> {
    patientTextSaveState.value = 'saving'
    patientTextSaveQueue = patientTextSaveQueue.then(async () => {
        try {
            await api.put(`/v1/patients/${id}/dekurz-texts`, { texts })
            if (id === patientId.value) patientTextSaveState.value = 'saved'
            return true
        } catch (saveError) {
            patientTextSaveState.value = 'error'
            toast.add({
                severity: 'error',
                summary: 'Texty sa nepodarilo uložiť',
                detail: errorMessage(saveError, 'Skúste uložiť texty znova.'),
                life: 5000,
            })
            return false
        }
    })
    return patientTextSaveQueue
}

function saveDraftSections(payload: DekurzNarrativeSection[]): Promise<boolean> {
    const draft = builder.value?.draft
    if (!draft) return Promise.resolve(true)
    const context = {
        patientId: patientId.value,
        draftId: draft.id,
        month: monthKey.value,
        completed: draft.status === 'completed',
    }
    pendingDraftSaves++
    draftSectionSaving.value = true
    draftSectionSaveQueue = draftSectionSaveQueue
        .then(() => saveSections(payload, context))
        .finally(() => {
            pendingDraftSaves--
            if (!pendingDraftSaves) draftSectionSaving.value = false
        })
    return draftSectionSaveQueue
}

async function persistSections(): Promise<boolean> {
    if (saveTimer) clearTimeout(saveTimer)
    saveTimer = null
    if (!patientId.value) return false
    const payload = sectionPayload()
    const results = await Promise.all([
        ...(isEditingDocument.value
            ? []
            : [savePatientTexts(patientId.value, payload.map(section => section.text))]),
        saveDraftSections(payload),
    ])
    return results.every(Boolean)
}

async function flushSectionSaves() {
    if (saveTimer) await persistSections()
    await Promise.all([patientTextSaveQueue, draftSectionSaveQueue])
}

function addSection(afterId?: string) {
    const section = makeSection()
    const index = sections.value.findIndex(item => item.id === afterId)
    sections.value.splice(index === -1 ? sections.value.length : index + 1, 0, section)
    changedSections()
    requestAnimationFrame(() => {
        const textarea = sectionTextarea(section.id)
        textarea?.scrollIntoView({ behavior: 'smooth', block: 'center' })
        textarea?.focus({ preventScroll: true })
    })
}

function removeSection(id: string) {
    sections.value = sections.value.filter(section => section.id !== id)
    if (!sections.value.length) sections.value.push(makeSection())
    changedSections()
}

const macroPopover = ref<InstanceType<typeof Popover> | null>(null)
const macroSectionId = ref<string | null>(null)
const selectionBySection: Record<string, { start: number; end: number }> = {}

function sectionTextarea(id: string): HTMLTextAreaElement | null {
    return document.querySelector<HTMLTextAreaElement>(`textarea[data-section-id="${id}"]`)
}

function rememberSelection(section: TextSection, event?: Event) {
    const textarea = event?.target instanceof HTMLTextAreaElement ? event.target : sectionTextarea(section.id)
    const start = textarea?.selectionStart ?? section.text.length
    selectionBySection[section.id] = { start, end: textarea?.selectionEnd ?? start }
}

function toggleMacros(section: TextSection, event: Event) {
    if (!selectionBySection[section.id]) rememberSelection(section)
    macroSectionId.value = section.id
    macroPopover.value?.toggle(event)
}

// Insert at the remembered caret (replacing a selection) and keep the text on its own line.
function insertMacro(body: string) {
    const section = sections.value.find(item => item.id === macroSectionId.value)
    macroPopover.value?.hide()
    if (!section) return
    const text = section.text
    const selection = selectionBySection[section.id] ?? { start: text.length, end: text.length }
    const start = Math.min(selection.start, text.length)
    const end = Math.min(Math.max(selection.end, start), text.length)
    const before = text.slice(0, start)
    const after = text.slice(end)
    const prefix = before && !/[\n ]$/.test(before) ? '\n' : ''
    const suffix = after && !/^[\n ]/.test(after) ? '\n' : ''
    const inserted = `${prefix}${body}${suffix}`
    const caret = before.length + inserted.length
    section.text = `${before}${inserted}${after}`
    selectionBySection[section.id] = { start: caret, end: caret }
    changedSections()
    requestAnimationFrame(() => {
        const textarea = sectionTextarea(section.id)
        textarea?.focus()
        textarea?.setSelectionRange(caret, caret)
    })
}

async function prefillWithAi() {
    if (!patientId.value) return
    const id = patientId.value
    loadingAiPrefill.value = true
    try {
        const response = await api.post(`/v1/patients/${id}/dekurz/ai-prefill`)
        if (id !== patientId.value) return
        const texts = ((response.data?.data?.sections ?? []) as { text?: string }[])
            .map(section => String(section?.text ?? '').trim())
            .filter(Boolean)
        if (!texts.length) {
            toast.add({ severity: 'warn', summary: 'Bez výsledku', detail: 'AI nevrátila použiteľné texty.', life: 3000 })
            return
        }
        const previous = sections.value
        const fallbackDates = previous.find(section => section.dates.length)?.dates ?? []
        sectionsBeforeAiPrefill.value = previous
        sections.value = texts.map((text, index) => ({
            id: crypto.randomUUID(),
            text,
            dates: [...(previous[index]?.dates.length ? previous[index].dates : fallbackDates)],
        }))
        previousSectionTextById.value = {}
        changedSections()
        toast.add({
            severity: 'success',
            summary: 'Hotovo',
            detail: 'Texty boli predvyplnené z posledného návrhu. Pôvodné texty môžete obnoviť.',
            life: 3500,
        })
    } catch (prefillError) {
        toast.add({
            severity: 'error',
            summary: 'Chyba AI',
            detail: errorMessage(prefillError, 'Nepodarilo sa načítať AI návrh textov dekurzu.'),
            life: 5000,
        })
    } finally {
        loadingAiPrefill.value = false
    }
}

function restoreSectionsBeforeAiPrefill() {
    if (!sectionsBeforeAiPrefill.value) return
    sections.value = sectionsBeforeAiPrefill.value
    sectionsBeforeAiPrefill.value = null
    changedSections()
}

async function improveSectionWithAi(section: TextSection) {
    if (!patientId.value || !section.text.trim()) return
    const original = section.text
    improvingSectionById.value = { ...improvingSectionById.value, [section.id]: true }
    try {
        const response = await api.post(`/v1/patients/${patientId.value}/dekurz/ai-improve-text`, { text: original })
        const improved = String(response.data?.data?.improved_text ?? '').trim()
        if (!improved) {
            toast.add({ severity: 'warn', summary: 'Bez výsledku', detail: 'AI nevrátila vylepšený text.', life: 3000 })
            return
        }
        if (section.text !== original) return
        previousSectionTextById.value = { ...previousSectionTextById.value, [section.id]: original }
        section.text = improved
        changedSections()
    } catch (improveError) {
        toast.add({
            severity: 'error',
            summary: 'Chyba AI',
            detail: errorMessage(improveError, 'Nepodarilo sa vylepšiť text pomocou AI.'),
            life: 3500,
        })
    } finally {
        improvingSectionById.value = { ...improvingSectionById.value, [section.id]: false }
    }
}

function restoreSectionText(section: TextSection) {
    const original = previousSectionTextById.value[section.id]
    if (original === undefined) return
    section.text = original
    const rest = { ...previousSectionTextById.value }
    delete rest[section.id]
    previousSectionTextById.value = rest
    changedSections()
}

const previewHtml =
    ref('')

const isPreviewLoading =
    ref(false)

const previewError =
    ref<string | null>(
        null,
    )

const previewPageCount =
    ref(1)

const isPreviewPaginationReady =
    ref(false)

let previewTimer:
    ReturnType<typeof setTimeout> |
    null = null

let previewRequestId =
    0

async function loadPreview() {
    if (
        !patientId.value ||
        !monthKey.value
    ) {
        previewRequestId++

        previewHtml.value =
            ''

        previewError.value =
            null

        isPreviewLoading.value =
            false

        return
    }

    const requestId =
        ++previewRequestId

    isPreviewLoading.value =
        true

    isPreviewPaginationReady.value =
        false

    previewError.value =
        null

    try {
        const response =
            await api.post(
                '/v1/dekurz/draft-preview',
                {
                    patient_id:
                        patientId.value,

                    month:
                        monthKey.value,

                    dekurz_number:
                        dekurzNumber.value ||
                        undefined,

                    sections: sectionPayload(),
                },

                {
                    headers: {
                        Accept:
                            'text/html',
                    },

                    responseType:
                        'text',
                },
            )

        if (
            requestId !==
            previewRequestId
        ) {
            return
        }

        previewHtml.value =
            String(
                response.data ??
                    '',
            )
    } catch (previewLoadError) {
        if (
            requestId !==
            previewRequestId
        ) {
            return
        }

        console.error(
            'Failed to load Dekurz preview:',
            previewLoadError,
        )

        previewHtml.value =
            ''

        previewError.value =
            'Náhľad Dekurzu sa nepodarilo načítať.'
    } finally {
        if (
            requestId ===
            previewRequestId
        ) {
            isPreviewLoading.value =
                false
        }
    }
}

function schedulePreview() {
    if (previewTimer) {
        clearTimeout(
            previewTimer,
        )
    }

    previewTimer =
        setTimeout(
            () => {
                void loadPreview()
            },
            350,
        )
}

function handlePreviewPageCountChange(
    pageCount: number,
) {
    previewPageCount.value =
        pageCount

    isPreviewPaginationReady.value =
        true
}

/* -------------------------------------------------------------------------- */
/* Supporting document drawers                                                */
/* -------------------------------------------------------------------------- */

const supportingDocumentPreviewUrls =
    ref<
        Record<
            string,
            string
        >
    >({})

const supportingDocumentLoading =
    ref<
        Record<
            string,
            boolean
        >
    >({})

const supportingDocuments =
    computed(
        () =>
            builder.value
                ?.supporting_documents ??
            [],
    )

function isScanDocument(
    document: DekurzSupportingDocument,
) {
    return (
        document.key === 'finding' ||
        document.key === 'previous_finding'
    )
}

function revokeSupportingDocumentPreviewUrls() {
    for (
        const url
        of Object.values(
            supportingDocumentPreviewUrls.value,
        )
    ) {
        if (
            url.startsWith(
                'blob:',
            )
        ) {
            URL.revokeObjectURL(
                url,
            )
        }
    }

    supportingDocumentPreviewUrls.value =
        {}
}

async function loadSupportingDocumentPreviews(
    documents: DekurzSupportingDocument[],
) {
    revokeSupportingDocumentPreviewUrls()

    const nextUrls:
        Record<
            string,
            string
        > = {}

    await Promise.all(
        documents.map(
            async document => {
                if (
                    isScanDocument(document) ||
                    !document.document
                        ?.preview_url
                ) {
                    return
                }

                supportingDocumentLoading.value[
                    document.key
                ] = true

                try {
                    nextUrls[
                        document.key
                    ] =
                        await fetchBlobUrl(
                            document.document
                                .preview_url,
                        )
                } catch (documentPreviewError) {
                    console.error(
                        `Failed to load supporting document ${document.key}:`,
                        documentPreviewError,
                    )
                } finally {
                    supportingDocumentLoading.value[
                        document.key
                    ] = false
                }
            },
        ),
    )

    supportingDocumentPreviewUrls.value =
        nextUrls
}

/* -------------------------------------------------------------------------- */
/* Loading / initialization                                                   */
/* -------------------------------------------------------------------------- */

async function loadAndInitialize() {
    const token = ++loadToken
    await flushSectionSaves()
    if (token !== loadToken) return

    const id = patientId.value
    if (!id || !monthKey.value) {
        loadingTexts.value = false
        await load()
        return
    }

    loadingTexts.value = true
    const textsRequest = fetchPatientTexts(id).then(texts => {
        if (token === loadToken) applySections(texts)
        return texts
    })

    try {
        await load()

        // Create the one logical patient+month Dekurz automatically only when it
        // does not exist yet. Merely viewing a completed month must not reopen it.
        if (token === loadToken && builder.value?.draft?.status !== 'completed') {
            await initializeDraft()
        }

        const patientTexts = await textsRequest
        if (token !== loadToken) return
        applySections(patientTexts)
        await syncEditModeWithLoadedDraft()
        patientTextSaveState.value = 'idle'
        schedulePreview()
    } finally {
        if (token === loadToken) loadingTexts.value = false
    }
}

/* -------------------------------------------------------------------------- */
/* Mutations                                                                  */
/* -------------------------------------------------------------------------- */

async function handleFinalize() {
    const errors: Record<string, string> = {}
    for (const section of sections.value) {
        if (!section.text.trim()) errors[`${section.id}-text`] = 'Text sekcie je povinný.'
        if (section.text.includes('[TEXT]')) errors[`${section.id}-text`] = 'Doplňte hodnotu [TEXT].'
        if (!section.dates.length) errors[`${section.id}-dates`] = 'Vyberte aspoň jeden dátum.'
    }
    sectionErrors.value = errors
    if (Object.keys(errors).length || !await persistSections()) return
    const finalized = await finalizeDraft(
        previewPageCount.value,
    )

    if (!finalized) {
        toast.add({
            severity:
                'error',

            summary:
                'Dekurz zatiaľ nie je možné vygenerovať.',

            detail:
                finalizationErrors.value
                    .slice(0, 3)
                    .join(' ') ||
                'Skontrolujte zadané údaje a skúste to znova.',

            life:
                6000,
        })

        return
    }

    const documentId =
        builder.value
            ?.draft
            ?.final_document_id

    if (!documentId) {
        return
    }

    await router.push({
        name:
            'documents-dekurz',

        params: {
            documentId,
        },
    })
}

/* -------------------------------------------------------------------------- */
/* Reload                                                                     */
/* -------------------------------------------------------------------------- */

async function reloadDekurz() {
    await loadAndInitialize()
}

/* -------------------------------------------------------------------------- */
/* Watchers                                                                   */
/* -------------------------------------------------------------------------- */

watch(
    monthKey,
    async newMonthKey => {
        if (
            isEditingDocument.value &&
            editingDocumentMonthKey.value &&
            newMonthKey !== editingDocumentMonthKey.value
        ) {
            const query = {
                ...router.currentRoute.value.query,
            }

            delete query.editDocumentId

            await router.replace({
                query,
            })

            editingDocumentMonthKey.value =
                null
        }

        await loadAndInitialize()
    },
)

watch(
    patientIdRef,
    async () => {
        dekurzNumber.value =
            String(
                patientStore.current
                    ?.dekurz_number ??
                    '',
            )

        await loadAndInitialize()
    },
)

watch(
    dekurzNumber,
    () => {
        schedulePreview()
    },
)

watch(
    supportingDocuments,
    documents => {
        void loadSupportingDocumentPreviews(
            documents,
        )
    },
    {
        deep:
            true,

        immediate:
            true,
    },
)

/* -------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* -------------------------------------------------------------------------- */

onMounted(
    async () => {
        let selectedDocumentChangedMonth = false

        if (editDocumentId.value) {
            const previousMonth = monthKey.value
            const response = await api.get(`/v1/dekurz/${editDocumentId.value}`)
            const documentMonth = String(
                response.data?.data?.dekurz_data?.month ??
                response.data?.data?.dekurz_data?.period ??
                '',
            ).slice(0, 7)

            if (/^\d{4}-\d{2}$/.test(documentMonth)) {
                editingDocumentMonthKey.value =
                    documentMonth

                const [year, month] = documentMonth.split('-').map(Number)
                selectedMonth.value = new Date(year, month - 1, 1)
                selectedDocumentChangedMonth = previousMonth !== monthKey.value
            }
        }

        await loadMacros()

        if (!selectedDocumentChangedMonth) {
            await loadAndInitialize()
        }
    },
)

onBeforeUnmount(() => {
    if (saveTimer) {
        clearTimeout(saveTimer)
        void persistSections()
    }
    if (previewTimer) {
        clearTimeout(
            previewTimer,
        )
    }

    previewRequestId++

    revokeSupportingDocumentPreviewUrls()
})
</script>

<template>
    <!-- ================================================================ -->
    <!-- SUPPORTING DOCUMENT DRAWERS                                      -->
    <!-- ================================================================ -->

    <ReferenceDocumentDrawer
        v-for="document in supportingDocuments"
        :key="document.key"
        :title="document.label"
        :handle-label="document.label"
        :available="!!document.document"
    >
        <template #default="{ setAspectRatio }">
            <ScanDocumentContent
                v-if="isScanDocument(document) && document.document"
                :document-id="document.document.id"
                bare
                @aspect-ratio-change="setAspectRatio"
            />

            <div
                v-else-if="supportingDocumentLoading[document.key]"
                class="
                    flex
                    h-full
                    items-center
                    justify-center
                    gap-2
                    bg-white
                    text-normal
                    text-accent
                "
            >
                <i
                    class="
                        bi
                        bi-arrow-repeat
                        animate-spin
                    "
                    aria-hidden="true"
                />

                Načítavam dokument…
            </div>

            <A4DocumentFrame
                v-else-if="supportingDocumentPreviewUrls[document.key]"
                :src="supportingDocumentPreviewUrls[document.key]!"
                :title="document.label"
            />

            <div
                v-else
                class="
                    flex
                    h-full
                    items-center
                    justify-center
                    bg-white
                    p-8
                    text-center
                    text-normal
                    text-lightgrey
                "
            >
                Náhľad dokumentu nie je dostupný.
            </div>
        </template>
    </ReferenceDocumentDrawer>

    <!-- ================================================================ -->
    <!-- PAGE                                                             -->
    <!-- ================================================================ -->

    <div
        class="
            -mx-8
            -mb-8
            -mt-8
            h-[calc(100%+4rem)]
            min-h-0
            w-[calc(100%+4rem)]
            overflow-hidden
        "
    >
        <div
            class="
                grid
                h-full
                min-h-0
                grid-cols-2
                gap-6
                p-8
            "
        >
            <!-- ======================================================== -->
            <!-- LEFT: FORM                                               -->
            <!-- ======================================================== -->

            <aside
                class="
                    relative
                    h-full
                    min-h-0
                    overflow-hidden
                "
            >
                <LoadingOverlay
                    :show="
                        loading ||
                        initializing
                    "
                    :text="
                        initializing
                            ? 'Pripravujem koncept…'
                            : 'Načítavam Dekurz…'
                    "
                />

                <section
                    class="
                        flex
                        min-h-0
                        flex-col
                        overflow-hidden
                        rounded-md
                        bg-tag3
                        max-h-full

                    "
                >
                    <div
                        class="
                            min-h-0
                            flex-1
                            overflow-y-auto
                            p-8
                        "
                    >
                        <div
                            v-if="!patientId"
                            class="
                                flex
                                min-h-full
                                items-center
                                justify-center
                                text-center
                                text-normal
                                text-lightgrey
                            "
                        >
                            Najprv vyberte pacienta.
                        </div>

                        <div
                            v-else-if="error"
                            class="
                                flex
                                flex-col
                                items-start
                                gap-3
                            "
                        >
                            <p
                                class="
                                    text-normal
                                    text-danger
                                "
                            >
                                {{ error }}
                            </p>

                            <AppButton
                                type="button"
                                variant="accent"
                                icon="bi bi-arrow-clockwise"
                                label="Skúsiť znova"
                                @click="
                                    reloadDekurz
                                "
                            />
                        </div>

                        <div
                            v-else
                            class="
                                flex
                                flex-col
                                gap-8
                            "
                        >
                            <div class="grid grid-cols-12 gap-4">
                                <FormField label="Mesiac dekurzu" class="col-span-6">
                                    <DatePicker
                                        v-model="selectedMonth"
                                        view="month"
                                        dateFormat="mm/yy"
                                        :showIcon="false"
                                        class="w-full"
                                        inputClass="w-full! cursor-pointer! border-none!"
                                    />
                                </FormField>

                                <FormField label="Číslo dekurzu" class="col-span-6">
                                    <InputText
                                        v-model="dekurzNumber"
                                        class="w-full cursor-default border-0! bg-tag3!"
                                    />
                                </FormField>
                            </div>

                            <div
                                v-if="!builder?.draft"
                                class="flex items-start gap-3 rounded-md bg-danger p-4"
                            >
                                <i class="bi bi-clock-history mt-0.5 text-white" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-normal font-medium text-white">
                                        {{ initializing ? 'Pripravujem Dekurz…' : 'Dni Dekurzu zatiaľ nie je možné vybrať.' }}
                                    </p>
                                    <p class="mt-1 text-mini text-white">
                                        {{ draftInitializationError || 'Kontrolujem vypočítané časy návštev.' }}
                                        Texty môžete upravovať, ukladajú sa k pacientovi.
                                    </p>
                                </div>
                            </div>

                            <section v-for="(section) in sections" :key="section.id"
                                class="flex flex-col gap-4 rounded-md bg-white p-4">
                                <FormField label="Text dekurzu" :error="sectionErrors[`${section.id}-text`]">
                                    <div class="relative">
                                        <Textarea v-model="section.text" rows="8" autoResize class="w-full pb-12!"
                                            :data-section-id="section.id"
                                            :disabled="sectionsLocked || !!improvingSectionById[section.id]"
                                            :invalid="!!sectionErrors[`${section.id}-text`]"
                                            @update:modelValue="changedSections"
                                            @click="rememberSelection(section, $event)"
                                            @keyup="rememberSelection(section, $event)"
                                            @select="rememberSelection(section, $event)"
                                            @focus="rememberSelection(section, $event)" />

                                        <div class="absolute bottom-3 left-2 flex items-center gap-2">
                                            <AppButton 
                                                type="button"
                                                aria-label="Pridať makro" title="Pridať makro"
                                                :disabled="sectionsLocked || !!improvingSectionById[section.id]"
                                                @mousedown.prevent
                                                @click="toggleMacros(section, $event)"
                                                icon="bi bi-plus-lg"
                                                variant="accent"
                                                label="Makro"
                                                :expand-on-hover="true"
                                            />
                                            <AppButton
                                                type="button"
                                                variant="accent"
                                                icon="bi bi-magic"
                                                :expand-on-hover="true"
                                                label="Vylepšiť text"
                                                loadingLabel="Vylepšujem…"
                                                :loading="!!improvingSectionById[section.id]"
                                                :disabled="sectionsLocked || !section.text.trim()"
                                                @click="improveSectionWithAi(section)" />
                                            
                                            <AppButton
                                                type="button"
                                                variant="accent"
                                                icon="bi bi-arrow-return-left"
                                                :expand-on-hover="true"
                                                label="Obnoviť pôvodný text"
                                                :disabled="sectionsLocked || previousSectionTextById[section.id] === undefined"
                                                @click="restoreSectionText(section)" />
                                        </div>
                                    </div>
                                </FormField>
                                <FormField label="Dátumy" :error="sectionErrors[`${section.id}-dates`] " required>
                                    <PresetMultiDatePicker v-model="section.dates"
                                        :disabled="sectionsLocked || !hasDraft"
                                        :viewDate="monthStart" :minDate="monthStart" :maxDate="maxSectionDate"
                                        :disabledDates="disabledDates" :showOtherMonths="false"
                                        :invalid="!!sectionErrors[`${section.id}-dates`]"
                                        input-class="border-darkgrey!"
                                        @update:modelValue="changedSections" />
                                </FormField>

                                <div class="flex items-center gap-2 justify-end">
                                    <AppButton
                                        type="button"
                                        variant="danger"
                                        icon="bi bi-eraser"
                                        :expand-on-hover="true"
                                        label="Odstrániť sekciu" :disabled="sectionsLocked"
                                        @click="removeSection(section.id)"
                                    />

                                    <AppButton 
                                        type="button" 
                                        variant="accent" 
                                        icon="bi bi-plus-lg" 
                                        :expand-on-hover="true"
                                        label="Pridať sekciu"
                                        :disabled="sectionsLocked" 
                                        @click="addSection(section.id)"
                                    />
                                </div>
                            </section>

                            <Popover ref="macroPopover" @hide="macroSectionId = null">
                                <div class="flex w-70 max-w-[calc(100vw-2rem)] flex-col gap-2">
                                    <div v-if="!macros.length" class="px-2 py-3 text-mini text-darkgrey/60">
                                        Nemáte žiadne makrá. Vytvorte si ich v
                                        <a href="/settings/macros" target="_blank" class="underline">nastaveniach</a>.
                                    </div>
                                    <div v-else class="flex max-h-80 flex-col gap-1 overflow-y-auto">
                                        <button v-for="macro in macros" :key="macro.key" type="button"
                                            class="flex w-full cursor-pointer flex-col gap-1 rounded-md px-3 py-2 text-left transition-colors hover:bg-tag3"
                                            @click="insertMacro(macro.body)">
                                            <span class="text-normal font-medium text-darkgrey">{{ macro.title }}</span>
                                            <span class="line-clamp-2 text-mini text-darkgrey/60">{{ macro.body }}</span>
                                        </button>
                                    </div>
                                </div>
                            </Popover>

                            <div class="flex items-center justify-end gap-2">
                                <AppButton 
                                    type="button" 
                                    variant="accent"
                                    icon="bi bi-arrow-return-left" 
                                    label="Obnoviť texty"
                                    :disabled="sectionsLocked || !sectionsBeforeAiPrefill" 
                                    @click="restoreSectionsBeforeAiPrefill" 
                                    :expand-on-hover="true"
                                />

                                <AppButton
                                    type="button"
                                    variant="accent"
                                    icon="bi bi-magic"
                                    label="Vyplniť pomocou AI"
                                    :loading="loadingAiPrefill"
                                    :disabled="sectionsLocked && !loadingAiPrefill"
                                    :expand-on-hover="true"
                                    @click="prefillWithAi"
                                />

                                <AppButton
                                    type="button"
                                    variant="accent"
                                    :label="builder?.draft?.final_document_id ? 'Znovu vygenerovať dokument' : 'Generovať dokument'"
                                    :loading="finalizing"
                                    :disabled="!hasDraft || sectionsLocked || isPreviewLoading || !isPreviewPaginationReady || sectionSaveState === 'saving'"
                                    @click="handleFinalize"
                                />
                            </div>
                        </div>
                    </div>
                </section>
            </aside>

            <!-- ======================================================== -->
            <!-- RIGHT: PREVIEW                                           -->
            <!-- ======================================================== -->

            <section
                class="
                    h-full
                    min-h-0
                    min-w-0
                    overflow-hidden
                    rounded-md
                "
                aria-label="Náhľad dokumentu"
            >
                <DocumentPreviewViewer
                    :html="previewHtml"
                    :loading="isPreviewLoading"
                    :error="previewError"
                    title="Náhľad Dekurzu"
                    @page-count-change="handlePreviewPageCountChange"
                />
            </section>
        </div>
    </div>

</template>
