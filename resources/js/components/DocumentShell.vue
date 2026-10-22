<script setup lang="ts">
import {
    computed,
    ref,
} from 'vue'

import axios from 'axios'
import SplitButton from 'primevue/splitbutton'

import AppButton from '@/components/AppButton.vue'
import AdonisButton from '@/components/AdonisButton.vue'
import DocumentPreviewViewer from '@/components/DocumentPreviewViewer.vue'

import api from '@/services/api'

export interface DownloadOption {
    label?: string
    url: string
    method?: 'get' | 'post'
    payload?: Record<string, any>
    filename?: string
    contentType?: string
    fileType?: string
}

export interface FileItem {
    title: string
    description?: string
    downloads: DownloadOption[]
}

export interface ActionButton {
    id: string
    label: string
    icon: string
    tooltip?: string
    disabled?: boolean
    loading?: boolean
    adonis?: boolean
    compact?: boolean

    variant?:
        | 'accent'
        | 'dark'
        | 'text'
        | 'secondary'
        | 'danger'
}

export interface Props {
    title: string
    subtitle?: string

    previewUrl?: string
    previewWidth?: string

    downloadUrl?: string
    downloadMethod?: 'get' | 'post'
    downloadPayload?: Record<string, any>
    downloadFilename?: string
    downloadContentType?: string
    downloadLabel?: string

    downloadOptions?: DownloadOption[]

    files?: FileItem[]

    actions?: ActionButton[]

    showPrintButton?: boolean
    showTitle?: boolean
    paginatedPreview?: boolean
}

const props =
    withDefaults(
        defineProps<Props>(),
        {
            previewWidth:
                '210mm',

            downloadMethod:
                'get',

            downloadContentType:
                'application/pdf',

            downloadLabel:
                'Download',

            downloadOptions:
                () => [],

            files:
                () => [],

            actions:
                () => [],

            showPrintButton:
                true,

            showTitle:
                true,

            paginatedPreview:
                true,
        },
    )

const emit =
    defineEmits<{
        actionClick: [
            buttonId: string,
        ]
    }>()

/* -------------------------------------------------------------------------- */
/* State                                                                      */
/* -------------------------------------------------------------------------- */

const isDownloading =
    ref(false)

const downloadError =
    ref<string | null>(
        null,
    )

const previewViewerRef =
    ref<{
        buildDocumentPdf: () => Promise<Blob>
        printDocument: () => void
    } | null>(null)

/* -------------------------------------------------------------------------- */
/* Download options                                                           */
/* -------------------------------------------------------------------------- */

const topLevelOptions =
    computed<DownloadOption[]>(
        () => {
            if (
                props
                    .downloadOptions
                    ?.length
            ) {
                return props
                    .downloadOptions
            }

            if (
                props.downloadUrl
            ) {
                return [
                    {
                        url:
                            props.downloadUrl,

                        method:
                            props.downloadMethod,

                        payload:
                            props.downloadPayload,

                        filename:
                            props.downloadFilename,

                        contentType:
                            props.downloadContentType,

                        label:
                            props.downloadLabel,

                        fileType:
                            props
                                .downloadFilename
                                ? props
                                    .downloadFilename
                                    .split('.')
                                    .pop()
                                    ?.toUpperCase() ??
                                  props
                                      .downloadLabel
                                : props
                                    .downloadLabel,
                    },
                ]
            }

            return []
        },
    )

const firstTopLevelOption =
    computed(
        () =>
            topLevelOptions
                .value[0],
    )

function getOptionLabel(
    option: DownloadOption,
): string {
    if (option.label) {
        return option.label
    }

    if (option.fileType) {
        return option.fileType
    }

    const path =
        option.url
            .split('?')[0] ??
        ''

    return (
        path
            .split('/')
            .pop() ??
        'Download'
    )
}

function splitMenuItems(
    options: DownloadOption[],
) {
    if (
        options.length <= 1
    ) {
        return []
    }

    return options
        .slice(1)
        .map(
            (option) => ({
                label:
                    getOptionLabel(
                        option,
                    ),

                command: () =>
                    download(
                        option,
                    ),
            }),
        )
}

/* -------------------------------------------------------------------------- */
/* Download helpers                                                           */
/* -------------------------------------------------------------------------- */

function makeFilename(
    option: DownloadOption,
): string {
    if (option.filename) {
        return option.filename
    }

    const extension =
        option.fileType
            ? option.fileType
                .replace(
                    /\./g,
                    '',
                )
                .toLowerCase()
            : (
                option.url
                    .split('?')[0]
                    ?.split('.')
                    .pop() ??
                'bin'
            )

    return `download.${extension}`
}

function normalizeDownloadUrl(
    url: string,
): string {
    let normalized =
        url.trim()

    normalized =
        normalized.replace(
            /\/api(\/|$)/g,
            '/api/',
        )

    const baseUrl =
        api.defaults
            .baseURL
            ?.toString()
            .replace(
                /\/$/,
                '',
            )

    if (
        baseUrl &&
        normalized.startsWith(
            baseUrl + '/',
        )
    ) {
        normalized =
            normalized.slice(
                baseUrl.length,
            )

        if (
            !normalized.startsWith(
                '/',
            )
        ) {
            normalized =
                '/' +
                normalized
        }
    }

    return normalized
}

function isApiUrl(
    url: string,
): boolean {
    return (
        url.startsWith(
            '/api/',
        ) ||
        url === '/api'
    )
}

function triggerDownload(
    blob: Blob,
    filename: string,
) {
    const url =
        URL.createObjectURL(
            blob,
        )

    const anchor =
        document.createElement(
            'a',
        )

    anchor.href =
        url

    anchor.download =
        filename

    anchor.click()

    setTimeout(
        () => {
            URL.revokeObjectURL(
                url,
            )
        },
        100,
    )
}

function getFilenameFromResponse(
    response: any,
): string | null {
    const contentDisposition =
        response.headers?.[
            'content-disposition'
        ]

    if (!contentDisposition) {
        return null
    }

    const match =
        /filename\*?=([^;]+)/i.exec(
            contentDisposition,
        )

    if (!match) {
        return null
    }

    let filename =
        match[1]!.trim()

    filename =
        filename.replace(
            /^(UTF-8'')/,
            '',
        )

    filename =
        filename.replace(
            /['"]+/g,
            '',
        )

    return decodeURIComponent(
        filename,
    )
}

/* -------------------------------------------------------------------------- */
/* Download                                                                   */
/* -------------------------------------------------------------------------- */

async function download(
    option: DownloadOption,
) {
    if (!option.url) {
        return
    }

    const isPdf =
        option.contentType ===
            'application/pdf' ||
        option.fileType
            ?.toLowerCase() ===
            'pdf'

    if (
        isPdf &&
        previewViewerRef.value
    ) {
        isDownloading.value =
            true

        downloadError.value =
            null

        try {
            const filename =
                option.filename ??
                `${props.title}.pdf`

            const pdf =
                await previewViewerRef.value
                    .buildDocumentPdf()

            triggerDownload(
                pdf,
                filename,
            )
        } catch (error) {
            downloadError.value =
                'Download failed.'

            console.error(
                'DocumentShell preview download failed',
                error,
            )
        } finally {
            isDownloading.value =
                false
        }

        return
    }

    const shouldFetch =
        option.method ===
            'post' ||
        (
            option.payload &&
            Object.keys(
                option.payload,
            ).length > 0
        ) ||
        !!option.filename ||
        !!option.contentType

    if (!shouldFetch) {
        window.open(
            option.url,
            '_blank',
        )

        return
    }

    isDownloading.value =
        true

    downloadError.value =
        null

    try {
        const isApiEndpoint =
            isApiUrl(
                option.url,
            )

        const requestUrl =
            isApiEndpoint
                ? normalizeDownloadUrl(
                    option.url,
                )
                : option.url

        const response =
            option.method ===
            'post'
                ? isApiEndpoint
                    ? await api.post(
                        requestUrl,
                        option.payload ??
                            {},
                        {
                            responseType:
                                'blob',

                            headers: {
                                Accept:
                                    option.contentType ??
                                    '*/*',
                            },
                        },
                    )
                    : await axios.post(
                        requestUrl,
                        option.payload ??
                            {},
                        {
                            responseType:
                                'blob',

                            headers: {
                                Accept:
                                    option.contentType ??
                                    '*/*',
                            },
                        },
                    )
                : await api.get(
                    requestUrl,
                    {
                        params:
                            option.payload ??
                            {},

                        responseType:
                            'blob',

                        headers: {
                            Accept:
                                option.contentType ??
                                '*/*',
                        },
                    },
                )

        const filename =
            option.filename ||
            getFilenameFromResponse(
                response,
            ) ||
            makeFilename(
                option,
            )

        triggerDownload(
            response.data,
            filename,
        )
    } catch (error) {
        downloadError.value =
            'Download failed.'

        console.error(
            'DocumentShell download failed',
            error,
        )
    } finally {
        isDownloading.value =
            false
    }
}

function printPage() {
    previewViewerRef.value
        ?.printDocument()
}

/* -------------------------------------------------------------------------- */
/* Actions                                                                    */
/* -------------------------------------------------------------------------- */

function onActionClick(
    actionId: string,
) {
    emit(
        'actionClick',
        actionId,
    )
}
</script>

<template>
    <div
        class="
            relative
            flex
            flex-col
            gap-4
        "
    >
        <!-- Quartal navigation inside the document scroll container. -->
        <div
            class="
                sticky
                top-0
                z-40
                -mx-8
                flex
                min-h-11
                flex-wrap
                items-center
                justify-between
                gap-3
                border-t
                border-darkgrey
                bg-tag2
                px-3
                py-2
                no-print
            "
        >
            <!-- Title -->
            <div
                v-if="
                    props.showTitle
                "
                class="
                    flex
                    flex-col
                    gap-1
                "
            >
                <div
                    class="
                        text-normal
                        text-white
                    "
                >
                    {{ props.title }}
                </div>

                <div
                    v-if="
                        props.subtitle
                    "
                    class="
                        text-mini
                        text-white/70
                    "
                >
                    {{ props.subtitle }}
                </div>
            </div>

            <!-- Toolbar buttons -->
            <div
                class="
                    relative
                    flex
                    items-center
                    gap-2
                "
            >
                <!-- Custom actions -->
                <template
                    v-for="
                        action
                        in props.actions
                    "
                    :key="
                        action.id
                    "
                >
                    <AdonisButton
                        v-if="
                            action.adonis
                        "
                        :icon="
                            action.icon
                        "
                        :label="
                            action.label
                        "
                        :disabled="
                            action.disabled ||
                            action.loading
                        "
                        :loading="
                            action.loading
                        "
                        :title="
                            action.tooltip ||
                            action.label
                        "
                        @click="
                            onActionClick(
                                action.id,
                            )
                        "
                    />

                    <AppButton
                        v-else
                        type="button"
                        :variant="
                            action.variant ??
                            'dark'
                        "
                        :icon="
                            action.icon
                        "
                        :label="
                            action.label
                        "
                        :aria-label="
                            action.tooltip ||
                            action.label
                        "
                        :disabled="
                            action.disabled ||
                            action.loading
                        "
                        :loading="
                            action.loading
                        "
                        :expand-on-hover="
                            action.compact ??
                            true
                        "
                        @click="
                            onActionClick(
                                action.id,
                            )
                        "
                    />
                </template>

                <!-- Download -->
                <div
                    v-if="
                        topLevelOptions.length
                    "
                    class="
                        relative
                    "
                >
                    <!-- One download option -->
                    <AppButton
                        v-if="
                            topLevelOptions.length ===
                            1
                        "
                        type="button"
                        variant="dark"
                        icon="
                            bi
                            bi-download
                        "
                        label="Stiahnuť"
                        expand-on-hover
                        :loading="
                            isDownloading
                        "
                        :disabled="
                            isDownloading
                        "
                        @click="
                            firstTopLevelOption &&
                            download(
                                firstTopLevelOption,
                            )
                        "
                    />

                    <!-- Multiple download options -->
                    <SplitButton
                        v-else
                        icon="
                            bi
                            bi-download
                        "
                        :model="
                            splitMenuItems(
                                topLevelOptions,
                            )
                        "
                        :loading="
                            isDownloading
                        "
                        :disabled="
                            isDownloading
                        "
                        class="
                            document-shell-split-button
                            h-7!
                        "
                        @click="
                            firstTopLevelOption &&
                            download(
                                firstTopLevelOption,
                            )
                        "
                    />
                </div>

                <!-- Print -->
                <AppButton
                    v-if="
                        props.showPrintButton
                    "
                    type="button"
                    variant="dark"
                    icon="
                        bi
                        bi-printer
                    "
                    label="Tlačiť"
                    expand-on-hover
                    @click="
                        printPage
                    "
                />
            </div>
        </div>

        <!-- Document content -->
        <section
            class="
                flex
                flex-col
                gap-4
            "
        >
            <div
                v-if="
                    props.previewUrl
                "
                class="
                    w-full
                    h-[calc(100vh-12rem)]
                    min-h-[32rem]
                "
            >
                <DocumentPreviewViewer
                    ref="previewViewerRef"
                    :html="props.previewUrl"
                    :title="props.title"
                />
            </div>

            <!--
                Custom document content, e.g. scanned image gallery.

                Keeping this inside DocumentShell is important:
                the sticky toolbar then remains sticky while this
                content is being scrolled.
            -->
            <slot />
        </section>

        <!-- Download error -->
        <div
            v-if="
                downloadError
            "
            class="
                text-mini
                text-danger
                no-print
            "
        >
            {{ downloadError }}
        </div>
    </div>
</template>

<style scoped lang="scss">
/* -------------------------------------------------------------------------- */
/* PrimeVue SplitButton                                                       */
/* -------------------------------------------------------------------------- */

/*
 * SplitButton is intentionally kept only for the case
 * where there are multiple download formats.
 *
 * Make it visually consistent with AppButton.
 */
:deep(.document-shell-split-button) {
    height: 28px;
}

:deep(
    .document-shell-split-button
    .p-button
) {
    height: 28px;

    border-color:
        transparent !important;

    background:
        var(--color-darkgrey) !important;

    color:
        white !important;

    box-shadow:
        none !important;
}

:deep(
    .document-shell-split-button
    .p-button:hover
) {
    border-color:
        transparent !important;

    background:
        var(--color-accent) !important;

    color:
        white !important;
}

:deep(
    .document-shell-split-button
    .p-splitbutton-button
) {
    padding-inline:
        0.75rem;

    border-radius:
        0.375rem 0 0
        0.375rem !important;
}

:deep(
    .document-shell-split-button
    .p-splitbutton-dropdown
) {
    width:
        28px;

    min-width:
        28px;

    padding:
        0;

    border-radius:
        0 0.375rem
        0.375rem 0 !important;
}

/* -------------------------------------------------------------------------- */
/* Print                                                                      */
/* -------------------------------------------------------------------------- */

@media print {
    html,
    body {
        margin:
            0 !important;

        padding:
            0 !important;

        height:
            auto !important;

        overflow:
            visible !important;
    }

    body * {
        visibility:
            hidden !important;
    }

    iframe {
        box-shadow:
            none !important;
    }

    #document-shell-print,
    #document-shell-print * {
        visibility:
            visible !important;
    }

    #document-shell-print {
        position:
            static !important;

        width:
            100% !important;

        margin:
            0 !important;

        padding:
            0 !important;

        height:
            auto !important;

        overflow:
            visible !important;
    }

    #document-shell-print
    iframe {
        height:
            auto !important;

        min-height:
            0 !important;

        max-height:
            none !important;

        overflow:
            visible !important;
    }

    .no-print {
        display:
            none !important;
    }
}
</style>