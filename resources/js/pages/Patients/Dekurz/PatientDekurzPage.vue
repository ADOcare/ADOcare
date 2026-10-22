<script setup lang="ts">
import {
    computed,
    markRaw,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue'
import { useRouter } from 'vue-router'

import api from '@/services/api'
import { fetchBlobUrl } from '@/utils/blobUrl'

import { useDekurzV2 } from '@/composables/useDekurzV2'
import { usePatientStore } from '@/stores/patientStore'

import type {
    DekurzDay,
    DekurzEntry,
    DekurzSupportingDocument,
} from '@/types/dekurz'

import AppButton from '@/components/AppButton.vue'
import DocumentPreviewViewer from '@/components/DocumentPreviewViewer.vue'
import FormField from '@/components/FormField.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import ReferenceDocumentDrawer from '@/components/ReferenceDocumentDrawer.vue'

import DekurzDailyStep from './components/DekurzDailyStep.vue'

import useModal from '@/composables/useModal'
import DeleteDekurzEntryConfirmation
    from './components/DeleteDekurzEntryConfirmation.vue'

/* -------------------------------------------------------------------------- */
/* Stores                                                                     */
/* -------------------------------------------------------------------------- */

const patientStore =
    usePatientStore()

const router =
    useRouter()

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

const {
    openModal,
} =
    useModal()

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
    saveEntry,
    deleteEntry,
    createCustomTrack,
    propagateEntry,
    finalizeDraft,
} = useDekurzV2(
    patientIdRef,
    monthKey,
    dekurzNumber,
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

                    days:
                        builder.value?.days.map(
                            day => ({
                                date:
                                    day.date,

                                entries:
                                    day.entries
                                        .filter(entry => !entry.is_excluded)
                                        .map(
                                            entry => ({
                                                text:
                                                    entry.text,
                                                is_excluded:
                                                    entry.is_excluded,
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

/* -------------------------------------------------------------------------- */
/* Loading / initialization                                                   */
/* -------------------------------------------------------------------------- */

async function loadAndInitialize() {
    await load()

    if (!patientId.value || !monthKey.value) {
        return
    }

    // Create the one logical patient+month Dekurz automatically only when it
    // does not exist yet. Merely viewing a completed month must not reopen it.
    if (builder.value?.draft?.status !== 'completed') {
        await initializeDraft()
    }

    schedulePreview()
}

/* -------------------------------------------------------------------------- */
/* Mutations                                                                  */
/* -------------------------------------------------------------------------- */

function updateEntryText(
    entry: DekurzEntry,
    text: string,
) {
    entry.text =
        text

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

async function handleDeleteEntry(
    entry: DekurzEntry,
) {
    if (
        entry.track_type !==
        'custom'
    ) {
        return
    }

    const confirmed =
        await openModal(
            markRaw(
                DeleteDekurzEntryConfirmation,
            ),

            {
                title:
                    'Odstrániť sekciu',

                entry,
            },

            {
                header:
                    'Odstrániť sekciu',

                style: {
                    width:
                        'min(440px, 94vw)',
                },
            },
        )

    if (
        !confirmed
    ) {
        return
    }

    await deleteEntry(
        entry,
    )

    schedulePreview()
}

async function handlePropagate(payload: Parameters<typeof propagateEntry>[0]) {
    await propagateEntry(payload)
    schedulePreview()
}

async function handleCreateSection(payload: { day: DekurzDay; name: string }) {
    await createCustomTrack(payload.name, payload.day.date)
    schedulePreview()
}

async function handleFinalize() {
    const finalized = await finalizeDraft(
        previewPageCount.value,
    )

    if (!finalized) {
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
    async () => {
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
        await Promise.all([
            loadAndInitialize(),
            loadMacros(),
        ])
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
                h-full
                min-h-0
            "
        >
            <div
                class="
                    relative
                    h-full
                    overflow-hidden
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
                            v-else-if="builder"
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
                                        readonly
                                        class="w-full cursor-default border-0! bg-tag3!"
                                    />
                                </FormField>
                            </div>

                            <div
                                v-if="builder.draft?.final_document_id"
                                class="flex items-center gap-3 rounded-md bg-danger p-2"
                            >
                                <i class="bi bi-file-earmark-check text-white" />
                                <div class="min-w-0">
                                    <p class="text-normal font-medium text-white">
                                        Upravujete existujúci súbor.
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="!builder.draft"
                                class="flex items-start gap-3 rounded-md bg-warning/10 p-4"
                            >
                                <i class="bi bi-clock-history mt-0.5 text-warning" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-normal font-medium text-darkgrey">
                                        {{ initializing ? 'Pripravujem Dekurz…' : 'Dekurz zatiaľ nie je možné upravovať.' }}
                                    </p>
                                    <p class="mt-1 text-mini text-lightgrey">
                                        {{ draftInitializationError || 'Kontrolujem vypočítané časy návštev.' }}
                                    </p>
                                    <AppButton
                                        v-if="draftInitializationError"
                                        type="button"
                                        variant="text"
                                        icon="bi bi-arrow-clockwise"
                                        label="Skontrolovať znova"
                                        class="mt-2"
                                        :loading="initializing"
                                        @click="initializeDraft"
                                    />
                                </div>
                            </div>

                            <template v-else>
                                <DekurzDailyStep
                                    :days="builder.days"
                                    :macros="macros"
                                    :save-states="saveStates"
                                    @save-entry="handleSaveEntry"
                                    @delete-entry="handleDeleteEntry"
                                    @propagate="handlePropagate"
                                    @create-section="handleCreateSection"
                                    @update-entry-text="updateEntryText"
                                />

                                <div
                                    v-if="finalizationErrors.length"
                                    class="rounded-md bg-danger/5 p-4"
                                >
                                    <p class="text-normal font-medium text-danger">
                                        Dekurz zatiaľ nie je možné vygenerovať.
                                    </p>
                                    <ul class="mt-2 list-disc space-y-1 pl-5 text-mini text-danger">
                                        <li v-for="message in finalizationErrors" :key="message">
                                            {{ message }}
                                        </li>
                                    </ul>
                                </div>

                                <div class="flex justify-end">
                                    <AppButton
                                        type="button"
                                        variant="accent"
                                        :label="builder.draft.final_document_id ? 'Znovu vygenerovať dokument' : 'Generovať dokument'"
                                        :loading="finalizing"
                                        :disabled="isPreviewLoading || !isPreviewPaginationReady"
                                        @click="handleFinalize"
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
                    title="Náhľad Dekurzu"
                    @page-count-change="handlePreviewPageCountChange"
                />
            </section>
        </div>
    </div>

</template>
