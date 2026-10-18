<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue'

import { storeToRefs } from 'pinia'

import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'

import AppButton from '@/components/AppButton.vue'
import DocumentAlert from '@/components/DocumentAlert.vue'
import DocumentPreviewViewer from '@/components/DocumentPreviewViewer.vue'
import FormField from '@/components/FormField.vue'

import api from '@/services/api'

import { useAuthStore } from '@/stores/auth'
import { usePatientStore } from '@/stores/patientStore'

/* -------------------------------------------------------------------------- */
/* Stores                                                                     */
/* -------------------------------------------------------------------------- */

const router =
    useRouter()

const toast =
    useToast()

const authStore =
    useAuthStore()

const patientStore =
    usePatientStore()

patientStore.loadFromStorage()

const {
    currentBranch,
} =
    storeToRefs(
        authStore,
    )

const {
    current:
        currentPatient,
} =
    storeToRefs(
        patientStore,
    )

/* -------------------------------------------------------------------------- */
/* Patient                                                                    */
/* -------------------------------------------------------------------------- */

const patientId =
    computed(
        () =>
            currentPatient.value
                ?.id ??
            null,
    )

const branchId =
    computed(
        () =>
            currentBranch.value
                ?.id ??
            currentPatient.value
                ?.branch_id ??
            null,
    )

const patientDeathDate =
    computed(
        () =>
            currentPatient.value
                ?.death_date ??
            null,
    )

/* -------------------------------------------------------------------------- */
/* State                                                                      */
/* -------------------------------------------------------------------------- */

const date =
    ref<Date>(
        new Date(),
    )

const submitted =
    ref(false)

const isGenerating =
    ref(false)

const documentExists =
    ref(false)

const documentId =
    ref<number | null>(
        null,
    )

const dialogVisible =
    ref(false)

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

/* -------------------------------------------------------------------------- */
/* Date helpers                                                               */
/* -------------------------------------------------------------------------- */

function toLocalYMD(
    value: Date,
): string {
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

const deathDateYmd =
    computed(() => {
        const value =
            patientDeathDate.value

        return (
            typeof value ===
                'string' &&
            value
                ? value.slice(
                    0,
                    10,
                )
                : null
        )
    })

const maxSelectableDate =
    computed<Date | undefined>(
        () => {
            if (
                !deathDateYmd.value
            ) {
                return undefined
            }

            const value =
                new Date(
                    `${deathDateYmd.value}T00:00:00`,
                )

            if (
                Number.isNaN(
                    value.getTime(),
                )
            ) {
                return undefined
            }

            return new Date(
                value.getFullYear(),
                value.getMonth(),
                value.getDate(),
            )
        },
    )

const isDateAfterDeath =
    computed(
        () =>
            !!date.value &&
            !!deathDateYmd.value &&
            toLocalYMD(
                date.value,
            ) >
                deathDateYmd.value,
    )

const dateError =
    computed(() => {
        if (
            !submitted.value
        ) {
            return null
        }

        if (
            !date.value
        ) {
            return 'Dátum je povinný.'
        }

        if (
            isDateAfterDeath.value
        ) {
            return 'Dátum dokumentu nemôže byť po dátume úmrtia pacienta.'
        }

        return null
    })

/* -------------------------------------------------------------------------- */
/* Validation                                                                 */
/* -------------------------------------------------------------------------- */

function validateForm():
    boolean {
    return (
        !!patientId.value &&
        !!branchId.value &&
        !!date.value &&
        !isDateAfterDeath.value
    )
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
        !date.value ||
        isDateAfterDeath.value
    ) {
        documentExists.value =
            false

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
                        'agreement',

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

        documentExists.value =
            result?.exists ??
            false

        documentId.value =
            result
                ?.document_id ??
            null

        dialogVisible.value =
            documentExists.value

        return documentExists.value
    } catch (error) {
        console.error(
            'Failed to check agreement existence:',
            error,
        )

        documentExists.value =
            false

        documentId.value =
            null

        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                'Existenciu dokumentu sa nepodarilo overiť.',

            life:
                5000,
        })

        return null
    }
}

/* -------------------------------------------------------------------------- */
/* Preview                                                                    */
/* -------------------------------------------------------------------------- */

async function loadPreview() {
    if (
        !patientId.value ||
        !branchId.value ||
        !date.value ||
        isDateAfterDeath.value
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
            await api.get(
                '/v1/agreements/draft-preview',
                {
                    params: {
                        patient_id:
                            patientId.value,

                        branch_id:
                            branchId.value,

                        date:
                            toLocalYMD(
                                date.value,
                            ),
                    },

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
            'Failed to load agreement preview:',
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
            250,
        )
}

/* -------------------------------------------------------------------------- */
/* Dialog                                                                     */
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
    documentExists.value =
        false

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
        !patientId.value
    ) {
        toast.add({
            severity:
                'error',

            summary:
                'Chýbajúci pacient',

            detail:
                'Najprv vyberte pacienta.',

            life:
                3000,
        })

        return
    }

    if (
        !validateForm()
    ) {
        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                isDateAfterDeath.value
                    ? 'Dátum dokumentu nemôže byť po dátume úmrtia pacienta.'
                    : 'Dokument sa nepodarilo vytvoriť. Skontrolujte zadané údaje.',

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
                '/v1/agreements',
                {
                    patient_id:
                        patientId.value,

                    branch_id:
                        branchId.value,

                    date:
                        toLocalYMD(
                            date.value,
                        ),
                },
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
                'Úspešne vytvorené',

            detail:
                'Dohoda o poskytovaní zdravotnej starostlivosti bola úspešne vytvorená.',

            life:
                3000,
        })

        await router.push({
            name:
                'documents-agreement',

            params: {
                documentId:
                    generatedDocumentId,
            },
        })
    } catch (error: any) {
        console.error(
            'Failed to generate agreement document:',
            error,
        )

        const message =
            error
                ?.response
                ?.data
                ?.errors
                ? Object
                    .values(
                        error
                            .response
                            .data
                            .errors,
                    )
                    .flat()[0]
                : error
                    ?.response
                    ?.data
                    ?.message ??
                error
                    ?.message ??
                'Chyba pri vytváraní dohody.'

        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                String(
                    message,
                ),

            life:
                5000,
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
/* Watch                                                                      */
/* -------------------------------------------------------------------------- */

watch(
    [
        () =>
            date.value,

        () =>
            patientId.value,

        () =>
            branchId.value,
    ],

    schedulePreview,

    {
        immediate:
            true,
    },
)

/* -------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* -------------------------------------------------------------------------- */

onMounted(() => {
    if (
        !currentPatient.value
    ) {
        toast.add({
            severity:
                'warn',

            summary:
                'Chýbajúci pacient',

            detail:
                'Najprv vyberte pacienta.',

            life:
                3000,
        })
    }
})

onBeforeUnmount(() => {
    if (
        previewTimer
    ) {
        clearTimeout(
            previewTimer,
        )
    }

    previewRequestId++
})
</script>

<template>
    <DocumentAlert
        :visible="
            dialogVisible
        "
        :document-id="
            documentId
        "
        document-url="/documents/agreement/{id}"
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
                    min-h-0
                    overflow-hidden
                "
            >
                <form
                    class="
                        flex
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
                        <!-- Scrollable content -->

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
                                <!-- DOHODA                                                   -->
                                <!-- ======================================================== -->

                                <div
                                    class="
                                        grid
                                        grid-cols-12
                                        gap-4
                                    "
                                >
                                    <FormField
                                        label="
                                            Dátum
                                        "
                                        required
                                        :error="
                                            dateError
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
                                </div>

                                <!-- Actions -->

                                <div
                                    class="
                                        flex
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
                                            !patientId ||
                                            !branchId
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
                        Náhľad dohody o poskytovaní zdravotnej starostlivosti
                    "
                />
            </section>
        </div>
    </div>
</template>