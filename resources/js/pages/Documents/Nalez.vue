<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue'

import { useRoute } from 'vue-router'
import Dialog from 'primevue/dialog'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'

import AppButton from '@/components/AppButton.vue'
import DocumentShell from '@/components/DocumentShell.vue'

import api from '@/services/api'
import { fetchBlobUrl } from '@/utils/blobUrl'

import {
    usePublicDocument,
    type PublicDocumentProps,
} from '@/composables/usePublicDocument'

const props =
    defineProps<PublicDocumentProps>()

const route =
    useRoute()

const toast =
    useToast()

const {
    data,
    loading,
    documentId,
} = usePublicDocument(
    props,
    {
        privateDataUrl:
            `/v1/scan/${route.params.documentId}`,

        privatePreviewUrl:
            `/v1/scan/${route.params.documentId}/preview`,

        privateDownloadUrl:
            `/api/v1/scan/${route.params.documentId}/download`,
    },
)

/* -------------------------------------------------------------------------- */
/* Types                                                                      */
/* -------------------------------------------------------------------------- */

type ScanPage = {
    text?: string
    image_url: string
}

type ScanImage = {
    url: string
}

/* -------------------------------------------------------------------------- */
/* Pages                                                                      */
/* -------------------------------------------------------------------------- */

const sourcePages =
    computed<ScanPage[]>(() => {
        const extractedPages =
            Array.isArray(
                data.value
                    ?.extracted_pages,
            )
                ? data.value
                    .extracted_pages
                : []

        const images: ScanImage[] =
            Array.isArray(
                data.value?.images,
            )
                ? data.value.images
                : []

        if (images.length) {
            return images.map(
                (
                    image,
                    index,
                ) => ({
                    image_url:
                        extractedPages[
                            index
                        ]?.image_url ??
                        image.url,

                    text:
                        extractedPages[
                            index
                        ]?.text,
                }),
            )
        }

        return extractedPages
    })

const pages =
    ref<ScanPage[]>([])

const imagesLoading =
    ref(false)

let imageLoadGeneration = 0

let imageBlobUrls:
    string[] = []

/* -------------------------------------------------------------------------- */
/* Image loading                                                              */
/* -------------------------------------------------------------------------- */

function revokeImageBlobUrls() {
    imageBlobUrls.forEach(
        (url) =>
            URL.revokeObjectURL(
                url,
            ),
    )

    imageBlobUrls = []
}

function authenticatedImageEndpoint(
    url: string,
) {
    return url.startsWith(
        '/api/',
    )
        ? url.slice(4)
        : url
}

watch(
    sourcePages,

    async (newPages) => {
        const generation =
            ++imageLoadGeneration

        imagesLoading.value =
            newPages.length > 0

        if (!newPages.length) {
            revokeImageBlobUrls()

            pages.value = []

            imagesLoading.value =
                false

            return
        }

        try {
            const blobUrls =
                await Promise.all(
                    newPages.map(
                        (page) =>
                            fetchBlobUrl(
                                authenticatedImageEndpoint(
                                    page.image_url,
                                ),
                            ),
                    ),
                )

            if (
                generation !==
                imageLoadGeneration
            ) {
                blobUrls.forEach(
                    (url) =>
                        URL.revokeObjectURL(
                            url,
                        ),
                )

                return
            }

            revokeImageBlobUrls()

            imageBlobUrls =
                blobUrls

            pages.value =
                newPages.map(
                    (
                        page,
                        index,
                    ) => ({
                        ...page,

                        image_url:
                            blobUrls[
                                index
                            ] ?? '',
                    }),
                )
        } catch (error) {
            console.error(
                'Failed to load scan images:',
                error,
            )

            pages.value = []
        } finally {
            if (
                generation ===
                imageLoadGeneration
            ) {
                imagesLoading.value =
                    false
            }
        }
    },

    {
        immediate: true,
    },
)

onBeforeUnmount(() => {
    imageLoadGeneration++

    revokeImageBlobUrls()
})

/* -------------------------------------------------------------------------- */
/* OCR                                                                        */
/* -------------------------------------------------------------------------- */

const selectedPageIndex =
    ref<number | null>(null)

const textVisible =
    ref(false)

const editedText =
    ref('')

const isSaving =
    ref(false)

function openTextForPage(
    index: number,
) {
    const page =
        pages.value[index]

    if (!page) {
        return
    }

    selectedPageIndex.value =
        index

    editedText.value =
        page.text ?? ''

    textVisible.value =
        true
}

/* -------------------------------------------------------------------------- */
/* Copy                                                                       */
/* -------------------------------------------------------------------------- */

async function copyText() {
    const text =
        editedText.value.trim()

    if (!text) {
        return
    }

    try {
        await navigator.clipboard
            .writeText(text)

        toast.add({
            severity:
                'success',

            summary:
                'Skopírované',

            detail:
                'Text bol skopírovaný.',

            life:
                2000,
        })
    } catch (error) {
        console.error(
            'Failed to copy text:',
            error,
        )

        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                'Text sa nepodarilo skopírovať.',

            life:
                3000,
        })
    }
}

/* -------------------------------------------------------------------------- */
/* Save                                                                       */
/* -------------------------------------------------------------------------- */

async function saveText() {
    if (
        !documentId.value ||
        selectedPageIndex.value ===
        null ||
        isSaving.value
    ) {
        return
    }

    isSaving.value = true

    try {
        await api.patch(
            `/v1/scan/${documentId.value}/text`,
            {
                page_index:
                    selectedPageIndex.value,

                text:
                    editedText.value,
            },
        )

        const page =
            pages.value[
                selectedPageIndex.value
            ]

        if (page) {
            page.text =
                editedText.value
        }

        const sourcePage =
            data.value
                ?.extracted_pages
                ?.[
                    selectedPageIndex.value
                ]

        if (sourcePage) {
            sourcePage.text =
                editedText.value
        }

        toast.add({
            severity:
                'success',

            summary:
                'Uložené',

            detail:
                'Text bol uložený.',

            life:
                2500,
        })

        textVisible.value =
            false
    } catch (error) {
        console.error(
            'Failed to save OCR text:',
            error,
        )

        toast.add({
            severity:
                'error',

            summary:
                'Chyba',

            detail:
                'Text sa nepodarilo uložiť.',

            life:
                3000,
        })
    } finally {
        isSaving.value =
            false
    }
}
</script>

<template>
    <DocumentShell
        title="Lekársky nález"
        previewUrl=""
        :downloadOptions="[]"
        :actions="[]"
        :showPrintButton="false"
    >

        <!-- Loading -->
        <div
            v-if="
                loading ||
                imagesLoading
            "
            class="
                flex
                min-h-40
                items-center
                justify-center
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
            />
        </div>

        <!-- Vertical image gallery -->
        <section
            v-else-if="
                pages.length
            "
            class="
                mx-auto
                flex
                w-full
                max-w-2xl
                flex-col
                gap-4
            "
        >
            <article
                v-for="(
                    page,
                    index
                ) in pages"
                :key="index"
                class="
                    relative
                    w-full
                    overflow-hidden
                    rounded-md
                    bg-tag3
                    border border-tag3
                "
            >
                <img
                    :src="
                        page.image_url
                    "
                    :alt="
                        `Strana ${index + 1}`
                    "
                    class="
                        mx-auto
                        block
                        h-auto
                        max-w-full
                        rounded-md
                    "
                />

                <!-- Per-page OCR -->
                <AppButton
                    v-if="
                        !props.isPublic
                    "
                    type="button"
                    icon="bi bi-text-paragraph"
                    :label="
                        page.text
                            ? 'Text'
                            : 'Extrahovať text'
                    "
                    expand-on-hover
                    class="
                        absolute!
                        bottom-3!
                        right-3!
                    "
                    @click="
                        openTextForPage(
                            index,
                        )
                    "
                />
            </article>
        </section>

        <!-- Empty -->
        <div
            v-else
            class="
                rounded-md
                bg-tag3
                p-6
                text-center
                text-normal
                text-darkgrey
            "
        >
            Dokument neobsahuje obrázky.
        </div>

        <!-- OCR text -->
        <Dialog
            v-model:visible="
                textVisible
            "
            modal
            header="Text z obrázka"
            class="
                w-full
                max-w-3xl
            "
        >
            <div
                class="
                    flex
                    flex-col
                    gap-4
                "
            >
                <Textarea
                    v-model="
                        editedText
                    "
                    class="
                        h-96!
                        w-full!
                        resize-none!
                    "
                    placeholder="
                        Text z OCR...
                    "
                />

                <div
                    class="
                        flex
                        justify-end
                        gap-2
                    "
                >
                    <AppButton
                        type="button"
                        variant="secondary"
                        icon="bi bi-copy"
                        label="Kopírovať"
                        :disabled="
                            !editedText.trim()
                        "
                        @click="
                            copyText
                        "
                    />

                    <AppButton
                        type="button"
                        variant="secondary"
                        label="Zrušiť"
                        :disabled="
                            isSaving
                        "
                        @click="
                            textVisible =
                                false
                        "
                    />

                    <AppButton
                        type="button"
                        variant="accent"
                        label="Uložiť"
                        :loading="
                            isSaving
                        "
                        loading-label="
                            Ukladám
                        "
                        @click="
                            saveText
                        "
                    />
                </div>
            </div>
        </Dialog>
    </DocumentShell>
</template>