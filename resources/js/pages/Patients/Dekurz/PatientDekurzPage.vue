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
import Dialog from 'primevue/dialog'
import Popover from 'primevue/popover'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
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

const selectedCreationMonthKey =
    ref<string | null>(
        null,
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
        () => editingDocumentMonthKey.value ?? selectedCreationMonthKey.value ?? '',
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
type DateCoverage = {
    date: string
    formattedDate: string
    procedures: string[]
    procedureCodes: string[]
    procedureSummary: string
    selected: boolean
}
type DekurzMonthOption = {
    month: string
    label: string
    status: 'issued' | 'ready' | 'timeline_required'
    document_id: number | null
    message: string | null
}
type SnapshotCoverageDay = {
    date: string
    procedures: string[]
    procedureCodes: string[]
}

const sections = ref<TextSection[]>([makeSection()])
const sectionErrors = ref<Record<string, string>>({})
const loadingTexts = ref(false)
const patientTextSaveState = ref<SaveState>('idle')
const snapshotSaveState = ref<SaveState>('idle')
const snapshotEditMode = ref(false)
const snapshotEditingDocumentId = ref<number | null>(null)
const snapshotAvailableDates = ref<string[]>([])
const snapshotCoverageDays = ref<SnapshotCoverageDay[]>([])
const snapshotDekurzNumber = ref<string>('')
const monthOptions = ref<DekurzMonthOption[]>([])
const loadingMonthOptions = ref(false)
const loadingAiPrefill = ref(false)
const loadingSnapshot = ref(false)
const finalizeConfirmationVisible = ref(false)
const pendingDekurzNumber = ref<number | null>(null)
const dateCoverageScroller = ref<HTMLElement | null>(null)
const timelineCalculationMonth = ref<string | null>(null)
const improvingSectionById = ref<Record<string, boolean>>({})
const previousSectionTextById = ref<Record<string, string>>({})
const sectionsBeforeAiPrefill = ref<TextSection[] | null>(null)
let saveTimer: ReturnType<typeof setTimeout> | null = null
let patientTextSaveQueue = Promise.resolve(true)
let draftSectionSaveQueue = Promise.resolve(true)
let pendingDraftSaves = 0
let loadToken = 0
let timelineStatusTimer: ReturnType<typeof setTimeout> | null = null
let timelineStatusAttempts = 0
const TIMELINE_CALC_TOAST_GROUP = 'timeline-calculation-toast'
const draftSectionSaving = ref(false)

const hasDraft = computed(
    () =>
        snapshotEditMode.value ||
        (
            !draftInitializationError.value &&
            (
                !!builder.value?.draft &&
                builder.value.days.length > 0
            )
        ),
)
const hasActiveDekurz = computed(
    () => snapshotEditMode.value || selectedCreationMonthKey.value !== null,
)
const sectionSaveState = computed<SaveState>(() => {
    const draftState = builder.value?.draft ? saveStates.value[`sections-${builder.value.draft.id}`] : undefined
    if (patientTextSaveState.value === 'saving' || snapshotSaveState.value === 'saving' || draftSectionSaving.value) return 'saving'
    if (patientTextSaveState.value === 'error' || snapshotSaveState.value === 'error' || draftState === 'error') return 'error'
    if (patientTextSaveState.value === 'saved' || snapshotSaveState.value === 'saved' || draftState === 'saved') return 'saved'
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
    const snapshotDates = snapshotEditMode.value
        ? snapshotAvailableDates.value
        : []
    const available = new Set([
        ...(!snapshotEditMode.value && !draftInitializationError.value
            ? (builder.value?.days.map(day => day.date) ?? [])
            : []),
        ...snapshotDates,
    ])
    const dates: Date[] = []
    for (let day = 1; day <= monthEnd.value.getDate(); day++) {
        const date = new Date(monthStart.value.getFullYear(), monthStart.value.getMonth(), day)
        const apiDate = toApiDate(date)
        if (!apiDate || !available.has(apiDate)) dates.push(date)
    }
    return dates
})
const selectedSectionDates = computed(() => new Set(
    sections.value.flatMap(section => (
        section.dates
            .map(date => toApiDate(date))
            .filter((date): date is string => date !== null)
    )),
))
const dateCoverage = computed<DateCoverage[]>(() => {
    if (!snapshotEditMode.value && draftInitializationError.value) return []

    const daysByDate = new Map((builder.value?.days ?? []).map(day => [day.date, day]))
    const snapshotDaysByDate = new Map(
        snapshotCoverageDays.value.map(day => [day.date, day]),
    )
    const availableDates = new Set([
        ...(snapshotEditMode.value ? [] : daysByDate.keys()),
        ...(snapshotEditMode.value ? snapshotAvailableDates.value : []),
    ])

    return Array.from(availableDates)
        .sort()
        .map(date => {
            const snapshotDay = snapshotDaysByDate.get(date)
            const entries = daysByDate.get(date)?.entries ?? []
            const procedures = snapshotEditMode.value
                ? (snapshotDay?.procedures ?? [])
                : Array.from(new Set(
                entries
                    .map(entry => {
                        const code = entry.procedure_code?.trim() ?? ''
                        const name = (
                            entry.procedure_name ??
                            entry.track_name
                        ).trim()

                        if (code && name) return `${code} – ${name}`
                        return code || name
                    })
                    .filter(Boolean),
                ))
            const procedureCodes = snapshotEditMode.value
                ? (snapshotDay?.procedureCodes ?? [])
                : Array.from(new Set(
                entries
                    .map(entry => (
                        entry.procedure_code?.trim() ||
                        entry.track_name.trim()
                        ))
                    .filter(Boolean),
            ))

            return {
                date,
                formattedDate: new Intl.DateTimeFormat('sk-SK', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                }).format(new Date(`${date}T00:00:00`)),
                procedures,
                procedureCodes,
                procedureSummary: procedures.join(', '),
                selected: selectedSectionDates.value.has(date),
            }
        })
})
const missingCoverageDates = computed(() => (
    dateCoverage.value.filter(day => !day.selected)
))

function makeSection(text = '', dates: string[] = []): TextSection {
    return { id: crypto.randomUUID(), text, dates: dates.map(date => new Date(`${date}T00:00:00`)) }
}

function sectionPayload(): DekurzNarrativeSection[] {
    return sections.value.map(section => ({
        text: section.text,
        dates: section.dates.map(date => toApiDate(date)).filter((date): date is string => date !== null),
    }))
}

function scrollDateCoverage(direction: -1 | 1): void {
    const scroller = dateCoverageScroller.value
    if (!scroller) return

    scroller.scrollBy({
        left: direction * Math.max(240, scroller.clientWidth * 0.75),
        behavior: 'smooth',
    })
}

function errorMessage(value: unknown, fallback: string): string {
    const response = (value as { response?: { data?: { message?: string } } })?.response
    return response?.data?.message || (value instanceof Error ? value.message : '') || fallback
}

async function loadMonthOptions(): Promise<void> {
    if (!patientId.value) {
        monthOptions.value = []
        return
    }

    loadingMonthOptions.value = true
    try {
        const response = await api.get<{ data: DekurzMonthOption[] }>(
            `/v1/patients/${patientId.value}/dekurz-months`,
        )
        monthOptions.value = response.data.data
    } catch (loadError) {
        monthOptions.value = []
        toast.add({
            severity: 'error',
            summary: 'Mesiace Dekurzu sa nepodarilo načítať',
            detail: errorMessage(loadError, 'Skúste stránku načítať znova.'),
            life: 5000,
        })
    } finally {
        loadingMonthOptions.value = false
    }
}

async function selectDekurzMonth(month: string | null): Promise<void> {
    if (!month) return

    const option = monthOptions.value.find(item => item.month === month)
    if (!option) return

    if (option.status === 'timeline_required') {
        await recalculateTimeline(option)
        return
    }

    await flushSectionSaves()

    if (option.status === 'issued' && option.document_id) {
        await router.replace({
            query: {
                ...router.currentRoute.value.query,
                editDocumentId: String(option.document_id),
            },
        })
        await loadDocumentSnapshot(option.document_id)
        schedulePreview()
        return
    }

    const query = { ...router.currentRoute.value.query }
    delete query.editDocumentId
    await router.replace({ query })
    clearSnapshotEditMode()
    selectedCreationMonthKey.value = month
    const [year = 0, selectedMonthNumber = 1] = month.split('-').map(Number)
    selectedMonth.value = new Date(year, selectedMonthNumber - 1, 1)
    await loadAndInitialize()
}

async function selectFirstReadyMonth(): Promise<void> {
    const option = monthOptions.value.find(item => item.status === 'ready')
    if (option) {
        await selectDekurzMonth(option.month)
    }
}

function stopTimelineStatusPolling(): void {
    if (timelineStatusTimer) {
        clearTimeout(timelineStatusTimer)
        timelineStatusTimer = null
    }
}

async function pollTimelineCalculation(
    option: DekurzMonthOption,
    requestedAt: string,
    branchId: number,
    nurseId: number,
    requestedPatientId: number,
): Promise<void> {
    timelineStatusAttempts++

    try {
        const response = await api.get('/v1/visits/timeline/status', {
            params: {
                month: `${option.month}-01`,
                branch_id: branchId,
                user_id: nurseId,
            },
        })
        const status = response.data?.data?.status
        const completedAt = response.data?.data?.completed_at
        const isCurrentCompletion =
            typeof completedAt === 'string' &&
            new Date(completedAt).getTime() >= new Date(requestedAt).getTime()

        if (status === 'completed' && isCurrentCompletion) {
            stopTimelineStatusPolling()
            timelineCalculationMonth.value = null
            toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
            toast.add({
                severity: 'success',
                summary: 'Výpočet dokončený',
                detail: `Časová os pre ${option.label} bola úspešne vypočítaná.`,
                life: 5000,
            })

            if (patientId.value === requestedPatientId) {
                await loadMonthOptions()
                const refreshedOption = monthOptions.value.find(
                    monthOption => monthOption.month === option.month,
                )
                if (refreshedOption?.status === 'ready') {
                    await selectDekurzMonth(option.month)
                }
            }
            return
        }

        if (status === 'failed') {
            stopTimelineStatusPolling()
            timelineCalculationMonth.value = null
            toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
            toast.add({
                severity: 'error',
                summary: 'Chyba výpočtu',
                detail: response.data?.data?.error_message || 'Časovú os sa nepodarilo vypočítať.',
                life: 6000,
            })
            return
        }
    } catch (pollError) {
        if (timelineStatusAttempts >= 120) {
            stopTimelineStatusPolling()
            timelineCalculationMonth.value = null
            toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
            toast.add({
                severity: 'error',
                summary: 'Stav výpočtu sa nepodarilo overiť',
                detail: errorMessage(pollError, 'Skúste stránku načítať znova.'),
                life: 6000,
            })
            return
        }
    }

    if (timelineStatusAttempts >= 120) {
        stopTimelineStatusPolling()
        timelineCalculationMonth.value = null
        toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
        toast.add({
            severity: 'warn',
            summary: 'Výpočet pokračuje na pozadí',
            detail: 'Výpočet trvá dlhšie ako obvykle. Dostupnosť mesiaca skontrolujte neskôr.',
            life: 6000,
        })
        return
    }

    timelineStatusTimer = setTimeout(() => {
        void pollTimelineCalculation(
            option,
            requestedAt,
            branchId,
            nurseId,
            requestedPatientId,
        )
    }, 5000)
}

async function recalculateTimeline(option: DekurzMonthOption): Promise<void> {
    if (option.status !== 'timeline_required' || timelineCalculationMonth.value) return

    const branchId = Number(patientStore.current?.branch_id ?? 0)
    const nurseId = Number(patientStore.current?.nurse_id ?? 0)
    const requestedPatientId = patientId.value

    if (!branchId || !nurseId || !requestedPatientId) {
        toast.add({
            severity: 'error',
            summary: 'Výpočet sa nedá spustiť',
            detail: 'Pacient nemá priradenú pobočku alebo sestru.',
            life: 5000,
        })
        return
    }

    timelineCalculationMonth.value = option.month
    timelineStatusAttempts = 0
    stopTimelineStatusPolling()

    try {
        const response = await api.post('/v1/visits/timeline', {
            month: `${option.month}-01`,
            branch_id: branchId,
            user_id: nurseId,
            persist: true,
        })
        const requestedAt = response.data?.data?.requested_at
        if (typeof requestedAt !== 'string') {
            throw new Error('Timeline request timestamp is missing.')
        }

        toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
        toast.add({
            group: TIMELINE_CALC_TOAST_GROUP,
            severity: 'info',
            summary: 'Prebieha výpočet časovej osi.',
            detail: `Počítajú sa časy návštev pre ${option.label}.`,
            life: 0,
            closable: false,
        })

        timelineStatusTimer = setTimeout(() => {
            void pollTimelineCalculation(
                option,
                requestedAt,
                branchId,
                nurseId,
                requestedPatientId,
            )
        }, 500)
    } catch (calculationError) {
        timelineCalculationMonth.value = null
        toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)
        toast.add({
            severity: 'error',
            summary: 'Výpočet sa nepodarilo spustiť',
            detail: errorMessage(calculationError, 'Skúste to znova.'),
            life: 6000,
        })
    }
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

function snapshotSections(payload: Record<string, any>): DekurzNarrativeSection[] {
    const savedSections = Array.isArray(payload.narrative_sections) && payload.narrative_sections.length
        ? payload.narrative_sections
        : Array.isArray(payload.sections) && payload.sections.length
            ? payload.sections
            : null

    if (savedSections) {
        return savedSections.map((section: Record<string, any>) => ({
            text: String(section.text ?? ''),
            dates: Array.isArray(section.dates) ? section.dates.map(String) : [],
        }))
    }

    const byText = new Map<string, string[]>()
    for (const day of Array.isArray(payload.days) ? payload.days : []) {
        const text = String(day?.text ?? '').trim()
        const date = String(day?.date ?? '').slice(0, 10)
        if (!text || !date) continue
        byText.set(text, [...(byText.get(text) ?? []), date])
    }

    return Array.from(byText, ([text, dates]) => ({ text, dates }))
}

async function loadDocumentSnapshot(documentId: number) {
    const response = await api.get(`/v1/dekurz/${documentId}`)
    const responseData = response.data?.data
    const payload = responseData?.dekurz_data as Record<string, any> | undefined
    const document = responseData?.document as Record<string, any> | undefined

    if (!payload || Number(document?.patient_id ?? payload.patient_id) !== patientId.value) {
        throw new Error('Vybraný Dekurz nepatrí aktuálnemu pacientovi.')
    }

    const documentMonth = String(payload.month ?? payload.period ?? document?.period ?? '').slice(0, 7)
    if (!/^\d{4}-\d{2}$/.test(documentMonth)) {
        throw new Error('Dokument nemá platný mesiac Dekurzu.')
    }

    const savedSections = snapshotSections(payload)
    const snapshotDays = Array.isArray(payload.days)
        ? payload.days as Array<Record<string, any>>
        : []
    sections.value = (savedSections.length ? savedSections : [{ text: '', dates: [] }])
        .map(section => makeSection(section.text, section.dates))
    snapshotAvailableDates.value = Array.from(new Set([
        ...savedSections.flatMap(section => section.dates),
        ...snapshotDays.map(day => String(day?.date ?? '').slice(0, 10)),
    ].filter(Boolean)))
    snapshotCoverageDays.value = snapshotDays
        .map(day => {
            const entries = Array.isArray(day.entries)
                ? day.entries as Array<Record<string, any>>
                : []
            const procedures = Array.from(new Set(entries.map(entry => {
                const procedure = entry.procedure as Record<string, any> | null
                const code = String(procedure?.code ?? '').trim()
                const name = String(
                    procedure?.description ??
                    entry.track_name ??
                    entry.custom_track?.name ??
                    '',
                ).trim()
                if (code && name) return `${code} – ${name}`
                return code || name
            }).filter(Boolean)))
            const procedureCodes = Array.from(new Set(entries.map(entry => {
                const procedure = entry.procedure as Record<string, any> | null
                return String(
                    procedure?.code ??
                    entry.track_name ??
                    entry.custom_track?.name ??
                    '',
                ).trim()
            }).filter(Boolean)))

            return {
                date: String(day?.date ?? '').slice(0, 10),
                procedures,
                procedureCodes,
            }
        })
        .filter(day => day.date !== '')
    snapshotDekurzNumber.value = String(payload.dekurz_number ?? '')
    dekurzNumber.value = snapshotDekurzNumber.value
    draftInitializationError.value = ''
    builder.value = null
    snapshotEditingDocumentId.value = documentId
    snapshotEditMode.value = true
    editingDocumentMonthKey.value = documentMonth
    selectedCreationMonthKey.value = null
    sectionErrors.value = {}
    previousSectionTextById.value = {}
    sectionsBeforeAiPrefill.value = null

    const year = Number(documentMonth.slice(0, 4))
    const month = Number(documentMonth.slice(5, 7))
    selectedMonth.value = new Date(year, month - 1, 1)
}

function clearSnapshotEditMode() {
    snapshotEditMode.value = false
    snapshotEditingDocumentId.value = null
    snapshotAvailableDates.value = []
    snapshotCoverageDays.value = []
    snapshotDekurzNumber.value = ''
    snapshotSaveState.value = 'idle'
    editingDocumentMonthKey.value = null
}

function resetUnselectedCreationState(): void {
    clearSnapshotEditMode()
    selectedCreationMonthKey.value = null
    builder.value = null
    sections.value = [makeSection()]
    sectionErrors.value = {}
    draftInitializationError.value = ''
    previewHtml.value = ''
    previewError.value = null
    isPreviewLoading.value = false
    isPreviewPaginationReady.value = false
}

async function openDocumentSnapshot(documentId: number): Promise<void> {
    loadingSnapshot.value = true
    error.value = ''

    try {
        await loadDocumentSnapshot(documentId)
        schedulePreview()
    } catch (snapshotError) {
        clearSnapshotEditMode()
        error.value = errorMessage(
            snapshotError,
            'Vybraný Dekurz sa nepodarilo načítať.',
        )
    } finally {
        loadingSnapshot.value = false
    }
}

async function syncEditModeWithLoadedDraft() {
    const finalDocumentId =
        Number(
            builder.value
                ?.draft
                ?.final_document_id ??
                builder.value
                    ?.existing_documents
                    ?.[0]
                    ?.id ??
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
        editDocumentId.value !==
        finalDocumentId
    ) {
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

    if (
        !snapshotEditMode.value ||
        snapshotEditingDocumentId.value !== finalDocumentId
    ) {
        await loadDocumentSnapshot(finalDocumentId)
    }
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

function saveSnapshotSections(payload: DekurzNarrativeSection[]): Promise<boolean> {
    const documentId = snapshotEditingDocumentId.value
    if (!documentId) return Promise.resolve(false)

    pendingDraftSaves++
    draftSectionSaving.value = true
    snapshotSaveState.value = 'saving'
    draftSectionSaveQueue = draftSectionSaveQueue
        .then(async () => {
            try {
                await api.put(`/v1/dekurz/${documentId}`, {
                    dekurz_number: Number(dekurzNumber.value),
                    sections: payload,
                })
                snapshotSaveState.value = 'saved'
                snapshotDekurzNumber.value = dekurzNumber.value
                return true
            } catch (saveError) {
                snapshotSaveState.value = 'error'
                toast.add({
                    severity: 'error',
                    summary: 'Dekurz sa nepodarilo uložiť',
                    detail: errorMessage(saveError, 'Skúste dokument uložiť znova.'),
                    life: 5000,
                })
                return false
            }
        })
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
    if (snapshotEditMode.value) {
        return saveSnapshotSections(payload)
    }
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
        !patientId.value
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
        const previewUrl = snapshotEditMode.value && snapshotEditingDocumentId.value
            ? `/v1/dekurz/${snapshotEditingDocumentId.value}/edit-preview`
            : '/v1/dekurz/draft-preview'
        const previewPayload = snapshotEditMode.value
            ? {
                dekurz_number: dekurzNumber.value || undefined,
                sections: sectionPayload(),
            }
            : {
                patient_id: patientId.value,
                month: monthKey.value || null,
                dekurz_number: dekurzNumber.value || undefined,
                sections: sectionPayload(),
            }
        const response =
            await api.post(
                previewUrl,
                previewPayload,

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

    if (
        snapshotEditMode.value &&
        editingDocumentMonthKey.value === monthKey.value
    ) {
        schedulePreview()
        return
    }

    const id = patientId.value
    if (!id || !monthKey.value) {
        loadingTexts.value = false
        await load()
        return
    }

    loadingTexts.value = true

    try {
        await load()

        if (
            token === loadToken &&
            builder.value?.existing_documents.length
        ) {
            await syncEditModeWithLoadedDraft()
            patientTextSaveState.value = 'idle'
            schedulePreview()
            return
        }

        // Create the one logical patient+month Dekurz automatically only when it
        // does not exist yet. Merely viewing a completed month must not reopen it.
        if (token === loadToken && builder.value?.draft?.status !== 'completed') {
            await initializeDraft()
        }

        if (
            token === loadToken &&
            !builder.value?.days.length
        ) {
            sections.value = [makeSection()]
            draftInitializationError.value =
                'Pre tento mesiac neexistuje vygenerovaný Dekurz ani bodovanie, z ktorého by sa dal vytvoriť.'
            previewHtml.value = ''
            previewError.value = draftInitializationError.value
            patientTextSaveState.value = 'idle'

            return
        }

        const patientTexts = await fetchPatientTexts(id)
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

function validateFinalizeContent(): boolean {
    const errors: Record<string, string> = {}
    for (const section of sections.value) {
        if (!section.text.trim()) errors[`${section.id}-text`] = 'Text sekcie je povinný.'
        if (section.text.includes('[TEXT]')) errors[`${section.id}-text`] = 'Doplňte hodnotu [TEXT].'
        if (!section.dates.length) errors[`${section.id}-dates`] = 'Vyberte aspoň jeden dátum.'
    }
    sectionErrors.value = errors
    if (Object.keys(errors).length) return false

    if (missingCoverageDates.value.length) {
        toast.add({
            severity: 'warn',
            summary: 'Nie všetky dni majú priradený text',
            detail: `Chýbajú dátumy: ${missingCoverageDates.value
                .map(day => day.formattedDate)
                .join(', ')}.`,
            life: 6000,
        })
        return false
    }

    return true
}

async function requestFinalize(): Promise<void> {
    if (!validateFinalizeContent()) return

    if (snapshotEditMode.value) {
        await handleFinalize()
        return
    }

    const suggestedNumber = Number(dekurzNumber.value)
    pendingDekurzNumber.value = Number.isInteger(suggestedNumber) && suggestedNumber > 0
        ? suggestedNumber
        : null
    finalizeConfirmationVisible.value = true
}

async function confirmFinalize(): Promise<void> {
    if (
        pendingDekurzNumber.value === null ||
        !Number.isInteger(pendingDekurzNumber.value) ||
        pendingDekurzNumber.value < 1
    ) {
        return
    }

    dekurzNumber.value = String(pendingDekurzNumber.value)
    finalizeConfirmationVisible.value = false
    await handleFinalize()
}

async function handleFinalize() {
    if (!validateFinalizeContent()) return
    if (!await persistSections()) return

    if (snapshotEditMode.value && snapshotEditingDocumentId.value) {
        toast.add({
            severity: 'success',
            summary: 'Úspešne',
            detail: 'Dekurz bol aktualizovaný.',
            life: 3000,
        })

        await router.push({
            name: 'documents-dekurz',
            params: {
                documentId: snapshotEditingDocumentId.value,
            },
        })

        return
    }

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
    patientIdRef,
    async () => {
        dekurzNumber.value =
            String(
                patientStore.current
                    ?.dekurz_number ??
                    '',
            )

        if (editDocumentId.value) {
            const query = { ...router.currentRoute.value.query }
            delete query.editDocumentId
            await router.replace({ query })
        }

        resetUnselectedCreationState()
        await loadMonthOptions()
        await selectFirstReadyMonth()
        schedulePreview()
    },
)

watch(
    editDocumentId,
    async (documentId, previousDocumentId) => {
        if (documentId === previousDocumentId) return

        if (documentId) {
            await openDocumentSnapshot(documentId)
            return
        }

        if (previousDocumentId) {
            resetUnselectedCreationState()
            await loadMonthOptions()
            await selectFirstReadyMonth()
        }
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
        if (editDocumentId.value) {
            await openDocumentSnapshot(editDocumentId.value)
        } else {
            resetUnselectedCreationState()
            await loadMonthOptions()
            await selectFirstReadyMonth()
            schedulePreview()
        }

        await loadMacros()
    },
)

onBeforeUnmount(() => {
    stopTimelineStatusPolling()
    toast.removeGroup(TIMELINE_CALC_TOAST_GROUP)

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
                        initializing ||
                        loadingSnapshot
                    "
                    :text="
                        loadingSnapshot
                            ? 'Načítavam uložený Dekurz…'
                            : initializing
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
                            <div
                                v-if="!isEditingDocument"
                                class="grid grid-cols-12 gap-4"
                            >
                                <FormField label="Mesiac dekurzu" class="col-span-12">
                                    <Select
                                        :modelValue="selectedCreationMonthKey"
                                        :options="monthOptions"
                                        optionLabel="label"
                                        optionValue="month"
                                        :loading="loadingMonthOptions"
                                        placeholder="Vyberte mesiac"
                                        class="w-full border-0!"
                                        @update:modelValue="selectDekurzMonth"
                                    >
                                        <template #option="{ option }">
                                            <div class="flex w-full items-center justify-between gap-3">
                                                <span>{{ option.label }}</span>
                                                <Tag
                                                    v-if="option.status === 'issued'"
                                                    value="Vytvorený"
                                                    class="bg-darkgrey!"
                                                />
                                                <Tag
                                                    v-else-if="option.status === 'ready'"
                                                    value="Pripravený"
                                                    class="bg-darkgrey!"   
                                                />
                                                <div v-else class="flex flex-wrap items-center gap-2">
                                                    <AppButton
                                                        type="button"
                                                        variant="text"
                                                        label="Prepočítať časy"
                                                        :loading="timelineCalculationMonth === option.month"
                                                        :disabled="timelineCalculationMonth !== null"
                                                        @pointerdown.stop
                                                        @mousedown.stop
                                                        @click.stop.prevent="recalculateTimeline(option)"
                                                    />

                                                    <Tag
                                                        value="Nepripravený"
                                                        class="bg-darkgrey!"
                                                    />
                                                </div>
                                            </div>
                                        </template>
                                    </Select>
                                </FormField>
                            </div>

                            <template v-if="hasActiveDekurz">
                                <section
                                    v-if="dateCoverage.length"
                                >
                                
                                    <div class="mb-2 flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <span class="text-normal">
                                                Pokrytie dní
                                            </span>
                                        </div>

                                        <div class="flex items-center gap-4 text-mini">
                                            <span class="flex items-center gap-1 text-success">
                                                <i class="bi bi-check-circle-fill" aria-hidden="true" />
                                                Vybrané {{ dateCoverage.length - missingCoverageDates.length }}
                                            </span>
                                            <span
                                                v-if="missingCoverageDates.length"
                                                class="flex items-center gap-1 text-danger"
                                            >
                                                <i class="bi bi-exclamation-circle-fill" aria-hidden="true" />
                                                Chýba {{ missingCoverageDates.length }}
                                            </span>
                                        </div>
                                    </div>

                                    <div
                                        ref="dateCoverageScroller"
                                        class="flex scroll-smooth gap-2 overflow-x-auto pb-1"
                                    >
                                        <div
                                            v-for="day in dateCoverage"
                                            :key="day.date"
                                            class="
                                                flex
                                                flex-none
                                                flex-col
                                                gap-2
                                                rounded-md
                                                px-2
                                                py-2
                                            "
                                            :class="
                                                day.selected
                                                    ? 'bg-success'
                                                    : 'bg-danger'
                                            "
                                            :title="
                                                day.procedureSummary ||
                                                'Bez priradeného výkonu'
                                            "
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-normal text-white">
                                                    {{ day.formattedDate }}
                                                </span>
                                            </div>

                                            <div class="flex flex-col gap-1">
                                                <Tag
                                                    v-for="procedureCode in day.procedureCodes"
                                                    :key="procedureCode"
                                                    class="bg-white! w-fit"
                                                    :pt="{ label: { class: 'text-darkgrey!' } }"
                                                    :value="procedureCode"
                                                />

                                                <Tag
                                                    v-if="!day.procedureCodes.length"
                                                    class="bg-darkgrey!"
                                                >
                                                    Bez výkonu
                                                </Tag>
                                            </div>
                                        </div>
                                        </div>
                                             <div class="items-center gap-4 flex justify-end mt-2">
                                                <button
                                                    type="button"
                                                    class="
                                                        flex
                                                        cursor-pointer
                                                        items-center
                                                        justify-center
                                                        text-darkgrey
                                                        text-normal
                                                    "
                                                    aria-label="Posunúť dni doľava"
                                                    title="Predchádzajúce dni"
                                                    @click="scrollDateCoverage(-1)"
                                                >
                                                    <i class="bi bi-chevron-left" aria-hidden="true" />
                                                </button>

                                                <button
                                                    type="button"
                                                    class="
                                                        flex
                                                        cursor-pointer
                                                        items-center
                                                        justify-center
                                                        text-darkgrey
                                                        text-normal
                                                    "
                                                    aria-label="Posunúť dni doprava"
                                                    title="Nasledujúce dni"
                                                    @click="scrollDateCoverage(1)"
                                                >
                                                    <i class="bi bi-chevron-right" aria-hidden="true" />
                                                </button>
                                        </div>
                                </section>

                                <div
                                    v-if="!hasDraft"
                                    class="flex items-start gap-3 rounded-md bg-danger p-4"
                                >
                                <i class="bi bi-clock-history mt-0.5 text-white" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-normal font-medium text-white">
                                        {{ initializing ? 'Pripravujem Dekurz…' : 'Dni Dekurzu zatiaľ nie je možné vybrať.' }}
                                    </p>
                                    <p class="mt-1 text-mini text-white">
                                        {{ draftInitializationError || 'Kontrolujem vypočítané časy návštev.' }}
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
                                    :label="snapshotEditMode ? 'Aktualizovať dokument' : builder?.draft?.final_document_id ? 'Znovu vygenerovať dokument' : 'Generovať dokument'"
                                    :loading="finalizing"
                                    :disabled="!hasDraft || sectionsLocked || isPreviewLoading || !isPreviewPaginationReady || sectionSaveState === 'saving'"
                                    @click="requestFinalize"
                                />
                                </div>
                            </template>
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
                    :title="previewHtml ? 'Náhľad Dekurzu' : ''"
                    @page-count-change="handlePreviewPageCountChange"
                />
            </section>
        </div>

        <Dialog
            v-if="!snapshotEditMode"
            v-model:visible="finalizeConfirmationVisible"
            modal
            :closable="!finalizing"
            :dismissableMask="!finalizing"
            header="Potvrdenie čísla dekurzu"
            :style="{ width: 'min(440px, 94vw)' }"
        >
            <div class="flex flex-col gap-5">
                <FormField label="Potvrďte číslo dekurzu">
                    <InputNumber
                        v-model="pendingDekurzNumber"
                        :min="1"
                        :maxFractionDigits="0"
                        :useGrouping="false"
                        autofocus
                        class="w-full"
                        inputClass="w-full"
                        @keyup.enter="confirmFinalize"
                    />
                </FormField>
            </div>

            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <AppButton
                        type="button"
                        variant="text"
                        label="Zrušiť"
                        :disabled="finalizing"
                        @click="finalizeConfirmationVisible = false"
                    />

                    <AppButton
                        type="button"
                        variant="accent"
                        label="Generovať dokument"
                        :loading="finalizing"
                        :disabled="pendingDekurzNumber === null || pendingDekurzNumber < 1"
                        @click="confirmFinalize"
                    />
                </div>
            </template>
        </Dialog>
    </div>
</template>
