<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue'

import Dialog from 'primevue/dialog'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'

import AppButton from '@/components/AppButton.vue'

import api from '@/services/api'
import { fetchBlobUrl } from '@/utils/blobUrl'

type ScanPage = {
    text?: string
    image_url: string
}

type ScanImage = {
    url: string
}

type ScanData = {
    extracted_pages?: ScanPage[]
    images?: ScanImage[]
}

const props = withDefaults(
    defineProps<{
        data?: ScanData | null
        documentId?: number | string | null
        isPublic?: boolean
        loading?: boolean
    }>(),
    {
        data: null,
        documentId: null,
        isPublic: false,
        loading: false,
    },
)

const toast =
    useToast()

const documentData =
    ref<ScanData | null>(
        props.data,
    )

const dataLoading =
    ref(false)

let dataRequestId =
    0

watch(
    [
        () =>
            props.documentId,
        () =>
            props.data,
    ],
    async ([documentId, data]) => {
        const requestId =
            ++dataRequestId

        if (data) {
            documentData.value =
                data
            dataLoading.value =
                false
            return
        }

        if (!documentId) {
            documentData.value =
                null
            dataLoading.value =
                false
            return
        }

        dataLoading.value =
            true

        try {
            const response =
                await api.get(
                    `/v1/scan/${documentId}`,
                )

            if (
                requestId !==
                dataRequestId
            ) {
                return
            }

            documentData.value =
                response.data
                    ?.data ??
                null
        } catch (error) {
            if (
                requestId !==
                dataRequestId
            ) {
                return
            }

            console.error(
                'Failed to load scan document:',
                error,
            )

            documentData.value =
                null
        } finally {
            if (
                requestId ===
                dataRequestId
            ) {
                dataLoading.value =
                    false
            }
        }
    },
    {
        immediate: true,
    },
)

const sourcePages =
    computed<ScanPage[]>(() => {
        const extractedPages =
            Array.isArray(
                documentData.value
                    ?.extracted_pages,
            )
                ? documentData.value
                    ?.extracted_pages ??
                    []
                : []

        const images =
            Array.isArray(
                documentData.value
                    ?.images,
            )
                ? documentData.value
                    ?.images ??
                    []
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

let imageLoadGeneration =
    0

let imageBlobUrls:
    string[] = []

function revokeImageBlobUrls() {
    imageBlobUrls.forEach(
        url =>
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
    async newPages => {
        const generation =
            ++imageLoadGeneration

        imagesLoading.value =
            newPages.length >
            0

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
                        page =>
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
                    url =>
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
                            ] ??
                            '',
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

const isLoading =
    computed(
        () =>
            props.loading ||
            dataLoading.value ||
            imagesLoading.value,
    )

const selectedPageIndex =
    ref<number | null>(
        null,
    )

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
        page.text ??
        ''
    textVisible.value =
        true
}

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
            severity: 'success',
            summary: 'Skopírované',
            detail: 'Text bol skopírovaný.',
            life: 2000,
        })
    } catch (error) {
        console.error(
            'Failed to copy text:',
            error,
        )

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Text sa nepodarilo skopírovať.',
            life: 3000,
        })
    }
}

async function saveText() {
    if (
        !props.documentId ||
        selectedPageIndex.value ===
            null ||
        isSaving.value
    ) {
        return
    }

    isSaving.value =
        true

    try {
        await api.patch(
            `/v1/scan/${props.documentId}/text`,
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
            documentData.value
                ?.extracted_pages
                ?.[
                    selectedPageIndex.value
                ]

        if (sourcePage) {
            sourcePage.text =
                editedText.value
        }

        toast.add({
            severity: 'success',
            summary: 'Uložené',
            detail: 'Text bol uložený.',
            life: 2500,
        })

        textVisible.value =
            false
    } catch (error) {
        console.error(
            'Failed to save OCR text:',
            error,
        )

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Text sa nepodarilo uložiť.',
            life: 3000,
        })
    } finally {
        isSaving.value =
            false
    }
}

onBeforeUnmount(() => {
    dataRequestId++
    imageLoadGeneration++
    revokeImageBlobUrls()
})
</script>

<template>
    <div
        class="
            h-full
            min-h-0
            overflow-y-auto
            bg-white
            p-4
        "
    >
        <div
            v-if="isLoading"
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
                aria-label="Načítavam nález"
            />
        </div>

        <section
            v-else-if="pages.length"
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
                    border
                    border-tag3
                    bg-tag3
                "
            >
                <img
                    :src="page.image_url"
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

                <AppButton
                    v-if="!isPublic"
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

        <Dialog
            v-model:visible="textVisible"
            modal
            header="Text z obrázka"
            class="w-full max-w-3xl"
        >
            <div
                class="
                    flex
                    flex-col
                    gap-4
                "
            >
                <Textarea
                    v-model="editedText"
                    class="
                        h-96!
                        w-full!
                        resize-none!
                    "
                    placeholder="Text z OCR..."
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
                        @click="copyText"
                    />

                    <AppButton
                        type="button"
                        variant="secondary"
                        label="Zrušiť"
                        :disabled="isSaving"
                        @click="
                            textVisible = false
                        "
                    />

                    <AppButton
                        type="button"
                        variant="accent"
                        label="Uložiť"
                        :loading="isSaving"
                        loading-label="Ukladám"
                        @click="saveText"
                    />
                </div>
            </div>
        </Dialog>
    </div>
</template>
