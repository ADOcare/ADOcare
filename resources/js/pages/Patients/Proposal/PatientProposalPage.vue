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

import api from '@/services/api'

import { usePatientStore } from '@/stores/patientStore'

import DocumentAlert from '@/components/DocumentAlert.vue'
import AdonisButton from '@/components/AdonisButton.vue'
import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'

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

const planScrollRefs =
    ref<
        Record<
            string,
            HTMLElement
        >
    >({})

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

const previewPageRef =
    ref<HTMLElement | null>(
        null,
    )

const A4_WIDTH_PX =
    210 / 25.4 * 96

const A4_HEIGHT_PX =
    297 / 25.4 * 96

const previewScale =
    ref(1)

const previewFrameStyle =
    computed(() => ({
        width:
            `${A4_WIDTH_PX}px`,

        height:
            `${A4_HEIGHT_PX}px`,

        transform:
            `scale(${previewScale.value})`,

        transformOrigin:
            'top left',
    }))

let previewResizeObserver:
    ResizeObserver |
    null = null

let previewTimer:
    ReturnType<typeof setTimeout> |
    null = null

let previewRequestId =
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
            'V pracovné dni',
        value:
            'weekdays',
    },
    {
        label:
            'Počas víkendov a sviatkov',
        value:
            'weekends',
    },
    {
        label:
            'Denne',
        value:
            'daily',
    },
    {
        label:
            'Každý druhý deň',
        value:
            'every_other_day',
    },
    {
        label:
            '3x týždenne',
        value:
            'three_times_weekly',
    },
    {
        label:
            '2x týždenne',
        value:
            'twice_weekly',
    },
    {
        label:
            '1x týždenne',
        value:
            'once_weekly',
    },
    {
        label:
            '2x mesačne',
        value:
            'twice_monthly',
    },
    {
        label:
            '1x mesačne',
        value:
            'once_monthly',
    },
    {
        label:
            'Podľa potreby',
        value:
            'as_needed',
    },
]

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

        'Každý druhý deň':
            'every_other_day',

        '3x týždenne':
            'three_times_weekly',

        '2x týždenne':
            'twice_weekly',

        '1x týždenne':
            'once_weekly',

        '2x mesačne':
            'twice_monthly',

        '1x mesačne':
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
                        normalizeFrequencyToEnum(
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
                        normalizeFrequencyToEnum(
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
    try {
        const query =
            (
                event?.query ??
                ''
            ).trim()

        const response =
            await api.get(
                '/v1/diagnoses',
                {
                    params: {
                        q:
                            query,

                        per_page:
                            25,

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
    try {
        const query =
            (
                event?.query ??
                ''
            ).trim()

        const response =
            await api.get(
                '/v1/nurse-diagnoses',
                {
                    params: {
                        q:
                            query,

                        per_page:
                            25,

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
    try {
        const query =
            (
                event?.query ??
                ''
            ).trim()

        const response =
            await api.get(
                '/v1/procedures',
                {
                    params: {
                        q:
                            query,

                        per_page:
                            25,

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

function setMacroScrollRef(
    id: string,
) {
    return (
        element:
            Element |
            ComponentPublicInstance |
            null,
    ) => {
        if (
            element instanceof
            HTMLElement
        ) {
            planScrollRefs.value[
                id
            ] =
                element
        }
    }
}

function scrollMacros(
    id: string,
    direction: number,
) {
    const element =
        planScrollRefs.value[
            id
        ]

    if (!element) {
        return
    }

    element.scrollBy({
        left:
            direction *
            200,

        behavior:
            'smooth',
    })
}

function appendToSectionText(
    text: string,
) {
    if (carePlan.value) {
        carePlan.value +=
            `\n${text}`
    } else {
        carePlan.value =
            text
    }
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

    if (
        previewPageRef.value &&
        typeof ResizeObserver !==
            'undefined'
    ) {
        previewResizeObserver =
            new ResizeObserver(
                ([entry]) => {
                    const width =
                        entry
                            ?.contentRect
                            .width ??
                        0

                    if (
                        width >
                        0
                    ) {
                        previewScale.value =
                            width /
                            A4_WIDTH_PX
                    }
                },
            )

        previewResizeObserver.observe(
            previewPageRef.value,
        )
    }
})

onBeforeUnmount(() => {
    previewResizeObserver
        ?.disconnect()

    previewResizeObserver =
        null

    if (previewTimer) {
        clearTimeout(
            previewTimer,
        )
    }

    previewRequestId++
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

    <div
        class="
            h-full
            min-h-0
            overflow-hidden
        "
    >
        <div
            class="
                grid
                h-full
                min-h-0
                grid-cols-2
            "
        >
            <!-- ============================================================ -->
            <!-- LEFT: FORM                                                   -->
            <!-- ============================================================ -->

            <aside
                class="
                    min-h-0
                    overflow-hidden
                    pr-6
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
                                p-6
                            "
                        >
                            <div
                                class="
                                    flex
                                    flex-col
                                    gap-6
                                "
                            >
                                <!-- Medical diagnosis -->

                                <FormField
                                    label="Lekárska diagnóza"
                                    required
                                    :error="
                                        submitted
                                            ? errors.medicalDiagnosis
                                            : null
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
                                        class="w-full"
                                        inputClass="
                                            w-full!
                                            shadow-none!
                                            bg-white!
                                            focus:ring-0!
                                            focus:shadow-none!
                                            border-0!
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
                                                    text-lightgrey
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
                                    label="Sesterská diagnóza"
                                    required
                                    :error="
                                        submitted
                                            ? errors.nurseDiagnosis
                                            : null
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
                                        class="w-full"
                                        inputClass="
                                            w-full!
                                            shadow-none!
                                            bg-white!
                                            focus:ring-0!
                                            focus:shadow-none!
                                            border-0!
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
                                                    text-lightgrey
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

                                <!-- Date -->

                                <FormField
                                    label="Dátum"
                                    required
                                    :error="
                                        submitted
                                            ? errors.date
                                            : null
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
                                        :maxDate="
                                            maxSelectableDate
                                        "
                                        :invalid="
                                            invalid
                                        "
                                        :aria-invalid="
                                            invalid ||
                                            undefined
                                        "
                                        :aria-describedby="
                                            describedBy
                                        "
                                        class="w-full"
                                        inputClass="
                                            w-full!
                                            cursor-pointer!
                                            border-none!
                                        "
                                    />
                                </FormField>

                                <!-- Epicrisis -->

                                <FormField
                                    label="Epizóka a zdôvodnenie pre poskytovanie ošetrovateľskej starostlivosti"
                                    required
                                    :error="
                                        submitted
                                            ? errors.epicrisisDescription
                                            : null
                                    "
                                >
                                    <Textarea
                                        v-model="
                                            epicrisisDescription
                                        "
                                        class="
                                            w-full
                                            border-none!
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
                                >
                                    <Textarea
                                        v-model="
                                            carePlan
                                        "
                                        class="
                                            w-full
                                            border-none!
                                        "
                                        rows="4"
                                        :invalid="
                                            submitted &&
                                            !!errors.carePlan
                                        "
                                    />
                                </FormField>

                                <!-- Plans -->

                                <div class="w-full">
                                    <label
                                        class="
                                            mb-2
                                            block
                                            text-normal
                                        "
                                    >
                                        Plány starostlivosti
                                    </label>

                                    <div
                                        v-if="
                                            !plansLoading &&
                                            !plans.length
                                        "
                                        class="
                                            opacity-70
                                        "
                                    >
                                        Nemáte žiadne plány. Skúste opustiť stránku a znova sa načítať.
                                    </div>

                                    <div
                                        v-else
                                        class="
                                            relative
                                        "
                                    >
                                        <AppButton
                                            type="button"
                                            variant="text"
                                            icon="
                                                bi
                                                bi-chevron-left
                                            "
                                            aria-label="
                                                Doľava
                                            "
                                            class="
                                                absolute
                                                left-0
                                                top-1/2
                                                z-10
                                                w-7!
                                                min-w-7!
                                                -translate-y-1/2
                                                px-0!
                                            "
                                            @click="
                                                scrollMacros(
                                                    'care-plan',
                                                    -1,
                                                )
                                            "
                                        />

                                        <AppButton
                                            type="button"
                                            variant="text"
                                            icon="
                                                bi
                                                bi-chevron-right
                                            "
                                            aria-label="
                                                Doprava
                                            "
                                            class="
                                                absolute
                                                right-0
                                                top-1/2
                                                z-10
                                                w-7!
                                                min-w-7!
                                                -translate-y-1/2
                                                px-0!
                                            "
                                            @click="
                                                scrollMacros(
                                                    'care-plan',
                                                    1,
                                                )
                                            "
                                        />

                                        <div
                                            :ref="
                                                setMacroScrollRef(
                                                    'care-plan',
                                                )
                                            "
                                            class="
                                                mb-2
                                                flex
                                                gap-2
                                                overflow-x-auto
                                                scroll-smooth
                                                whitespace-nowrap
                                                px-10
                                                py-3
                                            "
                                            style="
                                                scrollbar-width: thin;
                                            "
                                        >
                                            <AppButton
                                                v-for="
                                                    plan
                                                    in plans
                                                "
                                                :key="
                                                    plan.id
                                                "
                                                type="button"
                                                variant="accent"
                                                :label="
                                                    plan.name
                                                "
                                                :title="
                                                    plan.text
                                                "
                                                @click="
                                                    appendToSectionText(
                                                        plan.text,
                                                    )
                                                "
                                            />
                                        </div>
                                    </div>
                                </div>

                                <!-- Mobility -->

                                <div>
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

                                <!-- Duration -->

                                <FormField
                                    label="Predpokladaná dĺžka ošetrovateľskej starostlivosti"
                                    required
                                    :error="
                                        submitted
                                            ? errors.expectedDuration
                                            : null
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

                                <!-- Procedures -->

                                <FormField
                                    label="Výkony a frekvencia realizácie"
                                    required
                                    :error="
                                        submitted
                                            ? errors.procedures
                                            : null
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
                                                row,
                                                index
                                            ) in procedures"
                                            :key="
                                                index
                                            "
                                            class="
                                                flex
                                                items-end
                                                gap-4
                                            "
                                        >
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
                                                    "
                                                    inputClass="
                                                        w-full!
                                                        shadow-none!
                                                        bg-white!
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
                                                        frequencyOptions
                                                    "
                                                    optionLabel="label"
                                                    optionValue="value"
                                                    placeholder="
                                                        Vyberte frekvenciu
                                                    "
                                                    class="
                                                        w-full
                                                        border-none!
                                                    "
                                                />
                                            </div>

                                            <AppButton
                                                v-if="
                                                    index ===
                                                    procedures.length -
                                                        1
                                                "
                                                type="button"
                                                variant="accent"
                                                icon="
                                                    bi
                                                    bi-plus
                                                "
                                                aria-label="
                                                    Pridať výkon
                                                "
                                                class="
                                                    w-7!
                                                    min-w-7!
                                                    px-0!
                                                "
                                                @click="
                                                    addProcedure
                                                "
                                            />

                                            <AppButton
                                                v-else
                                                type="button"
                                                variant="danger"
                                                icon="
                                                    bi
                                                    bi-eraser
                                                "
                                                aria-label="
                                                    Odstrániť výkon
                                                "
                                                class="
                                                    w-7!
                                                    min-w-7!
                                                    px-0!
                                                "
                                                @click="
                                                    removeProcedure(
                                                        index,
                                                    )
                                                "
                                            />
                                        </div>
                                    </div>
                                </FormField>
                            </div>
                        </div>

                        <!-- FIXED FORM ACTIONS -->
                        <div
                            class="
                                flex
                                shrink-0
                                items-center
                                justify-end
                                gap-2
                                p-6
                                pt-4
                            "
                        >
                            <AdonisButton
                                v-if="
                                    hasScanDocument
                                "
                                :loading="
                                    loadingOcrPrefill
                                "
                                @click="
                                    prefillFromLatestScanWithAi
                                "
                            />

                            <AppButton
                                type="submit"
                                variant="accent"
                                label="
                                    Generovať dokument
                                "
                                :loading="
                                    isGenerating
                                "
                                loading-label="
                                    Generujem
                                "
                                :disabled="
                                    !patientId
                                "
                            />
                        </div>
                    </section>
                </form>
            </aside>

            <!-- ============================================================ -->
            <!-- RIGHT: PREVIEW                                               -->
            <!-- ============================================================ -->

            <section
                class="
                    min-h-0
                    min-w-0
                    overflow-hidden
                    border-l
                    border-darkgrey/20
                    pl-6
                "
                aria-label="
                    Náhľad dokumentu
                "
            >
                <div
                    class="
                        flex
                        h-full
                        min-h-0
                        flex-col
                    "
                >
                    <div
                        class="
                            min-h-0
                            flex-1
                            overflow-y-auto
                            bg-white
                            px-6
                            pb-6
                        "
                    >
                        <div
                            class="
                                flex
                                min-h-full
                                justify-center
                            "
                        >
                            <div
                                ref="
                                    previewPageRef
                                "
                                class="
                                    relative
                                    aspect-[210/297]
                                    w-full
                                    max-w-3xl
                                    self-start
                                    overflow-hidden
                                    border
                                    border-almostwhite/50
                                    bg-white
                                    shadow-lg
                                "
                            >
                                <iframe
                                    v-if="
                                        previewHtml
                                    "
                                    :srcdoc="
                                        previewHtml
                                    "
                                    title="
                                        Náhľad návrhu ošetrovateľskej starostlivosti
                                    "
                                    :style="
                                        previewFrameStyle
                                    "
                                    class="
                                        absolute
                                        left-0
                                        top-0
                                        border-0
                                    "
                                />

                                <div
                                    v-else
                                    class="
                                        flex
                                        h-full
                                        items-center
                                        justify-center
                                        p-8
                                        text-center
                                        text-normal
                                        text-darkgrey
                                    "
                                >
                                    {{
                                        previewError ??
                                        'Náhľad dokumentu'
                                    }}
                                </div>

                                <div
                                    v-if="
                                        isPreviewLoading
                                    "
                                    class="
                                        absolute
                                        inset-0
                                        flex
                                        items-center
                                        justify-center
                                        bg-white/75
                                    "
                                >
                                    <i
                                        class="
                                            bi
                                            bi-arrow-repeat
                                            animate-spin
                                            text-xl
                                            text-accent
                                        "
                                        aria-label="
                                            Aktualizujem náhľad
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>