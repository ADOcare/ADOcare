<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue'

import { useToast } from 'primevue/usetoast'

import api from '@/services/api'
import { fetchBlobUrl } from '@/utils/blobUrl'

import { useDekurzV2 } from '@/composables/useDekurzV2'
import { usePatientStore } from '@/stores/patientStore'

import type {
    DekurzDay,
    DekurzEntry,
    DekurzProcedure,
    DekurzSupportingDocument,
} from '@/types/dekurz'

import AppButton from '@/components/AppButton.vue'
import DocumentPreviewViewer from '@/components/DocumentPreviewViewer.vue'
import FormField from '@/components/FormField.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import ReferenceDocumentDrawer from '@/components/ReferenceDocumentDrawer.vue'

import DekurzDailyStep from './components/DekurzDailyStep.vue'
import DekurzProcedureTextCard from './components/DekurzProcedureTextCard.vue'
import DekurzReviewStep from './components/DekurzReviewStep.vue'

/* -------------------------------------------------------------------------- */
/* Stores                                                                     */
/* -------------------------------------------------------------------------- */

const toast =
    useToast()

const patientStore =
    usePatientStore()

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

const activeSection =
    ref(0)

const navigating =
    ref(false)

const sections = [
    {
        label:
            'Výkony',

        icon:
            'bi bi-list-check',
    },
    {
        label:
            'Denné záznamy',

        icon:
            'bi bi-calendar3',
    },
    {
        label:
            'Kontrola',

        icon:
            'bi bi-check2-circle',
    },
]

const {
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
} = useDekurzV2(
    patientIdRef,
    monthKey,
)

const isCompleted =
    computed(
        () =>
            builder.value
                ?.draft
                ?.status ===
            'completed',
    )

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

                    days:
                        builder.value?.days.map(
                            day => ({
                                date:
                                    day.date,

                                general_note:
                                    day.note,

                                entries:
                                    day.entries.map(
                                        entry => ({
                                            text:
                                                entry.text,
                                        }),
                                    ),
                            }),
                        ),
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

async function downloadSupportingDocument(
    document: DekurzSupportingDocument,
) {
    if (
        !document.document
            ?.download_url
    ) {
        return
    }

    try {
        const blobUrl =
            await fetchBlobUrl(
                document.document
                    .download_url,
            )

        const anchor =
            window.document.createElement(
                'a',
            )

        anchor.href =
            blobUrl

        anchor.download =
            document.document
                .name ||
            `${document.label}.pdf`

        anchor.click()

        window.setTimeout(
            () =>
                URL.revokeObjectURL(
                    blobUrl,
                ),
            1000,
        )
    } catch (downloadError) {
        console.error(
            'Failed to download supporting document:',
            downloadError,
        )

        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                'Dokument sa nepodarilo stiahnuť.',

            life:
                3000,
        })
    }
}

function formatDocumentDate(
    value: string | null | undefined,
): string {
    if (!value) {
        return 'Dátum nie je dostupný'
    }

    return new Date(
        `${value.slice(0, 10)}T00:00:00`,
    ).toLocaleDateString(
        'sk-SK',
    )
}

/* -------------------------------------------------------------------------- */
/* Navigation                                                                 */
/* -------------------------------------------------------------------------- */

async function goToSection(
    index: number,
) {
    if (
        index ===
            activeSection.value ||
        navigating.value
    ) {
        return
    }

    if (
        isCompleted.value
    ) {
        activeSection.value =
            2

        return
    }

    navigating.value =
        true

    try {
        const savesSucceeded =
            await flushPendingSaves()

        if (!savesSucceeded) {
            return
        }

        if (
            index > 0 &&
            !builder.value?.draft
        ) {
            const initialized =
                await initializeDraft()

            if (!initialized) {
                return
            }
        }

        activeSection.value =
            index

        schedulePreview()
    } finally {
        navigating.value =
            false
    }
}

/* -------------------------------------------------------------------------- */
/* Mutations                                                                  */
/* -------------------------------------------------------------------------- */

function updateTemplateText(
    procedure: DekurzProcedure,
    text: string,
) {
    procedure.effective_text =
        text

    schedulePreview()
}

function updateEntryText(
    entry: DekurzEntry,
    text: string,
) {
    entry.text =
        text

    schedulePreview()
}

function updateDayNote(
    day: DekurzDay,
    note: string,
) {
    day.note =
        note

    schedulePreview()
}

async function handleSaveTemplate(
    procedure: DekurzProcedure,
) {
    await saveTemplate(
        procedure,
    )

    schedulePreview()
}

async function handleResetTemplate(
    procedure: DekurzProcedure,
) {
    await resetTemplate(
        procedure,
    )

    schedulePreview()
}

async function handleSaveEntry(
    entry: DekurzEntry,
) {
    await saveEntry(
        entry,
    )

    schedulePreview()
}

async function handleSaveDay(
    day: DekurzDay,
) {
    await saveDay(
        day,
    )

    schedulePreview()
}

async function handleApplyToUnedited(
    entry: DekurzEntry,
) {
    await applyToUnedited(
        entry,
    )

    schedulePreview()
}

async function handleFinalize() {
    await finalizeDraft()

    await load()

    schedulePreview()
}

/* -------------------------------------------------------------------------- */
/* Reload                                                                     */
/* -------------------------------------------------------------------------- */

async function reloadDekurz() {
    await load()

    schedulePreview()
}

/* -------------------------------------------------------------------------- */
/* Watchers                                                                   */
/* -------------------------------------------------------------------------- */

watch(
    monthKey,
    async () => {
        activeSection.value =
            0

        await load()

        schedulePreview()
    },
)

watch(
    patientIdRef,
    async () => {
        activeSection.value =
            0

        dekurzNumber.value =
            String(
                patientStore.current
                    ?.dekurz_number ??
                    '',
            )

        await load()

        schedulePreview()
    },
)

watch(
    dekurzNumber,
    () => {
        schedulePreview()
    },
)

watch(
    () =>
        builder.value
            ?.draft
            ?.status,
    status => {
        if (
            status ===
            'completed'
        ) {
            activeSection.value =
                2
        }
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
        await Promise.all([
            load(),
            loadMacros(),
        ])

        schedulePreview()
    },
)

onBeforeUnmount(() => {
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
        <div
            v-if="document.document"
            class="
                flex
                h-full
                min-h-0
                flex-col
                gap-3
            "
        >
            <div
                class="
                    flex
                    shrink-0
                    items-center
                    justify-between
                    gap-3
                "
            >
                <div
                    class="
                        min-w-0
                    "
                >
                    <p
                        class="
                            truncate
                            text-normal
                            font-medium
                            text-darkgrey
                        "
                    >
                        {{
                            document.document.name ||
                            document.label
                        }}
                    </p>

                    <p
                        class="
                            text-mini
                            text-lightgrey
                        "
                    >
                        {{
                            formatDocumentDate(
                                document.document.date,
                            )
                        }}
                    </p>
                </div>

                <AppButton
                    type="button"
                    variant="text"
                    icon="bi bi-download"
                    label="Stiahnuť"
                    class="shrink-0!"
                    @click="
                        downloadSupportingDocument(
                            document,
                        )
                    "
                />
            </div>

            <div
                class="
                    relative
                    min-h-0
                    flex-1
                    overflow-hidden
                    rounded-md
                    bg-tag3
                "
            >
                <div
                    v-if="supportingDocumentLoading[document.key]"
                    class="
                        absolute
                        inset-0
                        flex
                        items-center
                        justify-center
                        gap-2
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

                <iframe
                    v-else-if="supportingDocumentPreviewUrls[document.key]"
                    :src="supportingDocumentPreviewUrls[document.key]"
                    :title="document.label"
                    class="
                        h-full
                        w-full
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
                        text-lightgrey
                    "
                >
                    Náhľad dokumentu nie je dostupný.
                </div>
            </div>
        </div>
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
                        h-full
                        min-h-0
                        flex-col
                        overflow-hidden
                        rounded-md
                        bg-tag3
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
                            v-else-if="builder"
                            class="
                                flex
                                flex-col
                                gap-8
                            "
                        >
                            <!-- Document identification -->

                            <div
                                class="
                                    grid
                                    grid-cols-12
                                    gap-4
                                "
                            >
                                <FormField
                                    label="Číslo dekurzu"
                                    class="
                                        col-span-6
                                    "
                                >
                                    <InputText
                                        v-model="dekurzNumber"
                                        class="
                                            w-full
                                            border-0!
                                        "
                                    />
                                </FormField>

                                <FormField
                                    label="Mesiac dekurzu"
                                    class="
                                        col-span-6
                                    "
                                >
                                    <DatePicker
                                        v-model="selectedMonth"
                                        view="month"
                                        dateFormat="mm/yy"
                                        :showIcon="false"
                                        class="
                                            w-full
                                        "
                                        inputClass="
                                            w-full!
                                            cursor-pointer!
                                            border-none!
                                        "
                                    />
                                </FormField>
                            </div>

                            <!-- Section selector -->

                            <div
                                class="
                                    flex
                                    flex-wrap
                                    items-center
                                    gap-2
                                "
                                aria-label="Sekcie Dekurzu"
                            >
                                <AppButton
                                    v-for="(
                                        section,
                                        index
                                    ) in sections"
                                    :key="section.label"
                                    type="button"
                                    :variant="
                                        activeSection ===
                                        index
                                            ? 'accent'
                                            : 'text'
                                    "
                                    :icon="section.icon"
                                    :label="section.label"
                                    :disabled="
                                        navigating ||
                                        (isCompleted &&
                                            index !== 2)
                                    "
                                    @click="
                                        goToSection(
                                            index,
                                        )
                                    "
                                />
                            </div>

                            <!-- ==================================================== -->
                            <!-- PROCEDURES                                           -->
                            <!-- ==================================================== -->

                            <section
                                v-if="activeSection === 0"
                                aria-labelledby="dekurz-procedures-heading"
                                class="
                                    flex
                                    flex-col
                                    gap-4
                                "
                            >
                                <div>
                                    <p
                                        id="dekurz-procedures-heading"
                                        class="
                                            text-normal
                                            text-accent
                                        "
                                    >
                                        Texty výkonov
                                    </p>

                                    <p
                                        class="
                                            mt-1
                                            text-mini
                                            text-lightgrey
                                        "
                                    >
                                        Upravte text, ktorý sa použije pri vytvorení denných záznamov.
                                    </p>
                                </div>

                                <div
                                    v-if="builder.procedures.length"
                                    class="
                                        grid
                                        gap-3
                                        xl:grid-cols-2
                                    "
                                >
                                    <DekurzProcedureTextCard
                                        v-for="procedure in builder.procedures"
                                        :key="procedure.id"
                                        :procedure="procedure"
                                        :macros="macros"
                                        :save-state="
                                            saveStates[
                                                `template-${procedure.id}`
                                            ]
                                        "
                                        @save="
                                            handleSaveTemplate
                                        "
                                        @reset="
                                            handleResetTemplate
                                        "
                                        @update-text="
                                            updateTemplateText
                                        "
                                    />
                                </div>

                                <p
                                    v-else
                                    class="
                                        text-normal
                                        text-lightgrey
                                    "
                                >
                                    V zvolenom mesiaci nie sú výkony určené pre Dekurz.
                                </p>
                            </section>

                            <!-- ==================================================== -->
                            <!-- DAILY                                                -->
                            <!-- ==================================================== -->

                            <DekurzDailyStep
                                v-else-if="activeSection === 1"
                                :days="builder.days"
                                :macros="macros"
                                :save-states="saveStates"
                                @save-entry="
                                    handleSaveEntry
                                "
                                @save-day="
                                    handleSaveDay
                                "
                                @apply-to-unedited="
                                    handleApplyToUnedited
                                "
                                @update-entry-text="
                                    updateEntryText
                                "
                                @update-day-note="
                                    updateDayNote
                                "
                            />

                            <!-- ==================================================== -->
                            <!-- REVIEW                                               -->
                            <!-- ==================================================== -->

                            <DekurzReviewStep
                                v-else
                                :days="builder.days"
                                :procedures="builder.procedures"
                                :documents="builder.supporting_documents"
                                :scoring-changes="builder.scoring_changes"
                                :completed="isCompleted"
                                :finalizing="finalizing"
                                :final-document-id="
                                    builder.draft
                                        ?.final_document_id ??
                                    null
                                "
                                :finalization-errors="finalizationErrors"
                                @finalize="
                                    handleFinalize
                                "
                            />

                            <!-- Navigation -->

                            <div
                                v-if="!isCompleted"
                                class="
                                    flex
                                    items-center
                                    justify-between
                                    gap-3
                                "
                            >
                                <AppButton
                                    v-if="activeSection > 0"
                                    type="button"
                                    variant="text"
                                    icon="bi bi-arrow-left"
                                    label="Späť"
                                    :disabled="navigating"
                                    @click="
                                        goToSection(
                                            activeSection -
                                                1,
                                        )
                                    "
                                />

                                <span
                                    v-else
                                />

                                <AppButton
                                    v-if="
                                        activeSection <
                                        sections.length -
                                            1
                                    "
                                    type="button"
                                    variant="accent"
                                    icon="bi bi-arrow-right"
                                    label="Pokračovať"
                                    :loading="navigating"
                                    class="ml-auto!"
                                    @click="
                                        goToSection(
                                            activeSection +
                                                1,
                                        )
                                    "
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
                />
            </section>
        </div>
    </div>
</template>
