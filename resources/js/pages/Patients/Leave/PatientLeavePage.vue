<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue'

import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Checkbox from 'primevue/checkbox'

import api from '@/services/api'

import { usePatientStore } from '@/stores/patientStore'
import { useAuthStore } from '@/stores/auth'

import AppButton from '@/components/AppButton.vue'
import DocumentAlert from '@/components/DocumentAlert.vue'
import DocumentPreviewViewer from '@/components/DocumentPreviewViewer.vue'
import FormField from '@/components/FormField.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'

/* -------------------------------------------------------------------------- */
/* Stores                                                                     */
/* -------------------------------------------------------------------------- */

const router =
    useRouter()

const toast =
    useToast()

const patientStore =
    usePatientStore()

const authStore =
    useAuthStore()

patientStore.loadFromStorage?.()

const patientId =
    computed(
        () =>
            patientStore.current
                ?.id ??
            0,
    )

const branchId =
    computed(
        () =>
            authStore.currentBranch
                ?.id ??
            patientStore.current
                ?.branch_id ??
            null,
    )

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

const isGenerating =
    ref(false)

const date =
    ref<Date>(
        new Date(),
    )

const selectedProblems =
    ref<string[]>([])

const otherFindings =
    ref('')

const results =
    ref('')

const education =
    ref('')

const received =
    ref('')

/* -------------------------------------------------------------------------- */
/* Existing document                                                          */
/* -------------------------------------------------------------------------- */

const documentId =
    ref<number | null>(
        null,
    )

const dialogVisible =
    ref(false)

/* -------------------------------------------------------------------------- */
/* Last patient point                                                         */
/* -------------------------------------------------------------------------- */

const lastPatientPointLoading =
    ref(false)

let lastPatientPointLoadSeq =
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
    ReturnType<
        typeof setTimeout
    > |
    null = null

let previewRequestId =
    0

/* -------------------------------------------------------------------------- */
/* Options                                                                    */
/* -------------------------------------------------------------------------- */

const problemOptions = [
    {
        label:
            'výživy',

        value:
            'nutrition',
    },
    {
        label:
            'mobility',

        value:
            'mobility',
    },
    {
        label:
            'vylučovania/vyprázdňovania',

        value:
            'elimination',
    },
    {
        label:
            'aplikácie s. c. inj.',

        value:
            'injections',
    },
    {
        label:
            'hygieny',

        value:
            'hygiene',
    },
    {
        label:
            'starostlivosti o ranu',

        value:
            'wound_care',
    },
    {
        label:
            'iné zistenia',

        value:
            'other_findings',
    },
]

const showOtherFindings =
    computed(
        () =>
            selectedProblems.value.includes(
                'other_findings',
            ),
    )

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function unwrapApiData(
    payload: any,
) {
    return (
        payload?.data?.data ??
        payload?.data ??
        payload ??
        {}
    )
}

function toIsoDateTime(
    value: Date,
) {
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

    const hours =
        String(
            value.getHours(),
        ).padStart(
            2,
            '0',
        )

    const minutes =
        String(
            value.getMinutes(),
        ).padStart(
            2,
            '0',
        )

    const seconds =
        String(
            value.getSeconds(),
        ).padStart(
            2,
            '0',
        )

    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`
}

function toLocalYMD(
    value: Date,
) {
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

function formatDateForText(
    value: Date,
) {
    const day =
        String(
            value.getDate(),
        ).padStart(
            2,
            '0',
        )

    const month =
        String(
            value.getMonth() +
                1,
        ).padStart(
            2,
            '0',
        )

    const year =
        value.getFullYear()

    return `${day}.${month}.${year}`
}

/* -------------------------------------------------------------------------- */
/* Payload                                                                    */
/* -------------------------------------------------------------------------- */

function buildLeavePayload() {
    return {
        patient_id:
            patientId.value,

        date:
            toIsoDateTime(
                date.value,
            ),

        problems:
            selectedProblems.value,

        other_findings:
            otherFindings.value,

        results:
            results.value,

        education:
            education.value,

        received:
            received.value,

        branch_id:
            branchId.value,
    }
}

/* -------------------------------------------------------------------------- */
/* Existing document                                                          */
/* -------------------------------------------------------------------------- */

async function checkDocumentExists():
    Promise<
        boolean |
        null
    > {
    if (
        !patientId.value ||
        !date.value
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
                        'leave',

                    date:
                        toLocalYMD(
                            date.value,
                        ),

                    patient_id:
                        patientId.value,
                },
            )

        const result =
            unwrapApiData(
                response,
            )

        const exists =
            result.exists ??
            false

        documentId.value =
            result.document_id ??
            null

        dialogVisible.value =
            exists

        return exists
    } catch (error) {
        console.error(
            'Failed to check document existence:',
            error,
        )

        documentId.value =
            null

        dialogVisible.value =
            false

        return null
    }
}

/* -------------------------------------------------------------------------- */
/* Last patient point                                                         */
/* -------------------------------------------------------------------------- */

async function loadLastPatientPointDate() {
    const sequence =
        ++lastPatientPointLoadSeq

    lastPatientPointLoading.value =
        true

    if (
        !patientId.value
    ) {
        results.value =
            ''

        lastPatientPointLoading.value =
            false

        return
    }

    try {
        const {
            data,
        } =
            await api.get(
                'v1/patient-points',
                {
                    params: {
                        filter: {
                            patient_id:
                                patientId.value,
                        },

                        per_page:
                            1,

                        page:
                            1,

                        sort:
                            '-date',
                    },
                },
            )

        if (
            sequence !==
            lastPatientPointLoadSeq
        ) {
            return
        }

        let records:
            any[] = []

        if (
            Array.isArray(
                data,
            )
        ) {
            records =
                data
        } else if (
            data?.data
        ) {
            records =
                Array.isArray(
                    data.data,
                )
                    ? data.data
                    : Array.isArray(
                          data.data
                              ?.items,
                      )
                      ? data.data.items
                      : []
        } else if (
            Array.isArray(
                data?.items,
            )
        ) {
            records =
                data.items
        }

        if (
            !records.length
        ) {
            results.value =
                ''

            return
        }

        const latestPoint =
            records[0]

        if (
            !latestPoint
                ?.date
        ) {
            results.value =
                ''

            return
        }

        const lastDate =
            new Date(
                latestPoint.date,
            )

        if (
            Number.isNaN(
                lastDate.getTime(),
            )
        ) {
            return
        }

        results.value =
            `${formatDateForText(
                lastDate,
            )} ukončená ošetrovateľská starostlivosť`
    } catch (error) {
        if (
            sequence !==
            lastPatientPointLoadSeq
        ) {
            return
        }

        console.error(
            'Failed to load last patient point date:',
            error,
        )
    } finally {
        if (
            sequence ===
            lastPatientPointLoadSeq
        ) {
            lastPatientPointLoading.value =
                false
        }
    }
}

/* -------------------------------------------------------------------------- */
/* Preview                                                                    */
/* -------------------------------------------------------------------------- */

async function loadPreview() {
    if (
        !patientId.value ||
        !branchId.value ||
        !date.value
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

    previewError.value =
        null

    try {
        const response =
            await api.post(
                '/v1/leave-documents/draft-preview',
                buildLeavePayload(),
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
            'Failed to load leave preview:',
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
    if (
        previewTimer
    ) {
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

/* -------------------------------------------------------------------------- */
/* Existing document dialog                                                   */
/* -------------------------------------------------------------------------- */

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
/* Validation                                                                 */
/* -------------------------------------------------------------------------- */

function validateForm() {
    const validationErrors:
        Record<
            string,
            string
        > = {}

    if (
        !patientId.value
    ) {
        validationErrors.patient =
            'Pacient nie je vybratý.'
    }

    if (
        !date.value ||
        Number.isNaN(
            date.value.getTime(),
        )
    ) {
        validationErrors.date =
            'Dátum je povinný.'
    }

    if (
        showOtherFindings.value &&
        !otherFindings.value.trim()
    ) {
        validationErrors.otherFindings =
            'Vyplňte iné zistenia.'
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
/* Generate                                                                   */
/* -------------------------------------------------------------------------- */

async function generateDocument(
    options: {
        duplicateConfirmed?:
            boolean
    } = {},
) {
    if (
        isGenerating.value
    ) {
        return
    }

    submitted.value =
        true

    if (
        !validateForm()
    ) {
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

        const response =
            await api.post(
                '/v1/leave-documents',
                buildLeavePayload(),
            )

        const result =
            unwrapApiData(
                response,
            )

        const generatedDocumentId =
            result.document_id ??
            result.id ??
            result.document?.id ??
            null

        toast.add({
            severity:
                'success',

            summary:
                'Úspešne',

            detail:
                'Dokument bol vytvorený.',

            life:
                3000,
        })

        if (
            generatedDocumentId
        ) {
            await router.push({
                name:
                    'documents-leave',

                params: {
                    documentId:
                        generatedDocumentId,
                },
            })
        }
    } catch (error: any) {
        console.error(
            'Failed to generate leave document:',
            error,
        )

        const message =
            error?.response
                ?.data
                ?.message ??
            error?.message ??
            'Chyba pri vytváraní dokumentu.'

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

    () => {
        void loadLastPatientPointDate()
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
            branchId.value,

        () =>
            date.value,

        () =>
            selectedProblems.value,

        () =>
            otherFindings.value,

        () =>
            results.value,

        () =>
            education.value,

        () =>
            received.value,
    ],

    schedulePreview,

    {
        deep:
            true,

        immediate:
            true,
    },
)

watch(
    showOtherFindings,

    visible => {
        if (
            visible
        ) {
            return
        }

        otherFindings.value =
            ''

        delete errors.value
            .otherFindings
    },
)

/* -------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* -------------------------------------------------------------------------- */

onBeforeUnmount(
    () => {
        if (
            previewTimer
        ) {
            clearTimeout(
                previewTimer,
            )
        }

        previewRequestId++

        lastPatientPointLoadSeq++
    },
)
</script>

<template>
    <DocumentAlert
        :visible="
            dialogVisible
        "
        :document-id="
            documentId
        "
        document-url="
            /documents/leave/{id}
        "
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
                                <!-- STAV PRI PREPUSTENÍ                                     -->
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
                                            Stav pri prepustení
                                        </label>
                                    </div>

                                    <!-- Problems -->

                                    <FormField
                                        label="Pretrvávanie problémov pri prepustení v oblasti sebaopatery"
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
                                            <label
                                                v-for="(
                                                    option,
                                                    index
                                                ) in problemOptions"
                                                :key="
                                                    option.value
                                                "
                                                :for="
                                                    `leave-problem-${index}`
                                                "
                                                class="
                                                    flex
                                                    cursor-pointer
                                                    items-center
                                                    gap-2
                                                "
                                            >
                                                <Checkbox
                                                    v-model="
                                                        selectedProblems
                                                    "
                                                    :input-id="
                                                        `leave-problem-${index}`
                                                    "
                                                    :value="
                                                        option.value
                                                    "
                                                />

                                                <span
                                                    class="
                                                        text-normal
                                                    "
                                                >
                                                    {{
                                                        option.label
                                                    }}
                                                </span>
                                            </label>
                                        </div>
                                    </FormField>

                                    <!-- Other findings -->

                                    <FormField
                                        v-if="
                                            showOtherFindings
                                        "
                                        v-model="
                                            otherFindings
                                        "
                                        control="textarea"
                                        label="
                                            Iné zistenia
                                        "
                                        required
                                        :error="
                                            submitted
                                                ? errors.otherFindings
                                                : null
                                        "
                                        :outlined="
                                            false
                                        "
                                        :rows="
                                            2
                                        "
                                        auto-resize
                                        control-class="
                                            resize-none!
                                            bg-white!
                                        "
                                        class="
                                            col-span-12
                                        "
                                    />
                                </div>

                                <!-- ======================================================== -->
                                <!-- VYHODNOTENIE STAROSTLIVOSTI                             -->
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
                                            Vyhodnotenie starostlivosti
                                        </label>
                                    </div>

                                    <!-- Results -->

                                    <div
                                        class="
                                            relative
                                            col-span-12
                                        "
                                    >
                                        <LoadingOverlay
                                            :show="
                                                lastPatientPointLoading
                                            "
                                            text="
                                                Načítavam posledný záznam výkonov...
                                            "
                                        />

                                        <FormField
                                            v-model="
                                                results
                                            "
                                            control="textarea"
                                            label="
                                                Vyhodnotenie výsledkov ošetrovateľskej starostlivosti
                                            "
                                            :disabled="
                                                lastPatientPointLoading
                                            "
                                            :outlined="
                                                false
                                            "
                                            :rows="
                                                2
                                            "
                                            auto-resize
                                            control-class="
                                                resize-none!
                                                bg-white!
                                            "
                                        />
                                    </div>

                                    <!-- Education -->

                                    <FormField
                                        v-model="
                                            education
                                        "
                                        control="textarea"
                                        label="
                                            Realizovaná edukácia o
                                        "
                                        :outlined="
                                            false
                                        "
                                        :rows="
                                            2
                                        "
                                        auto-resize
                                        control-class="
                                            resize-none!
                                            bg-white!
                                        "
                                        class="
                                            col-span-12
                                        "
                                    />

                                    <!-- Received -->

                                    <FormField
                                        v-model="
                                            received
                                        "
                                        control="textarea"
                                        label="
                                            Pacient pri ukončení hospitalizácie prevzal
                                        "
                                        :outlined="
                                            false
                                        "
                                        :rows="
                                            2
                                        "
                                        auto-resize
                                        control-class="
                                            resize-none!
                                            bg-white!
                                        "
                                        class="
                                            col-span-12
                                        "
                                    />
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
                                        v-model="
                                            date
                                        "
                                        control="date"
                                        label="
                                            Dátum
                                        "
                                        required
                                        :error="
                                            submitted
                                                ? errors.date
                                                : null
                                        "
                                        :outlined="
                                            false
                                        "
                                        date-format="
                                            dd.mm.yy
                                        "
                                        time-format="
                                            HH:mm
                                        "
                                        show-time
                                        :show-icon="
                                            false
                                        "
                                        input-class="
                                            w-full!
                                            cursor-pointer!
                                            border-none!
                                        "
                                        control-class="
                                            w-full
                                            bg-white!
                                        "
                                        class="
                                            col-span-12
                                        "
                                    />
                                </div>

                                <!-- ======================================================== -->
                                <!-- ACTIONS                                                  -->
                                <!-- ======================================================== -->

                                <div
                                    class="
                                        flex
                                        shrink-0
                                        items-center
                                        justify-end
                                        gap-2
                                    "
                                >
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
                    :html="
                        previewHtml
                    "
                    :loading="
                        isPreviewLoading
                    "
                    :error="
                        previewError
                    "
                    title="
                        Náhľad prepúšťacej správy
                    "
                />
            </section>
        </div>
    </div>
</template>