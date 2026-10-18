<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    type ComponentPublicInstance,
} from 'vue'

import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Popover from 'primevue/popover'

import api from '@/services/api'

import { usePatientStore } from '@/stores/patientStore'

import DocumentAlert from '@/components/DocumentAlert.vue'
import AdonisButton from '@/components/AdonisButton.vue'
import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'
import ReferenceDocumentDrawer from '@/components/ReferenceDocumentDrawer.vue'
import ScanDocumentContent from '@/components/documents/ScanDocumentContent.vue'
import DocumentPreviewViewer from '@/components/DocumentPreviewViewer.vue'

interface Diagnosis {
    id: number
    code: string
    description: string
}

interface NurseDiagnosis {
    id: number
    code: string
    description: string
}

interface ProcedureOption {
    id: number
    code: string
    description: string
}

interface SelectedProcedure {
    procedure: ProcedureOption | null
    frequency: string
}

interface ProposalValidationIssue {
    rule_code: string
    type: string
    severity: 'error' | 'warning' | 'info'
    procedure_codes: string[]
    message: string
    source_reference: string | null
    metadata?: Record<string, unknown>
}

interface ProposalValidationResult {
    version: string
    care_type: string
    errors: ProposalValidationIssue[]
    warnings: ProposalValidationIssue[]
    info: ProposalValidationIssue[]
    per_procedure: Record<
        string,
        ProposalValidationIssue[]
    >
}

interface Plan {
    id: number
    name: string
    text: string
}

interface OcrPrefillProcedure {
    code: string
    frequency: string
}

interface OcrPrefill {
    medical_diagnosis_codes: string[]
    nurse_diagnosis_codes: string[]
    epicrisis_description: string
    care_plan: string
    patient_mobility: string[]
    expected_duration: string
    procedures: OcrPrefillProcedure[]
}

interface OcrPrefillAvailability {
    has_scan_document: boolean
    has_extracted_text: boolean
    can_prefill: boolean
    latest_scan_document_id: number | null
    scanned_at: string | null
}

const router =
    useRouter()

const toast =
    useToast()

const patientStore =
    usePatientStore()

patientStore.loadFromStorage?.()

const patientId =
    computed(
        () =>
            patientStore.current
                ?.id ??
            0,
    )

const patientDeathDate =
    computed<Date | null>(
        () => {
            const raw =
                patientStore.current
                    ?.death_date

            if (
                !raw ||
                typeof raw !==
                    'string'
            ) {
                return null
            }

            const date =
                new Date(
                    `${raw.slice(0, 10)}T00:00:00`,
                )

            if (
                Number.isNaN(
                    date.getTime(),
                )
            ) {
                return null
            }

            return new Date(
                date.getFullYear(),
                date.getMonth(),
                date.getDate(),
            )
        },
    )

const maxSelectableDate =
    computed<Date | undefined>(
        () =>
            patientDeathDate.value ??
            undefined,
    )

/* -------------------------------------------------------------------------- */
/* Selected                                                                   */
/* -------------------------------------------------------------------------- */

const medicalDiagnoses =
    ref<Diagnosis[]>([])

const nurseDiagnoses =
    ref<NurseDiagnosis[]>([])

/* -------------------------------------------------------------------------- */
/* Suggestions                                                                */
/* -------------------------------------------------------------------------- */

const filteredDiagnoses =
    ref<Diagnosis[]>([])

const filteredNurseDiagnoses =
    ref<NurseDiagnosis[]>([])

const filteredProcedures =
    ref<ProcedureOption[]>([])

/* -------------------------------------------------------------------------- */
/* State                                                                      */
/* -------------------------------------------------------------------------- */

const errors =
    ref<
        Record<
            string,
            string
        >
    >({})

const submitted =
    ref(false)

const loadingPrefill =
    ref(false)

const loadingOcrPrefill =
    ref(false)

const isGenerating =
    ref(false)

const proposalValidation =
    ref<ProposalValidationResult | null>(
        null,
    )

const isProposalValidationLoading =
    ref(false)

const proposalValidationFailure =
    ref<string | null>(
        null,
    )

const documentId =
    ref<number | null>(
        null,
    )

const dialogVisible =
    ref(false)

const ocrPrefillAvailability =
    ref<
        OcrPrefillAvailability |
        null
    >(null)

const date =
    ref<Date>(
        new Date(),
    )

const epicrisisDescription =
    ref('')

const carePlan =
    ref('')

const patientMobility =
    ref<string[]>([])

const expectedDuration =
    ref('')

const procedures =
    ref<SelectedProcedure[]>([
        {
            procedure:
                null,
            frequency:
                '',
        },
    ])

/* -------------------------------------------------------------------------- */
/* Plans                                                                      */
/* -------------------------------------------------------------------------- */

const plans =
    ref<Plan[]>([])

const plansLoading =
    ref(false)

const carePlanPopover =
    ref<InstanceType<typeof Popover> | null>(
        null,
    )

const carePlanTextarea =
    ref<ComponentPublicInstance | HTMLTextAreaElement | null>(
        null,
    )

let carePlanSelectionStart =
    0

let carePlanSelectionEnd =
    0

/* -------------------------------------------------------------------------- */
/* Preview                                                                    */
/* -------------------------------------------------------------------------- */

const previewHtml =
    ref('')

const isPreviewLoading =
    ref(false)

const previewError =
    ref<string | null>(
        null,
    )

let previewTimer:
    ReturnType<typeof setTimeout> |
    null = null

let previewRequestId =
    0

let proposalValidationTimer:
    ReturnType<typeof setTimeout> |
    null = null

let proposalValidationRequestId =
    0

/* -------------------------------------------------------------------------- */
/* Options                                                                    */
/* -------------------------------------------------------------------------- */

const mobilityOptions = [
    {
        label:
            'H - pacient/ka s obmedzenou pohyblivosťou (50%)',
        value:
            'H',
    },
    {
        label:
            'I - imobilný/á (75%)',
        value:
            'I',
    },
    {
        label:
            'F - pacient/ka s psychickou diagnózou, mentálne retardovaný pacient/ka (75%)',
        value:
            'F',
    },
]

const durationOptions = [
    {
        label:
            'do jedného mesiaca',
        value:
            'one_month',
    },
    {
        label:
            'do 3 mesiacov',
        value:
            'three_months',
    },
    {
        label:
            'do 6 mesiacov',
        value:
            'six_months',
    },
    {
        label:
            'nad 6 mesiacov',
        value:
            'over_six_months',
    },
]

const frequencyOptions = [
    {
        label:
            'Denne',
        value:
            'daily',
    },
    {
        label:
            '2× denne',
        value:
            'twice_daily',
    },
    {
        label:
            '3× denne',
        value:
            'three_times_daily',
    },
    {
        label:
            'V pracovné dni',
        value:
            'weekdays',
    },
    {
        label:
            'Každý druhý deň',
        value:
            'every_other_day',
    },
    {
        label:
            '3× týždenne',
        value:
            'three_times_weekly',
    },
    {
        label:
            '2× týždenne',
        value:
            'twice_weekly',
    },
    {
        label:
            '1× týždenne',
        value:
            'once_weekly',
    },
    {
        label:
            'Počas víkendov a sviatkov',
        value:
            'weekends',
    },
    {
        label:
            '2× mesačne',
        value:
            'twice_monthly',
    },
    {
        label:
            '1× mesačne',
        value:
            'once_monthly',
    },
    {
        label:
            'Jednorazovo',
        value:
            'once',
    },
    {
        label:
            'Podľa potreby',
        value:
            'as_needed',
    },
]

const oneTimeProcedureCodes =
    new Set([
        '3390',
        '3392A',
        '3393',
        '3395',
    ])

function frequencyOptionsFor(
    code?: string,
) {
    if (
        code &&
        oneTimeProcedureCodes.has(
            code,
        )
    ) {
        return frequencyOptions.filter(
            option =>
                option.value ===
                'once',
        )
    }

    if (code === '3394') {
        return frequencyOptions.filter(
            option =>
                option.value ===
                'once_monthly',
        )
    }

    return frequencyOptions
}

function handleProcedureChange(
    row: SelectedProcedure,
) {
    const options =
        frequencyOptionsFor(
            row.procedure?.code,
        )

    if (
        !options.some(
            option =>
                option.value ===
                row.frequency,
        )
    ) {
        const [onlyOption] =
            options

        row.frequency =
            options.length === 1 &&
            onlyOption
                ? onlyOption.value
                : ''
    }
}

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function extractArray(
    raw: any,
): any[] {
    if (
        Array.isArray(
            raw,
        )
    ) {
        return raw
    }

    const candidates = [
        raw?.data,
        raw?.data?.items,
        raw?.data?.data,
        raw?.data?.data
            ?.items,
        raw?.data?.data
            ?.data,
        raw?.items,
        raw?.items?.data,
    ]

    for (
        const candidate
        of candidates
    ) {
        if (
            Array.isArray(
                candidate,
            )
        ) {
            return candidate
        }
    }

    return []
}

function parseCodeFromText(
    value: string,
): string {
    return (
        String(
            value ?? '',
        )
            .split(' - ')[0] ??
        ''
    ).trim()
}

const reverseFrequencyMap:
    Record<
        string,
        string
    > = {
        Denne:
            'daily',

        '1x denne':
            'daily',

        '1× denne':
            'daily',

        '2x denne':
            'twice_daily',

        '2× denne':
            'twice_daily',

        '3x denne':
            'three_times_daily',

        '3× denne':
            'three_times_daily',

        '1x_daily':
            'daily',

        '2x_daily':
            'twice_daily',

        '3x_daily':
            'three_times_daily',

        Jednorazovo:
            'once',

        'Každý druhý deň':
            'every_other_day',

        '3x týždenne':
            'three_times_weekly',

        '3× týždenne':
            'three_times_weekly',

        '2x týždenne':
            'twice_weekly',

        '2× týždenne':
            'twice_weekly',

        '1x týždenne':
            'once_weekly',

        '1× týždenne':
            'once_weekly',

        '2x mesačne':
            'twice_monthly',

        '2× mesačne':
            'twice_monthly',

        '1x mesačne':
            'once_monthly',

        '1× mesačne':
            'once_monthly',

        'V pracovné dni':
            'weekdays',

        'Počas víkendov a sviatkov':
            'weekends',

        'Podľa potreby':
            'as_needed',
    }

function normalizeFrequencyToEnum(
    value: string,
): string {
    const normalized =
        String(
            value ?? '',
        ).trim()

    return (
        reverseFrequencyMap[
            normalized
        ] ??
        normalized
    )
}

function normalizeProcedureFrequency(
    code: string,
    value: string,
): string {
    if (
        oneTimeProcedureCodes.has(
            code,
        )
    ) {
        return 'once'
    }

    if (code === '3394') {
        return 'once_monthly'
    }

    return normalizeFrequencyToEnum(
        value,
    )
}

const toLocalYMD =
    (
        value: Date,
    ) => {
        const year =
            value.getFullYear()

        const month =
            String(
                value.getMonth() +
                1,
            ).padStart(
                2,
                '0',
            )

        const day =
            String(
                value.getDate(),
            ).padStart(
                2,
                '0',
            )

        return `${year}-${month}-${day}`
    }

function isDateAfterDeath(
    value: Date,
): boolean {
    return (
        !!patientDeathDate.value &&
        toLocalYMD(
            value,
        ) >
            toLocalYMD(
                patientDeathDate.value,
            )
    )
}

/* -------------------------------------------------------------------------- */
/* Fetch                                                                      */
/* -------------------------------------------------------------------------- */

async function fetchDiagnosisByCode(
    code: string,
): Promise<
    Diagnosis |
    null
> {
    if (!code) {
        return null
    }

    const response =
        await api.get(
            '/v1/diagnoses',
            {
                params: {
                    q:
                        code,

                    per_page:
                        50,

                    page:
                        1,

                    sort:
                        'code',
                },
            },
        )

    const results =
        extractArray(
            response.data,
        ) as Diagnosis[]

    const match =
        results.find(
            diagnosis =>
                (
                    diagnosis.code ??
                    ''
                )
                    .toLowerCase() ===
                code.toLowerCase(),
        )

    return match
        ? {
            id:
                match.id,

            code:
                match.code ??
                '',

            description:
                match.description ??
                '',
        }
        : null
}

async function fetchNurseDiagnosisByCode(
    code: string,
): Promise<
    NurseDiagnosis |
    null
> {
    if (!code) {
        return null
    }

    const response =
        await api.get(
            '/v1/nurse-diagnoses',
            {
                params: {
                    q:
                        code,

                    per_page:
                        50,

                    page:
                        1,

                    sort:
                        'code',

                    paginate:
                        0,
                },
            },
        )

    const results =
        extractArray(
            response.data,
        ) as NurseDiagnosis[]

    const match =
        results.find(
            diagnosis =>
                (
                    diagnosis.code ??
                    ''
                )
                    .toLowerCase() ===
                code.toLowerCase(),
        )

    return match
        ? {
            id:
                match.id,

            code:
                match.code ??
                '',

            description:
                match.description ??
                '',
        }
        : null
}

async function fetchProcedureByCode(
    code: string,
): Promise<
    ProcedureOption |
    null
> {
    if (!code) {
        return null
    }

    const response =
        await api.get(
            '/v1/procedures',
            {
                params: {
                    q:
                        code,

                    per_page:
                        50,

                    page:
                        1,

                    sort:
                        'code',

                    paginate:
                        0,
                },
            },
        )

    const results =
        extractArray(
            response.data,
        ) as ProcedureOption[]

    const match =
        results.find(
            procedure =>
                (
                    procedure.code ??
                    ''
                )
                    .toLowerCase() ===
                code.toLowerCase(),
        )

    return match
        ? {
            id:
                match.id,

            code:
                match.code ??
                '',

            description:
                match.description ??
                '',
        }
        : null
}

/* -------------------------------------------------------------------------- */
/* Latest proposal                                                            */
/* -------------------------------------------------------------------------- */

async function preloadFromLatestProposal() {
    if (!patientId.value) {
        return
    }

    loadingPrefill.value =
        true

    try {
        const response =
            await api.get(
                `/v1/patients/${patientId.value}/proposals/latest`,
            )

        const proposal =
            response.data
                ?.data
                ?.proposal_data

        if (!proposal) {
            return
        }

        if (
            proposal.date
        ) {
            const parsed =
                new Date(
                    proposal.date,
                )

            if (
                !Number.isNaN(
                    parsed.getTime(),
                )
            ) {
                const normalized =
                    new Date(
                        parsed.getFullYear(),
                        parsed.getMonth(),
                        parsed.getDate(),
                    )

                date.value =
                    patientDeathDate.value &&
                    normalized >
                        patientDeathDate.value
                        ? patientDeathDate.value
                        : normalized
            }
        }

        epicrisisDescription.value =
            proposal.epicrisis ??
            ''

        carePlan.value =
            proposal.care_plan ??
            ''

        patientMobility.value =
            Array.isArray(
                proposal.mobility,
            )
                ? [
                    ...proposal.mobility,
                ]
                : []

        expectedDuration.value =
            proposal.expected_duration ??
            ''

        if (
            Array.isArray(
                proposal.diagnosis,
            ) &&
            proposal
                .diagnosis
                .length
        ) {
            const diagnosisResults =
                await Promise.all(
                    proposal
                        .diagnosis
                        .map(
                            (
                                diagnosis: string,
                            ) =>
                                fetchDiagnosisByCode(
                                    parseCodeFromText(
                                        diagnosis,
                                    ),
                                ),
                        ),
                )

            medicalDiagnoses.value =
                diagnosisResults.filter(
                    (
                        diagnosis,
                    ): diagnosis is Diagnosis =>
                        diagnosis !==
                        null,
                )
        }

        if (
            Array.isArray(
                proposal
                    .nurse_diagnosis,
            ) &&
            proposal
                .nurse_diagnosis
                .length
        ) {
            const nurseResults =
                await Promise.all(
                    proposal
                        .nurse_diagnosis
                        .map(
                            (
                                diagnosis: string,
                            ) =>
                                fetchNurseDiagnosisByCode(
                                    parseCodeFromText(
                                        diagnosis,
                                    ),
                                ),
                        ),
                )

            nurseDiagnoses.value =
                nurseResults.filter(
                    (
                        diagnosis,
                    ): diagnosis is NurseDiagnosis =>
                        diagnosis !==
                        null,
                )
        }

        if (
            Array.isArray(
                proposal.procedures,
            ) &&
            proposal
                .procedures
                .length
        ) {
            const mapped:
                SelectedProcedure[] =
                []

            for (
                const item
                of proposal.procedures
            ) {
                const procedureCode =
                    String(
                        item?.code ??
                        '',
                    ).trim()

                const procedure =
                    await fetchProcedureByCode(
                        procedureCode,
                    )

                mapped.push({
                    procedure,

                    frequency:
                        normalizeProcedureFrequency(
                            procedureCode,
                            String(
                                item?.frequency ??
                                '',
                            ),
                        ),
                })
            }

            procedures.value =
                mapped.length
                    ? mapped
                    : [
                        {
                            procedure:
                                null,

                            frequency:
                                '',
                        },
                    ]
        }

        toast.add({
            severity:
                'info',

            summary:
                'Načítané',

            detail:
                'Texty načítané z histórie.',

            life:
                2500,
        })
    } catch (error: any) {
        if (
            error
                ?.response
                ?.status !==
            404
        ) {
            console.error(
                'Prefill failed:',
                error,
            )
        }
    } finally {
        loadingPrefill.value =
            false
    }
}

/* -------------------------------------------------------------------------- */
/* OCR                                                                        */
/* -------------------------------------------------------------------------- */

async function loadOcrPrefillAvailability() {
    if (
        !patientId.value
    ) {
        ocrPrefillAvailability.value =
            null

        return
    }

    try {
        const response =
            await api.get(
                `/v1/patients/${patientId.value}/proposals/ocr-prefill/availability`,
            )

        ocrPrefillAvailability.value =
            (
                response.data
                    ?.data ??
                null
            ) as OcrPrefillAvailability | null
    } catch (error) {
        console.error(
            'Failed to load OCR prefill availability:',
            error,
        )

        ocrPrefillAvailability.value =
            null
    }
}

const canUseOcrPrefill =
    computed(
        () =>
            !!ocrPrefillAvailability.value
                ?.can_prefill,
    )

const hasScanDocument =
    computed(
        () =>
            !!ocrPrefillAvailability.value
                ?.has_scan_document,
    )

const latestScanDocumentId =
    computed(
        () =>
            ocrPrefillAvailability.value
                ?.latest_scan_document_id ??
            null,
    )

async function prefillFromLatestScanWithAi() {
    if (
        !patientId.value ||
        !canUseOcrPrefill.value
    ) {
        return
    }

    loadingOcrPrefill.value =
        true

    try {
        const response =
            await api.post(
                `/v1/patients/${patientId.value}/proposals/ocr-prefill`,
            )

        const prefill =
            (
                response.data
                    ?.data
                    ?.prefill ??
                null
            ) as OcrPrefill | null

        if (!prefill) {
            toast.add({
                severity:
                    'warn',

                summary:
                    'Bez výsledku',

                detail:
                    'AI nevrátila použiteľné dáta.',

                life:
                    3000,
            })

            return
        }

        if (
            Array.isArray(
                prefill.medical_diagnosis_codes,
            ) &&
            prefill
                .medical_diagnosis_codes
                .length
        ) {
            const diagnosisResults =
                await Promise.all(
                    prefill
                        .medical_diagnosis_codes
                        .map(
                            (
                                code: string,
                            ) =>
                                fetchDiagnosisByCode(
                                    parseCodeFromText(
                                        code,
                                    ),
                                ),
                        ),
                )

            medicalDiagnoses.value =
                diagnosisResults.filter(
                    (
                        diagnosis,
                    ): diagnosis is Diagnosis =>
                        diagnosis !==
                        null,
                )
        }

        if (
            Array.isArray(
                prefill.nurse_diagnosis_codes,
            ) &&
            prefill
                .nurse_diagnosis_codes
                .length
        ) {
            const nurseResults =
                await Promise.all(
                    prefill
                        .nurse_diagnosis_codes
                        .map(
                            (
                                code: string,
                            ) =>
                                fetchNurseDiagnosisByCode(
                                    parseCodeFromText(
                                        code,
                                    ),
                                ),
                        ),
                )

            nurseDiagnoses.value =
                nurseResults.filter(
                    (
                        diagnosis,
                    ): diagnosis is NurseDiagnosis =>
                        diagnosis !==
                        null,
                )
        }

        if (
            Array.isArray(
                prefill.procedures,
            ) &&
            prefill
                .procedures
                .length
        ) {
            const mapped:
                SelectedProcedure[] =
                []

            for (
                const item
                of prefill.procedures
            ) {
                const procedureCode =
                    String(
                        item?.code ??
                        '',
                    ).trim()

                const procedure =
                    await fetchProcedureByCode(
                        procedureCode,
                    )

                if (!procedure) {
                    continue
                }

                mapped.push({
                    procedure,

                    frequency:
                        normalizeProcedureFrequency(
                            procedureCode,
                            String(
                                item?.frequency ??
                                '',
                            ),
                        ),
                })
            }

            procedures.value =
                mapped.length
                    ? mapped
                    : [
                        {
                            procedure:
                                null,

                            frequency:
                                '',
                        },
                    ]
        }

        if (
            Array.isArray(
                prefill.patient_mobility,
            )
        ) {
            const allowed =
                new Set([
                    'H',
                    'I',
                    'F',
                ])

            patientMobility.value =
                prefill
                    .patient_mobility
                    .filter(
                        value =>
                            allowed.has(
                                value,
                            ),
                    )
        }

        if (
            typeof prefill.expected_duration ===
            'string'
        ) {
            expectedDuration.value =
                prefill.expected_duration
        }

        if (
            typeof prefill.epicrisis_description ===
                'string' &&
            prefill
                .epicrisis_description
                .trim()
        ) {
            epicrisisDescription.value =
                prefill
                    .epicrisis_description
                    .trim()
        }

        if (
            typeof prefill.care_plan ===
                'string' &&
            prefill
                .care_plan
                .trim()
        ) {
            carePlan.value =
                prefill
                    .care_plan
                    .trim()
        }

        toast.add({
            severity:
                'success',

            summary:
                'Hotovo',

            detail:
                'Údaje boli predvyplnené z posledného OCR dokumentu.',

            life:
                3000,
        })
    } catch (error: any) {
        console.error(
            'Failed to prefill from OCR using AI:',
            error,
        )

        const message =
            error
                ?.response
                ?.data
                ?.message ??
            error
                ?.message ??
            'Nepodarilo sa načítať dáta z OCR dokumentu.'

        toast.add({
            severity:
                'error',

            summary:
                'Chyba AI',

            detail:
                message,

            life:
                3500,
        })
    } finally {
        loadingOcrPrefill.value =
            false
    }
}

/* -------------------------------------------------------------------------- */
/* Search                                                                     */
/* -------------------------------------------------------------------------- */

async function searchDiagnoses(
    event: {
        query: string
    },
) {
    const branchId =
        Number(
            patientStore.current
                ?.branch_id,
        ) || null

    if (!branchId) {
        filteredDiagnoses.value =
            []

        return
    }

    try {
        const query =
            (
                event?.query ??
                ''
            ).trim()

        const response =
            await api.get(
                '/v1/clinical-suggestions/diagnoses',
                {
                    params: {
                        q:
                            query,

                        branch_id:
                            branchId,

                        limit:
                            25,
                    },
                },
            )

        const results =
            extractArray(
                response.data,
            ) as Diagnosis[]

        filteredDiagnoses.value =
            results.map(
                diagnosis => ({
                    id:
                        diagnosis.id,

                    code:
                        diagnosis.code ??
                        '',

                    description:
                        diagnosis.description ??
                        '',
                }),
            )
    } catch (error) {
        console.error(
            'Failed to load diagnoses:',
            error,
        )

        filteredDiagnoses.value =
            []
    }
}

async function searchNurseDiagnoses(
    event: {
        query: string
    },
) {
    const branchId =
        Number(
            patientStore.current
                ?.branch_id,
        ) || null

    if (!branchId) {
        filteredNurseDiagnoses.value =
            []

        return
    }

    try {
        const query =
            (
                event?.query ??
                ''
            ).trim()

        const response =
            await api.get(
                '/v1/clinical-suggestions/nurse-diagnoses',
                {
                    params: {
                        q:
                            query,

                        branch_id:
                            branchId,

                        limit:
                            25,
                    },
                },
            )

        const results =
            extractArray(
                response.data,
            ) as NurseDiagnosis[]

        filteredNurseDiagnoses.value =
            results.map(
                diagnosis => ({
                    id:
                        diagnosis.id,

                    code:
                        diagnosis.code ??
                        '',

                    description:
                        diagnosis.description ??
                        '',
                }),
            )
    } catch (error) {
        console.error(
            'Failed to load nurse diagnoses:',
            error,
        )

        filteredNurseDiagnoses.value =
            []
    }
}

async function searchProcedures(
    event: {
        query: string
    },
) {
    const branchId =
        Number(
            patientStore.current
                ?.branch_id,
        ) || null

    if (!branchId) {
        filteredProcedures.value =
            []

        return
    }

    try {
        const query =
            (
                event?.query ??
                ''
            ).trim()

        const response =
            await api.get(
                '/v1/clinical-suggestions/procedures',
                {
                    params: {
                        q:
                            query,

                        branch_id:
                            branchId,

                        diagnosis_ids:
                            medicalDiagnoses.value.map(
                                diagnosis =>
                                    diagnosis.id,
                            ),

                        limit:
                            25,
                    },
                },
            )

        const results =
            extractArray(
                response.data,
            ) as ProcedureOption[]

        filteredProcedures.value =
            results.map(
                procedure => ({
                    id:
                        procedure.id,

                    code:
                        procedure.code ??
                        '',

                    description:
                        procedure.description ??
                        '',
                }),
            )
    } catch (error) {
        console.error(
            'Failed to search procedures:',
            error,
        )

        filteredProcedures.value =
            []
    }
}

/* -------------------------------------------------------------------------- */
/* Plans                                                                      */
/* -------------------------------------------------------------------------- */

async function loadPlans() {
    plansLoading.value =
        true

    try {
        const response =
            await api.get(
                '/v1/plans',
            )

        plans.value =
            extractArray(
                response.data,
            ) as Plan[]
    } catch (error) {
        console.error(
            'Failed to load plans:',
            error,
        )

        plans.value =
            []
    } finally {
        plansLoading.value =
            false
    }
}

function getCarePlanTextareaElement():
    HTMLTextAreaElement |
    null {
    const target =
        carePlanTextarea.value

    if (
        target instanceof
        HTMLTextAreaElement
    ) {
        return target
    }

    const element =
        (
            target as
                | ComponentPublicInstance
                | null
        )?.$el

    if (
        element instanceof
        HTMLTextAreaElement
    ) {
        return element
    }

    if (
        element instanceof
        HTMLElement
    ) {
        return element.querySelector(
            'textarea',
        )
    }

    return null
}

function rememberCarePlanSelection() {
    const textarea =
        getCarePlanTextareaElement()

    if (!textarea) {
        carePlanSelectionStart =
            carePlan.value.length

        carePlanSelectionEnd =
            carePlan.value.length

        return
    }

    carePlanSelectionStart =
        textarea.selectionStart ??
        carePlan.value.length

    carePlanSelectionEnd =
        textarea.selectionEnd ??
        carePlanSelectionStart
}

function toggleCarePlanMacros(
    event: Event,
) {
    rememberCarePlanSelection()

    carePlanPopover.value?.toggle(
        event,
    )
}

function insertCarePlanMacro(
    plan: Plan,
) {
    const currentValue =
        carePlan.value ?? ''

    const start =
        Math.min(
            carePlanSelectionStart,
            currentValue.length,
        )

    const end =
        Math.min(
            Math.max(
                carePlanSelectionEnd,
                start,
            ),
            currentValue.length,
        )

    const before =
        currentValue.slice(
            0,
            start,
        )

    const after =
        currentValue.slice(
            end,
        )

    const prefix =
        before.length > 0 &&
        !before.endsWith('\n') &&
        !before.endsWith(' ')
            ? '\n'
            : ''

    const suffix =
        after.length > 0 &&
        !after.startsWith('\n') &&
        !after.startsWith(' ')
            ? '\n'
            : ''

    const insertedText =
        `${prefix}${plan.text}${suffix}`

    carePlan.value =
        `${before}${insertedText}${after}`

    const nextCaretPosition =
        before.length +
        insertedText.length

    carePlanSelectionStart =
        nextCaretPosition

    carePlanSelectionEnd =
        nextCaretPosition

    carePlanPopover.value?.hide()

    requestAnimationFrame(
        () => {
            const textarea =
                getCarePlanTextareaElement()

            if (!textarea) {
                return
            }

            textarea.focus()

            textarea.setSelectionRange(
                nextCaretPosition,
                nextCaretPosition,
            )
        },
    )
}

/* -------------------------------------------------------------------------- */
/* Procedures                                                                 */
/* -------------------------------------------------------------------------- */

function addProcedure() {
    procedures.value.push({
        procedure:
            null,

        frequency:
            '',
    })
}

function removeProcedure(
    index: number,
) {
    if (
        procedures.value
            .length >
        1
    ) {
        procedures.value.splice(
            index,
            1,
        )
    }
}

function removeMedicalDiagnosis(
    diagnosis: Diagnosis,
) {
    medicalDiagnoses.value =
        medicalDiagnoses.value.filter(
            item =>
                item.id !==
                diagnosis.id,
        )
}

function removeNurseDiagnosis(
    diagnosis: NurseDiagnosis,
) {
    nurseDiagnoses.value =
        nurseDiagnoses.value.filter(
            item =>
                item.id !==
                diagnosis.id,
        )
}

/* -------------------------------------------------------------------------- */
/* Validation                                                                 */
/* -------------------------------------------------------------------------- */

function validateForm() {
    const validationErrors:
        Record<
            string,
            string
        > = {}

    if (
        !patientId.value ||
        patientId.value ===
            0
    ) {
        validationErrors.patient =
            'Pacient nie je vybratý.'
    }

    if (
        medicalDiagnoses.value
            .length ===
        0
    ) {
        validationErrors.medicalDiagnosis =
            'Vyberte aspoň jednu lekársku diagnózu.'
    }

    if (
        nurseDiagnoses.value
            .length ===
        0
    ) {
        validationErrors.nurseDiagnosis =
            'Vyberte aspoň jednu sestersku diagnózu.'
    }

    if (!date.value) {
        validationErrors.date =
            'Dátum je povinný.'
    }

    if (
        date.value &&
        isDateAfterDeath(
            date.value,
        )
    ) {
        validationErrors.date =
            'Dátum nemôže byť po dátume úmrtia pacienta.'
    }

    if (
        !epicrisisDescription.value
            ?.trim()
    ) {
        validationErrors.epicrisisDescription =
            'Epizóda a zdôvodnenie sú povinné.'
    }

    if (
        !carePlan.value
            ?.trim()
    ) {
        validationErrors.carePlan =
            'Plán ošetrovateľskej starostlivosti je povinný.'
    }

    if (
        !expectedDuration.value
    ) {
        validationErrors.expectedDuration =
            'Predpokladaná dĺžka je povinná.'
    }

    if (
        !procedures.value.some(
            procedure =>
                procedure
                    .procedure
                    ?.id,
        )
    ) {
        validationErrors.procedures =
            'Pridajte aspoň jeden výkon.'
    }

    errors.value =
        validationErrors

    return (
        Object.keys(
            validationErrors,
        ).length ===
        0
    )
}

const validationSeverityWeight:
    Record<
        ProposalValidationIssue['severity'],
        number
    > = {
        error:
            3,

        warning:
            2,

        info:
            1,
    }

function normalizeIssueMessage(
    message: string,
): string {
    return String(
        message ?? '',
    )
        .trim()
        .replace(
            /\s+/g,
            ' ',
        )
        .toLowerCase()
}

function issueKey(
    issue: ProposalValidationIssue,
): string {
    const procedures =
        [
            ...(
                issue.procedure_codes ??
                []
            ),
        ]
            .sort()
            .join(',')

    return [
        normalizeIssueMessage(
            issue.message,
        ),
        procedures,
    ].join('|')
}

function uniqueProposalIssues(
    issues: ProposalValidationIssue[],
): ProposalValidationIssue[] {
    const unique =
        new Map<
            string,
            ProposalValidationIssue
        >()

    for (
        const issue
        of issues
    ) {
        const key =
            issueKey(
                issue,
            )

        const existing =
            unique.get(
                key,
            )

        if (
            !existing ||
            validationSeverityWeight[
                issue.severity
            ] >
                validationSeverityWeight[
                    existing.severity
                ]
        ) {
            unique.set(
                key,
                issue,
            )
        }
    }

    return Array.from(
        unique.values(),
    )
}

function issuesForProcedure(
    row: SelectedProcedure,
): ProposalValidationIssue[] {
    const code =
        row.procedure?.code

    if (!code) {
        return []
    }

    return uniqueProposalIssues(
        proposalValidation.value
            ?.per_procedure[
                code
            ] ??
        [],
    )
}

const allProposalIssues =
    computed<
        ProposalValidationIssue[]
    >(
        () =>
            uniqueProposalIssues([
                ...(
                    proposalValidation.value
                        ?.errors ??
                    []
                ),
                ...(
                    proposalValidation.value
                        ?.warnings ??
                    []
                ),
                ...(
                    proposalValidation.value
                        ?.info ??
                    []
                ),
            ]),
    )

const generalProposalIssues =
    computed(
        () =>
            uniqueProposalIssues(
                allProposalIssues.value.filter(
                    issue =>
                        (
                            issue.procedure_codes ??
                            []
                        ).length ===
                        0,
                ),
            ),
    )

function issueTextClass(
    issue: ProposalValidationIssue,
): string {
    return issue.severity ===
        'error'
        ? 'text-danger'
        : 'text-warning'
}

async function validateProposalRules():
    Promise<boolean> {
    if (
        !patientId.value ||
        !date.value ||
        isDateAfterDeath(
            date.value,
        )
    ) {
        proposalValidation.value =
            null

        return true
    }

    const requestId =
        ++proposalValidationRequestId

    isProposalValidationLoading.value =
        true

    proposalValidationFailure.value =
        null

    try {
        const response =
            await api.post(
                '/v1/proposals/validate-rules',
                buildProposalPayload(),
            )

        if (
            requestId !==
            proposalValidationRequestId
        ) {
            return true
        }

        proposalValidation.value =
            response.data
                ?.data as ProposalValidationResult

        return (
            proposalValidation.value
                ?.errors.length ===
            0
        )
    } catch (error: any) {
        if (
            requestId ===
            proposalValidationRequestId
        ) {
            console.error(
                'Failed to validate ADOS proposal rules:',
                error,
            )

            const result =
                error?.response
                    ?.data
                    ?.data

            if (result?.errors) {
                proposalValidation.value =
                    result as ProposalValidationResult

                return false
            }

            proposalValidation.value =
                null

            proposalValidationFailure.value =
                error?.response
                    ?.data
                    ?.message ??
                'Pravidlá návrhu ADOS sa nepodarilo skontrolovať.'
        }

        return false
    } finally {
        if (
            requestId ===
            proposalValidationRequestId
        ) {
            isProposalValidationLoading.value =
                false
        }
    }
}

function scheduleProposalValidation() {
    if (proposalValidationTimer) {
        clearTimeout(
            proposalValidationTimer,
        )
    }

    proposalValidationTimer =
        setTimeout(
            () => {
                void validateProposalRules()
            },
            450,
        )
}

/* -------------------------------------------------------------------------- */
/* Existing document                                                          */
/* -------------------------------------------------------------------------- */

function buildProposalPayload() {
    return {
        patient_id:
            patientId.value,

        branch_id:
            patientStore.current
                ?.branch_id ??
            null,

        medical_diagnosis_ids:
            medicalDiagnoses.value.map(
                diagnosis =>
                    diagnosis.id,
            ),

        nurse_diagnosis_ids:
            nurseDiagnoses.value.map(
                diagnosis =>
                    diagnosis.id,
            ),

        date:
            date.value
                ? toLocalYMD(
                    date.value,
                )
                : toLocalYMD(
                    new Date(),
                ),

        epicrisis_description:
            epicrisisDescription.value,

        care_plan:
            carePlan.value,

        patient_mobility:
            patientMobility.value,

        expected_duration:
            expectedDuration.value,

        procedures:
            procedures.value
                .filter(
                    procedure =>
                        procedure
                            .procedure
                            ?.id,
                )
                .map(
                    procedure => ({
                        procedure_id:
                            procedure
                                .procedure!
                                .id,

                        frequency:
                            procedure.frequency,
                    }),
                ),
    }
}

async function loadPreview() {
    if (
        !patientId.value ||
        !patientStore.current
            ?.branch_id ||
        !date.value ||
        isDateAfterDeath(
            date.value,
        )
    ) {
        previewRequestId++
        isPreviewLoading.value =
            false
        previewHtml.value =
            ''
        previewError.value =
            null
        return
    }

    const requestId =
        ++previewRequestId

    isPreviewLoading.value =
        true
    previewError.value =
        null

    try {
        const response =
            await api.post(
                '/v1/proposals/draft-preview',
                buildProposalPayload(),
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
    } catch (error) {
        if (
            requestId !==
            previewRequestId
        ) {
            return
        }

        console.error(
            'Failed to load proposal preview:',
            error,
        )

        previewHtml.value =
            ''
        previewError.value =
            'Náhľad dokumentu sa nepodarilo načítať.'
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
            loadPreview,
            300,
        )
}

async function checkDocumentExists():
    Promise<
        boolean |
        null
    > {
    if (
        !patientId.value ||
        !date.value
    ) {
        return false
    }

    if (
        isDateAfterDeath(
            date.value,
        )
    ) {
        documentId.value =
            null

        dialogVisible.value =
            false

        return false
    }

    try {
        const response =
            await api.post(
                '/v1/documents/check-exists',
                {
                    type:
                        'proposal',

                    date:
                        toLocalYMD(
                            date.value,
                        ),

                    patient_id:
                        patientId.value,
                },
            )

        const result =
            response.data
                ?.data ??
            response.data

        const exists =
            result
                ?.exists ??
            false

        documentId.value =
            result
                ?.document_id ??
            null

        dialogVisible.value =
            exists

        return exists
    } catch (error) {
        console.error(
            'Failed to check document existence:',
            error,
        )

        return null
    }
}

function closeDialog() {
    dialogVisible.value =
        false
}

function continueDocumentGeneration() {
    dialogVisible.value =
        false

    void generateDocument({
        duplicateConfirmed:
            true,
    })
}

function handleExistingDocumentDeleted() {
    documentId.value =
        null

    dialogVisible.value =
        false
}

/* -------------------------------------------------------------------------- */
/* Generate                                                                   */
/* -------------------------------------------------------------------------- */

async function generateDocument(
    options: {
        duplicateConfirmed?: boolean
    } = {},
) {
    if (
        isGenerating.value
    ) {
        return
    }

    submitted.value =
        true

    if (!validateForm()) {
        toast.add({
            severity:
                'error',

            summary:
                'Chyba validácie',

            detail:
                'Vyplňte všetky povinné polia.',

            life:
                3000,
        })

        return
    }

    if (
        date.value &&
        isDateAfterDeath(
            date.value,
        )
    ) {
        toast.add({
            severity:
                'error',

            summary:
                'Neplatný dátum',

            detail:
                'Dátum dokumentu nemôže byť po dátume úmrtia pacienta.',

            life:
                3000,
        })

        return
    }

    const proposalRulesValid =
        await validateProposalRules()

    if (!proposalRulesValid) {
        const proposalMessage =
            proposalValidation.value
                ?.errors[0]
                ?.message ??
            proposalValidationFailure.value ??
            'Návrh obsahuje chyby podľa pravidiel ADOS.'

        toast.add({
            severity:
                'error',

            summary:
                'Kontrola návrhu',

            detail:
                proposalMessage,

            life:
                3500,
        })

        return
    }

    isGenerating.value =
        true

    try {
        if (
            options
                .duplicateConfirmed !==
            true
        ) {
            const exists =
                await checkDocumentExists()

            if (
                exists !==
                false
            ) {
                return
            }
        }

        const payload =
            buildProposalPayload()

        const response =
            await api.post(
                '/v1/proposals',
                payload,
            )

        const generatedDocumentId =
            response.data
                ?.data
                ?.document_id

        if (
            !generatedDocumentId
        ) {
            throw new Error(
                'Server nevrátil ID dokumentu.',
            )
        }

        toast.add({
            severity:
                'success',

            summary:
                'Úspešne',

            detail:
                'Návrh ošetrovateľskej starostlivosti bol vytvorený.',

            life:
                3000,
        })

        await router.push({
            name:
                'documents-proposal',

            params: {
                documentId:
                    generatedDocumentId,
            },
        })
    } catch (error: any) {
        console.error(
            'Failed to generate document:',
            error,
        )

        const message =
            error
                ?.response
                ?.data
                ?.data
                ?.errors?.[0]
                ?.message ??
            error
                ?.response
                ?.data
                ?.message ??
            error
                ?.message ??
            'Chyba pri vytváraní návrhu.'

        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                message,

            life:
                3000,
        })
    } finally {
        isGenerating.value =
            false
    }
}

function submitDocument() {
    void generateDocument()
}

/* -------------------------------------------------------------------------- */
/* Watchers                                                                   */
/* -------------------------------------------------------------------------- */

watch(
    () =>
        patientId.value,

    async (
        id: number,
    ) => {
        if (!id) {
            ocrPrefillAvailability.value =
                null

            return
        }

        await Promise.all([
            preloadFromLatestProposal(),
            loadOcrPrefillAvailability(),
        ])
    },

    {
        immediate:
            true,
    },
)

watch(
    [
        () =>
            patientId.value,

        () =>
            date.value,

        () =>
            medicalDiagnoses.value,

        () =>
            nurseDiagnoses.value,

        () =>
            patientMobility.value,

        () =>
            expectedDuration.value,

        () =>
            procedures.value,
    ],
    scheduleProposalValidation,
    {
        deep:
            true,
        immediate:
            true,
    },
)

watch(
    [
        () =>
            patientId.value,

        () =>
            patientStore.current
                ?.branch_id,

        () =>
            date.value,

        () =>
            medicalDiagnoses.value,

        () =>
            nurseDiagnoses.value,

        () =>
            epicrisisDescription.value,

        () =>
            carePlan.value,

        () =>
            patientMobility.value,

        () =>
            expectedDuration.value,

        () =>
            procedures.value,
    ],
    schedulePreview,
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

onMounted(() => {
    void loadPlans()
})

onBeforeUnmount(() => {
    if (previewTimer) {
        clearTimeout(
            previewTimer,
        )
    }

    if (proposalValidationTimer) {
        clearTimeout(
            proposalValidationTimer,
        )
    }

    previewRequestId++
    proposalValidationRequestId++
})
</script>

<template>
    <DocumentAlert
        :visible="dialogVisible"
        :document-id="documentId"
        document-url="/documents/proposal/{id}"
        @update:visible="
            dialogVisible =
                $event
        "
        @close="
            closeDialog
        "
        @continue="
            continueDocumentGeneration
        "
        @deleted="
            handleExistingDocumentDeleted
        "
    />

    <ReferenceDocumentDrawer
        title="Nález"
        handle-label="Zobraziť posledný nález"
        :available="
            !!latestScanDocumentId
        "
    >
        <ScanDocumentContent
            v-if="latestScanDocumentId"
            :document-id="latestScanDocumentId"
        />
    </ReferenceDocumentDrawer>

    <div
        class="
            -mx-8
            -mb-8
            -mt-8
            h-[calc(100%+4rem)]
            min-h-0
            overflow-hidden
            w-[calc(100%+4rem)]
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
            <!-- ============================================================ -->
            <!-- LEFT: FORM                                                   -->
            <!-- ============================================================ -->

            <aside
                class="
                    h-full
                    min-h-0
                    overflow-hidden
                "
            >
                <form
                    class="
                        flex
                        h-full
                        min-h-0
                        flex-col

                    "
                    @submit.prevent="
                        submitDocument
                    "
                >
                  <section
                      class="
                          flex
                          h-full
                          min-h-0
                          flex-col
                          overflow-hidden
                          rounded-md
                          bg-tag3
                      "
                  >
                      <!-- SCROLLABLE FORM CONTENT -->

                      <div
                          class="
                              min-h-0
                              flex-1
                              overflow-y-auto
                              p-8
                          "
                      >
                        <div
                            class="
                                flex
                                flex-col
                                gap-8
                            "
                        >
                            <!-- ======================================================== -->
                            <!-- DOPLŇUJÚCE INFORMÁCIE PACIENTA                           -->
                            <!-- ======================================================== -->

                            <div
                                class="
                                    grid
                                    grid-cols-12
                                    gap-4
                                "
                            >
                                <div
                                    class="
                                        col-span-12
                                    "
                                >
                                    <label
                                        class="
                                            block
                                            text-normal
                                            text-accent
                                        "
                                    >
                                        Doplňujúce informácie pacienta
                                    </label>
                                </div>

                                <!-- Mobility -->

                                <div
                                    class="
                                        col-span-12
                                    "
                                >
                                    <label
                                        class="
                                            mb-2
                                            block
                                            text-normal
                                        "
                                    >
                                        Mobilita pacienta
                                    </label>

                                    <div
                                        class="
                                            flex
                                            flex-col
                                            gap-2
                                        "
                                    >
                                        <div
                                            v-for="(
                                                option,
                                                index
                                            ) in mobilityOptions"
                                            :key="
                                                option.value
                                            "
                                            class="
                                                flex
                                                items-center
                                                gap-2
                                            "
                                        >
                                            <Checkbox
                                                v-model="
                                                    patientMobility
                                                "
                                                :inputId="
                                                    `mobility-${index}`
                                                "
                                                :value="
                                                    option.value
                                                "
                                            />

                                            <label
                                                :for="
                                                    `mobility-${index}`
                                                "
                                                class="
                                                    cursor-pointer
                                                    text-normal
                                                "
                                            >
                                                {{
                                                    option.label
                                                }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ======================================================== -->
                            <!-- OŠETROVACÍ NÁVRH                                         -->
                            <!-- ======================================================== -->

                            <div
                                class="
                                    grid
                                    grid-cols-12
                                    gap-4
                                "
                            >
                                <div
                                    class="
                                        col-span-12
                                    "
                                >
                                    <label
                                        class="
                                            block
                                            text-normal
                                            text-accent
                                        "
                                    >
                                        Ošetrovací návrh
                                    </label>
                                </div>

                                <!-- Epicrisis -->

                                                                <!-- Medical diagnosis -->

                                <FormField
                                    label="Lekárske diagnózy"
                                    required
                                    :error="
                                        submitted
                                            ? errors.medicalDiagnosis
                                            : null
                                    "
                                    class="
                                        col-span-12
                                    "
                                >
                                    <AutoComplete
                                        v-model="
                                            medicalDiagnoses
                                        "
                                        :suggestions="
                                            filteredDiagnoses
                                        "
                                        multiple
                                        :minLength="1"
                                        dropdown
                                        completeOnFocus
                                        optionLabel="code"
                                        class="
                                            w-full
                                            rounded-md!
                                        "
                                        inputClass="
                                            w-full!
                                            bg-white!
                                            shadow-none!
                                            focus:ring-0!
                                            focus:shadow-none!
                                        "
                                        @complete="
                                            searchDiagnoses
                                        "
                                    >
                                        <template
                                            #option="{
                                                option,
                                            }"
                                        >
                                            <div
                                                class="
                                                    flex
                                                    flex-col
                                                "
                                            >
                                                <span
                                                    class="
                                                        shrink-0
                                                        font-medium
                                                    "
                                                >
                                                    {{
                                                        option.code
                                                    }}
                                                </span>

                                                <span>
                                                    {{
                                                        option.description
                                                    }}
                                                </span>
                                            </div>
                                        </template>

                                        <template
                                            #chip="{
                                                value,
                                            }"
                                        >
                                            <div
                                                class="
                                                    inline-flex
                                                    items-center
                                                    gap-2
                                                    rounded-md
                                                    bg-darkgrey
                                                    px-3
                                                    py-1
                                                    text-normal
                                                    text-white
                                                "
                                            >
                                                <span
                                                    class="
                                                        whitespace-nowrap
                                                    "
                                                >
                                                    {{
                                                        value.code
                                                    }}
                                                </span>

                                                <i
                                                    class="
                                                        bi
                                                        bi-x-lg
                                                        cursor-pointer
                                                        text-[0.65rem]
                                                        text-lightgrey
                                                    "
                                                    @click.stop="
                                                        removeMedicalDiagnosis(
                                                            value,
                                                        )
                                                    "
                                                />
                                            </div>
                                        </template>
                                    </AutoComplete>
                                </FormField>

                                <!-- Nurse diagnosis -->

                                <FormField
                                    label="Sesterské diagnózy"
                                    required
                                    :error="
                                        submitted
                                            ? errors.nurseDiagnosis
                                            : null
                                    "
                                    class="
                                        col-span-12
                                    "
                                >
                                    <AutoComplete
                                        v-model="
                                            nurseDiagnoses
                                        "
                                        :suggestions="
                                            filteredNurseDiagnoses
                                        "
                                        multiple
                                        :minLength="1"
                                        dropdown
                                        completeOnFocus
                                        optionLabel="code"
                                        class="
                                            w-full
                                            rounded-md!
                                        "
                                        inputClass="
                                            w-full!
                                            border-0!
                                            bg-white!
                                            shadow-none!
                                            focus:ring-0!
                                            focus:shadow-none!
                                        "
                                        @complete="
                                            searchNurseDiagnoses
                                        "
                                    >
                                        <template
                                            #option="{
                                                option,
                                            }"
                                        >
                                            <div
                                                class="
                                                    flex
                                                    flex-col
                                                "
                                            >
                                                <span
                                                    class="
                                                        shrink-0
                                                        font-medium
                                                    "
                                                >
                                                    {{
                                                        option.code
                                                    }}
                                                </span>

                                                <span>
                                                    {{
                                                        option.description
                                                    }}
                                                </span>
                                            </div>
                                        </template>

                                        <template
                                            #chip="{
                                                value,
                                            }"
                                        >
                                            <div
                                                class="
                                                    inline-flex
                                                    items-center
                                                    gap-2
                                                    rounded-md
                                                    bg-darkgrey
                                                    px-3
                                                    py-1
                                                    text-normal
                                                    text-white
                                                "
                                            >
                                                <span
                                                    class="
                                                        whitespace-nowrap
                                                    "
                                                >
                                                    {{
                                                        value.code
                                                    }}
                                                </span>

                                                <i
                                                    class="
                                                        bi
                                                        bi-x-lg
                                                        cursor-pointer
                                                        text-[0.65rem]
                                                        text-lightgrey
                                                    "
                                                    @click.stop="
                                                        removeNurseDiagnosis(
                                                            value,
                                                        )
                                                    "
                                                />
                                            </div>
                                        </template>
                                    </AutoComplete>
                                </FormField>

                                <FormField
                                    label="Epikríza a zdôvodnenie pre poskytovanie ošetrovateľskej starostlivosti"
                                    required
                                    :error="
                                        submitted
                                            ? errors.epicrisisDescription
                                            : null
                                    "
                                    class="
                                        col-span-12
                                    "
                                >
                                    <Textarea
                                        v-model="
                                            epicrisisDescription
                                        "
                                        class="
                                            w-full
                                            border-0!
                                        "
                                        rows="4"
                                        :invalid="
                                            submitted &&
                                            !!errors.epicrisisDescription
                                        "
                                    />
                                </FormField>

                                <!-- Care plan -->

                                <FormField
                                    label="Plán ošetrovateľskej starostlivosti"
                                    required
                                    :error="
                                        submitted
                                            ? errors.carePlan
                                            : null
                                    "
                                    class="
                                        col-span-12
                                    "
                                >
                                    <div
                                        class="
                                            relative
                                        "
                                    >
                                        <Textarea
                                            ref="carePlanTextarea"
                                            v-model="
                                                carePlan
                                            "
                                            class="
                                                w-full
                                                border-0!
                                                pb-12!
                                            "
                                            rows="5"
                                            :invalid="
                                                submitted &&
                                                !!errors.carePlan
                                            "
                                            @click="
                                                rememberCarePlanSelection
                                            "
                                            @keyup="
                                                rememberCarePlanSelection
                                            "
                                            @select="
                                                rememberCarePlanSelection
                                            "
                                            @focus="
                                                rememberCarePlanSelection
                                            "
                                        />

                                        <!-- Macro button -->

                                        <button
                                            type="button"
                                            class="
                                                absolute
                                                bottom-3
                                                left-2
                                                flex
                                                h-7
                                                w-10
                                                items-center
                                                justify-center
                                                rounded-md
                                                bg-tag3
                                                text-darkgrey
                                                transition-colors
                                                hover:bg-accent
                                                hover:text-white
                                                cursor-pointer
                                            "
                                            aria-label="
                                                Pridať zo šablóny
                                            "
                                            title="
                                                Pridať zo šablóny
                                            "
                                            @mousedown.prevent="
                                                rememberCarePlanSelection
                                            "
                                            @click="
                                                toggleCarePlanMacros
                                            "
                                        >
                                            <i
                                                class="
                                                    bi
                                                    bi-plus-lg
                                                "
                                                aria-hidden="true"
                                            />
                                        </button>

                                        <!-- Macro picker -->

                                        <Popover
                                            ref="carePlanPopover"
                                        >
                                            <div
                                                class="
                                                    flex
                                                    w-70
                                                    max-w-[calc(100vw-2rem)]
                                                    flex-col
                                                    gap-2
                                                "
                                            >
                                                 <div
                                                    v-if="
                                                        plansLoading
                                                    "
                                                    class="
                                                        flex
                                                        items-center
                                                        gap-2
                                                        px-2
                                                        py-3
                                                        text-mini
                                                        text-darkgrey/60
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

                                                    Načítavam šablóny...
                                                </div>

                                                <div
                                                    v-else-if="
                                                        !plans.length
                                                    "
                                                    class="
                                                        px-2
                                                        py-3
                                                        text-mini
                                                        text-darkgrey/60
                                                    "
                                                >
                                                    Nemáte uložené žiadne šablóny.
                                                </div>

                                                <div
                                                    v-else
                                                    class="
                                                        flex
                                                        max-h-80
                                                        flex-col
                                                        gap-1
                                                        overflow-y-auto
                                                    "
                                                >
                                                    <button
                                                        v-for="
                                                            plan
                                                            in plans
                                                        "
                                                        :key="
                                                            plan.id
                                                        "
                                                        type="button"
                                                        class="
                                                            flex
                                                            w-full
                                                            flex-col
                                                            gap-1
                                                            rounded-md
                                                            px-3
                                                            py-2
                                                            text-left
                                                            transition-colors
                                                            hover:bg-tag3
                                                        "
                                                        @click="
                                                            insertCarePlanMacro(
                                                                plan,
                                                            )
                                                        "
                                                    >
                                                        <span
                                                            class="
                                                                text-normal
                                                                font-medium
                                                                text-darkgrey
                                                            "
                                                        >
                                                            {{
                                                                plan.name
                                                            }}
                                                        </span>

                                                        <span
                                                            class="
                                                                line-clamp-2
                                                                text-mini
                                                                text-darkgrey/60
                                                            "
                                                        >
                                                            {{
                                                                plan.text
                                                            }}
                                                        </span>
                                                    </button>
                                                </div>
                                            </div>
                                        </Popover>
                                    </div>
                                </FormField>

                                <!-- Procedures -->

                                <FormField
                                    label="Výkony a frekvencia realizácie"
                                    required
                                    :error="
                                        submitted
                                            ? errors.procedures
                                            : null
                                    "
                                    class="
                                        col-span-12
                                    "
                                >
                                    <div
                                        class="
                                            flex
                                            flex-col
                                            gap-3
                                        "
                                    >
                                        <div
                                            v-for="(
                                                row,
                                                index
                                            ) in procedures"
                                            :key="
                                                index
                                            "
                                            class="
                                                flex
                                                flex-col
                                                gap-3
                                            "
                                        >
                                            <div
                                                class="
                                                    flex
                                                    w-full
                                                    items-end
                                                    gap-4
                                                "
                                            >
                                                <!-- Procedure -->

                                                <div
                                                    class="
                                                        min-w-0
                                                        flex-1
                                                    "
                                                >
                                                    <label
                                                        :for="
                                                            `procedure-${index}`
                                                        "
                                                        class="
                                                            mb-1
                                                            block
                                                            text-normal
                                                        "
                                                    >
                                                        Výkon
                                                    </label>

                                                    <AutoComplete
                                                        :id="
                                                            `procedure-${index}`
                                                        "
                                                        v-model="
                                                            row.procedure
                                                        "
                                                        :suggestions="
                                                            filteredProcedures
                                                        "
                                                        @complete="
                                                            searchProcedures
                                                        "
                                                        @update:model-value="
                                                            handleProcedureChange(
                                                                row,
                                                            )
                                                        "
                                                        :virtualScrollerOptions="{
                                                            itemSize:
                                                                38,
                                                        }"
                                                        optionLabel="code"
                                                        dropdown
                                                        dropdownMode="blank"
                                                        :minLength="0"
                                                        completeOnFocus
                                                        class="
                                                            w-full
                                                            rounded-md!
                                                        "
                                                        inputClass="
                                                            w-full!
                                                            bg-white!
                                                            shadow-none!
                                                            focus:ring-0!
                                                            focus:shadow-none!
                                                            border-0!
                                                        "
                                                    >
                                                        <template
                                                            #option="{
                                                                option,
                                                            }"
                                                        >
                                                            <div
                                                                class="
                                                                    flex
                                                                    flex-col
                                                                "
                                                            >
                                                                <span
                                                                    class="
                                                                        shrink-0
                                                                        font-medium
                                                                    "
                                                                >
                                                                    {{
                                                                        option.code
                                                                    }}
                                                                </span>

                                                                <span>
                                                                    {{
                                                                        option.description
                                                                    }}
                                                                </span>
                                                            </div>
                                                        </template>
                                                    </AutoComplete>
                                                </div>

                                                <!-- Frequency -->

                                                <div
                                                    class="
                                                        min-w-0
                                                        flex-1
                                                    "
                                                >
                                                    <label
                                                        :for="
                                                            `frequency-${index}`
                                                        "
                                                        class="
                                                            mb-1
                                                            block
                                                            text-normal
                                                        "
                                                    >
                                                        Frekvencia realizácie
                                                    </label>

                                                    <Select
                                                        :id="
                                                            `frequency-${index}`
                                                        "
                                                        v-model="
                                                            row.frequency
                                                        "
                                                        :options="
                                                            frequencyOptionsFor(
                                                                row.procedure
                                                                    ?.code,
                                                            )
                                                        "
                                                        optionLabel="label"
                                                        optionValue="value"
                                                        placeholder="
                                                            Vyberte frekvenciu
                                                        "
                                                        class="
                                                            w-full
                                                            border-0!
                                                        "
                                                    />
                                                </div>

                                                <!-- Add -->

                                                <AppButton
                                                    v-if="
                                                        index ===
                                                        procedures.length -
                                                            1
                                                    "
                                                    type="button"
                                                    variant="accent"
                                                    expand-on-hover
                                                    label="
                                                        Pridať výkon
                                                    "
                                                    icon="
                                                        bi
                                                        bi-plus-lg
                                                    "
                                                    aria-label="
                                                        Pridať výkon
                                                    "
                                                    @click="
                                                        addProcedure
                                                    "
                                                />

                                                <!-- Remove -->

                                                <AppButton
                                                    v-else
                                                    type="button"
                                                    variant="danger"
                                                    icon="
                                                        bi
                                                        bi-eraser
                                                    "
                                                    label="
                                                        Odstrániť výkon
                                                    "
                                                    expand-on-hover
                                                    aria-label="
                                                        Odstrániť výkon
                                                    "
                                                    @click="
                                                        removeProcedure(
                                                            index,
                                                        )
                                                    "
                                                />
                                            </div>

                                            <!-- Proposal rule issues -->

                                            <div
                                                v-if="
                                                    issuesForProcedure(
                                                        row,
                                                    ).length
                                                "
                                                class="
                                                    flex
                                                    w-full
                                                    flex-col
                                                    gap-1
                                                "
                                            >
                                                <p
                                                    v-for="issue in issuesForProcedure(
                                                        row,
                                                    )"
                                                    :key="
                                                        issueKey(
                                                            issue,
                                                        )
                                                    "
                                                    class="
                                                        m-0
                                                        text-mini
                                                    "
                                                    :class="
                                                        issueTextClass(
                                                            issue,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        issue.message
                                                    }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </FormField>

                                <!-- Duration -->

                                <FormField
                                    label="Predpokladaná dĺžka ošetrovateľskej starostlivosti"
                                    required
                                    :error="
                                        submitted
                                            ? errors.expectedDuration
                                            : null
                                    "
                                    class="
                                        col-span-12
                                    "
                                >
                                    <div
                                        class="
                                            flex
                                            flex-col
                                            gap-2
                                        "
                                    >
                                        <div
                                            v-for="(
                                                option,
                                                index
                                            ) in durationOptions"
                                            :key="
                                                option.value
                                            "
                                            class="
                                                flex
                                                items-center
                                                gap-2
                                            "
                                        >
                                            <RadioButton
                                                v-model="
                                                    expectedDuration
                                                "
                                                :inputId="
                                                    `duration-${index}`
                                                "
                                                :value="
                                                    option.value
                                                "
                                            />

                                            <label
                                                :for="
                                                    `duration-${index}`
                                                "
                                                class="
                                                    cursor-pointer
                                                    text-normal
                                                "
                                            >
                                                {{
                                                    option.label
                                                }}
                                            </label>
                                        </div>
                                    </div>
                                </FormField>
                            </div>

                            <!-- ======================================================== -->
                            <!-- ADMINISTRATÍVA                                           -->
                            <!-- ======================================================== -->

                            <div
                                class="
                                    grid
                                    grid-cols-12
                                    gap-4
                                "
                            >
                                <div
                                    class="
                                        col-span-12
                                    "
                                >
                                    <label
                                        class="
                                            block
                                            text-normal
                                            text-accent
                                        "
                                    >
                                        Administratíva
                                    </label>
                                </div>

                                <!-- Date -->

                                <FormField
                                    label="Dátum"
                                    required
                                    :error="
                                        submitted
                                            ? errors.date
                                            : null
                                    "
                                    class="
                                        col-span-12
                                    "
                                    v-slot="{
                                        id,
                                        describedBy,
                                        invalid,
                                    }"
                                >
                                    <DatePicker
                                        :id="id"
                                        v-model="date"
                                        dateFormat="dd.mm.yy"
                                        :showIcon="false"
                                        :maxDate="maxSelectableDate"
                                        :invalid="invalid"
                                        :aria-invalid="invalid || undefined"
                                        :aria-describedby="describedBy"
                                        class="w-full"
                                        inputClass="w-full! cursor-pointer! border-none!"
                                    />
                                </FormField>

                                <div
                                    v-if="
                                        proposalValidationFailure ||
                                        generalProposalIssues.length
                                    "
                                    class="
                                        col-span-12
                                        flex
                                        flex-col
                                        gap-1
                                    "
                                    role="status"
                                >
                                    <p
                                        v-if="proposalValidationFailure"
                                        class="
                                            m-0
                                            text-mini
                                            text-danger
                                        "
                                    >
                                        {{ proposalValidationFailure }}
                                    </p>

                                    <p
                                        v-for="issue in generalProposalIssues"
                                        :key="
                                            issueKey(
                                                issue,
                                            )
                                        "
                                        class="
                                            m-0
                                            text-mini
                                        "
                                        :class="
                                            issueTextClass(
                                                issue,
                                            )
                                        "
                                    >
                                        {{ issue.message }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="
                                    flex
                                    shrink-0
                                    items-center
                                    gap-2
                                    justify-end
                                "
                            >
                                <AdonisButton
                                    v-if="hasScanDocument"
                                    variant="accent"
                                    :loading="loadingOcrPrefill"
                                    @click="
                                        prefillFromLatestScanWithAi
                                    "
                                />

                                <AppButton
                                    type="submit"
                                    variant="accent"
                                    label="Generovať dokument"
                                    :loading="isGenerating"
                                    loading-label="Generujem"
                                    :disabled="!patientId"
                                />
                            </div>
                        </div>
                      </div>

                      

                  </section>
                </form>
            </aside>

            <!-- ============================================================ -->
            <!-- RIGHT: PREVIEW                                               -->
            <!-- ============================================================ -->

            <section
                class="
                    h-full
                    min-h-0
                    min-w-0
                    overflow-hidden
                    rounded-md
                "
                aria-label="
                    Náhľad dokumentu
                "
            >
                <DocumentPreviewViewer
                    :html="previewHtml"
                    :loading="isPreviewLoading"
                    :error="previewError"
                    title="Náhľad návrhu ošetrovateľskej starostlivosti"
                />
            </section>
        </div>
    </div>
</template>