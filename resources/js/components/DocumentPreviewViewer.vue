<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue'

const props = withDefaults(
    defineProps<{
        html?: string
        loading?: boolean
        error?: string | null
        title?: string
    }>(),
    {
        html: '',
        loading: false,
        error: null,
        title: 'Náhľad dokumentu',
    },
)

const emit = defineEmits<{
    pageCountChange: [pageCount: number]
}>()

/* -------------------------------------------------------------------------- */
/* Constants                                                                  */
/* -------------------------------------------------------------------------- */

const A4_WIDTH_PX =
    210 / 25.4 * 96

const A4_HEIGHT_PX =
    297 / 25.4 * 96

const PREVIEW_HORIZONTAL_SPACE =
    48

const PREVIEW_VERTICAL_SPACE =
    24

const MIN_ZOOM =
    0.4

const MAX_ZOOM =
    2

const ZOOM_STEP =
    0.1

/* -------------------------------------------------------------------------- */
/* Refs                                                                       */
/* -------------------------------------------------------------------------- */

const viewportRef =
    ref<HTMLElement | null>(null)

const iframeRef =
    ref<HTMLIFrameElement | null>(
        null,
    )

const fitScale =
    ref(1)

const zoom =
    ref(1)

const currentPage =
    ref(0)

const pageCount =
    ref(1)

const previewDocumentVersion =
    ref(0)

const viewportSize =
    ref({
        width: 0,
        height: 0,
    })

/*
 * Position of the scaled document
 * inside the preview viewport.
 */
const panX =
    ref(0)

const panY =
    ref(0)

const isPanning =
    ref(false)

let panInteraction:
    | {
          pointerId: number
          startPointerX: number
          startPointerY: number
          startPanX: number
          startPanY: number
      }
    | null = null

let resizeObserver:
    ResizeObserver |
    null = null

let iframeInitializationFrame:
    number |
    null = null

/* -------------------------------------------------------------------------- */
/* Computed                                                                   */
/* -------------------------------------------------------------------------- */

const previewScale =
    computed(
        () =>
            fitScale.value *
            zoom.value,
    )

const documentWidth =
    computed(
        () =>
            A4_WIDTH_PX *
            previewScale.value,
    )

const documentHeight =
    computed(
        () =>
            A4_HEIGHT_PX *
            previewScale.value,
    )

const zoomPercentage =
    computed(
        () =>
            Math.round(
                zoom.value *
                    100,
            ),
    )

const hasPreviousPage =
    computed(
        () =>
            currentPage.value >
            0,
    )

const hasNextPage =
    computed(
        () =>
            currentPage.value <
            pageCount.value -
                1,
    )

const canZoomOut =
    computed(
        () =>
            zoom.value >
            MIN_ZOOM,
    )

const canZoomIn =
    computed(
        () =>
            zoom.value <
            MAX_ZOOM,
    )

const frameStyle =
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

const pageStyle =
    computed(() => ({
        width:
            `${documentWidth.value}px`,

        height:
            `${documentHeight.value}px`,

        left:
            `${panX.value}px`,

        top:
            `${panY.value}px`,
    }))

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function clamp(
    value: number,
    minimum: number,
    maximum: number,
) {
    return Math.min(
        Math.max(
            value,
            minimum,
        ),
        maximum,
    )
}

function getPointerPosition(
    event: PointerEvent | WheelEvent,
) {
    const viewport =
        viewportRef.value

    if (!viewport) {
        return {
            x:
                viewportSize.value.width /
                2,

            y:
                viewportSize.value.height /
                2,
        }
    }

    const rect =
        viewport.getBoundingClientRect()

    return {
        x:
            event.clientX -
            rect.left,

        y:
            event.clientY -
            rect.top,
    }
}

/* -------------------------------------------------------------------------- */
/* Scaling                                                                    */
/* -------------------------------------------------------------------------- */

function updateFitScale(
    width: number,
    height: number,
) {
    if (
        width <= 0 ||
        height <= 0
    ) {
        return
    }

    viewportSize.value = {
        width,
        height,
    }

    const availableWidth =
        Math.max(
            0,

            width -
                PREVIEW_HORIZONTAL_SPACE,
        )

    const availableHeight =
        Math.max(
            0,

            height -
                PREVIEW_VERTICAL_SPACE,
        )

    const widthScale =
        availableWidth /
        A4_WIDTH_PX

    const heightScale =
        availableHeight /
        A4_HEIGHT_PX

    fitScale.value =
        Math.min(
            widthScale,
            heightScale,
            1,
        )

    centerDocument()
}

/* -------------------------------------------------------------------------- */
/* Pan                                                                        */
/* -------------------------------------------------------------------------- */

function constrainPan() {
    const viewportWidth =
        viewportSize.value.width

    const viewportHeight =
        viewportSize.value.height

    const width =
        documentWidth.value

    const height =
        documentHeight.value

    /*
     * If the document is smaller than the viewport,
     * always keep it centered.
     */
    if (
        width <=
        viewportWidth
    ) {
        panX.value =
            (
                viewportWidth -
                width
            ) /
            2
    } else {
        panX.value =
            clamp(
                panX.value,

                viewportWidth -
                    width,

                0,
            )
    }

    if (
        height <=
        viewportHeight
    ) {
        panY.value =
            (
                viewportHeight -
                height
            ) /
            2
    } else {
        panY.value =
            clamp(
                panY.value,

                viewportHeight -
                    height,

                0,
            )
    }
}

function centerDocument() {
    panX.value =
        (
            viewportSize.value.width -
            documentWidth.value
        ) /
        2

    panY.value =
        (
            viewportSize.value.height -
            documentHeight.value
        ) /
        2

    constrainPan()
}

/*
 * When zoomed beyond the fitted size,
 * moving the pointer around the viewer
 * reveals the corresponding area of the page.
 */
function handlePointerMove(
    event: PointerEvent,
) {
    if (
        panInteraction
    ) {
        movePan(
            event,
        )

        return
    }

    if (
        zoom.value <=
        1
    ) {
        return
    }

    const {
        x,
        y,
    } =
        getPointerPosition(
            event,
        )

    const viewportWidth =
        viewportSize.value.width

    const viewportHeight =
        viewportSize.value.height

    if (
        viewportWidth <=
            0 ||
        viewportHeight <=
            0
    ) {
        return
    }

    const xRatio =
        clamp(
            x /
                viewportWidth,

            0,
            1,
        )

    const yRatio =
        clamp(
            y /
                viewportHeight,

            0,
            1,
        )

    /*
     * This maps:
     *
     * cursor left   -> document left
     * cursor center -> document center
     * cursor right  -> document right
     *
     * Same vertically.
     */
    if (
        documentWidth.value >
        viewportWidth
    ) {
        panX.value =
            x -
            xRatio *
                documentWidth.value
    }

    if (
        documentHeight.value >
        viewportHeight
    ) {
        panY.value =
            y -
            yRatio *
                documentHeight.value
    }

    constrainPan()
}

/* -------------------------------------------------------------------------- */
/* Drag to pan                                                                */
/* -------------------------------------------------------------------------- */

function startPan(
    event: PointerEvent,
) {
    if (
        event.button !==
            0 ||
        zoom.value <=
            1
    ) {
        return
    }

    event.preventDefault()

    const element =
        event.currentTarget as HTMLElement

    try {
        element.setPointerCapture(
            event.pointerId,
        )
    } catch {
        //
    }

    panInteraction = {
        pointerId:
            event.pointerId,

        startPointerX:
            event.clientX,

        startPointerY:
            event.clientY,

        startPanX:
            panX.value,

        startPanY:
            panY.value,
    }

    isPanning.value =
        true
}

function movePan(
    event: PointerEvent,
) {
    if (
        !panInteraction ||
        event.pointerId !==
            panInteraction.pointerId
    ) {
        return
    }

    panX.value =
        panInteraction.startPanX +
        (
            event.clientX -
            panInteraction.startPointerX
        )

    panY.value =
        panInteraction.startPanY +
        (
            event.clientY -
            panInteraction.startPointerY
        )

    constrainPan()
}

function stopPan(
    event?: PointerEvent,
) {
    if (
        !panInteraction
    ) {
        return
    }

    if (
        event &&
        event.pointerId !==
            panInteraction.pointerId
    ) {
        return
    }

    panInteraction =
        null

    isPanning.value =
        false
}

/* -------------------------------------------------------------------------- */
/* Zoom                                                                       */
/* -------------------------------------------------------------------------- */

function setZoom(
    nextZoom: number,
    focusX?: number,
    focusY?: number,
) {
    const viewportWidth =
        viewportSize.value.width

    const viewportHeight =
        viewportSize.value.height

    if (
        viewportWidth <=
            0 ||
        viewportHeight <=
            0
    ) {
        return
    }

    const oldWidth =
        documentWidth.value

    const oldHeight =
        documentHeight.value

    const pointX =
        focusX ??
        viewportWidth /
            2

    const pointY =
        focusY ??
        viewportHeight /
            2

    /*
     * Which exact point of the document
     * is currently underneath the mouse?
     */
    const documentX =
        oldWidth >
        0
            ? (
                pointX -
                panX.value
            ) /
                oldWidth
            : 0.5

    const documentY =
        oldHeight >
        0
            ? (
                pointY -
                panY.value
            ) /
                oldHeight
            : 0.5

    zoom.value =
        clamp(
            Number(
                nextZoom.toFixed(
                    2,
                ),
            ),

            MIN_ZOOM,
            MAX_ZOOM,
        )

    const newWidth =
        A4_WIDTH_PX *
        fitScale.value *
        zoom.value

    const newHeight =
        A4_HEIGHT_PX *
        fitScale.value *
        zoom.value

    /*
     * Keep the same document point directly
     * underneath the mouse after zooming.
     */
    panX.value =
        pointX -
        documentX *
            newWidth

    panY.value =
        pointY -
        documentY *
            newHeight

    constrainPan()
}

function zoomIn() {
    if (
        !canZoomIn.value
    ) {
        return
    }

    setZoom(
        zoom.value +
            ZOOM_STEP,
    )
}

function zoomOut() {
    if (
        !canZoomOut.value
    ) {
        return
    }

    setZoom(
        zoom.value -
            ZOOM_STEP,
    )
}

function resetZoom() {
    zoom.value =
        1

    centerDocument()
}

/* -------------------------------------------------------------------------- */
/* Iframe helpers                                                             */
/* -------------------------------------------------------------------------- */

function getIframeDocument() {
    return (
        iframeRef.value
            ?.contentDocument ??
        null
    )
}

function getIframeWindow() {
    return (
        iframeRef.value
            ?.contentWindow ??
        null
    )
}

/* -------------------------------------------------------------------------- */
/* Page detection                                                             */
/* -------------------------------------------------------------------------- */

function getPageElements() {
    const document =
        getIframeDocument()

    if (!document) {
        return []
    }

    const selectors = [
        '[data-page]',
        '.page',
        '.a4-page',
        '.document-page',
        '.print-page',
    ]

    for (
        const selector
        of selectors
    ) {
        const elements =
            Array.from(
                document.querySelectorAll(
                    selector,
                ),
            ) as HTMLElement[]

        if (
            elements.length >
            1
        ) {
            return elements
        }
    }

    return []
}

function getRenderablePageElements() {
    const document =
        getIframeDocument()

    if (!document) {
        return []
    }

    const selectors = [
        '[data-page]',
        '.page',
        '.a4-page',
        '.document-page',
        '.print-page',
    ]

    for (
        const selector
        of selectors
    ) {
        const elements =
            Array.from(
                document.querySelectorAll(
                    selector,
                ),
            ) as HTMLElement[]

        if (elements.length) {
            return elements
        }
    }

    return document.body
        ? [document.body]
        : []
}

function getDeclaredPageCount(
    html: string,
) {
    return Math.max(
        1,
        html.match(
            /\sdata-page(?:=|\s)/g,
        )?.length ??
            0,
    )
}

function detectPages() {
    const document =
        getIframeDocument()

    if (!document) {
        pageCount.value =
            1

        return
    }

    const pageElements =
        getPageElements()

    if (
        pageElements.length >
        1
    ) {
        pageCount.value =
            pageElements.length

        return
    }

    pageCount.value =
        1
}

/* -------------------------------------------------------------------------- */
/* Page navigation                                                            */
/* -------------------------------------------------------------------------- */

function showPage(
    page: number,
) {
    const document =
        getIframeDocument()

    const iframeWindow =
        getIframeWindow()

    if (
        !document ||
        !iframeWindow
    ) {
        return
    }

    const nextPage =
        clamp(
            page,

            0,

            pageCount.value -
                1,
        )

    currentPage.value =
        nextPage

    const pageElements =
        getPageElements()

    if (
        pageElements.length >
        1
    ) {
        pageElements.forEach(
            (
                element,
                index,
            ) => {
                element.style.setProperty(
                    'display',
                    index ===
                        nextPage
                        ? 'block'
                        : 'none',
                    'important',
                )
            },
        )

        iframeWindow.scrollTo({
            top:
                0,
            left:
                0,
            behavior:
                'auto',
        })

        return
    }

    let top =
        nextPage *
        A4_HEIGHT_PX

    if (
        pageElements[
            nextPage
        ]
    ) {
        top =
            pageElements[
                nextPage
            ].offsetTop
    }

    iframeWindow.scrollTo({
        top,
        left:
            0,

        behavior:
            'auto',
    })
}

function previousPage() {
    if (
        !hasPreviousPage.value
    ) {
        return
    }

    showPage(
        currentPage.value -
            1,
    )
}

function nextPage() {
    if (
        !hasNextPage.value
    ) {
        return
    }

    showPage(
        currentPage.value +
            1,
    )
}

function printDocument() {
    const document =
        getIframeDocument()

    const iframeWindow =
        getIframeWindow()

    if (
        !document ||
        !iframeWindow
    ) {
        return
    }

    const pageElements =
        getPageElements()

    const printStyle =
        document.createElement(
            'style',
        )

    printStyle.textContent = `
        @media print {
            @page {
                size: A4 portrait;
                margin: 0;
            }

            html.document-preview-printing,
            html.document-preview-printing body {
                width: auto !important;
                height: auto !important;
                overflow: visible !important;
            }

            html.document-preview-printing body {
                margin: 0 !important;
                padding: 0 !important;
            }

            .document-preview-print-page {
                display: block !important;
                margin: 0 !important;
                break-after: page !important;
                page-break-after: always !important;
                box-shadow: none !important;
            }

            .document-preview-print-page-last {
                break-after: auto !important;
                page-break-after: auto !important;
            }
        }
    `

    document.head.appendChild(
        printStyle,
    )

    document.documentElement
        .classList.add(
            'document-preview-printing',
        )

    pageElements.forEach(
        (
            element,
            index,
        ) => {
            element.classList.add(
                'document-preview-print-page',
            )

            element.classList.toggle(
                'document-preview-print-page-last',
                index ===
                    pageCount.value -
                        1,
            )

            element.style.setProperty(
                'display',
                index <
                    pageCount.value
                    ? 'block'
                    : 'none',
                'important',
            )
        },
    )

    const restorePage = () => {
        printStyle.remove()

        document.documentElement
            .classList.remove(
                'document-preview-printing',
            )

        pageElements.forEach(
            (element) => {
                element.classList.remove(
                    'document-preview-print-page',
                    'document-preview-print-page-last',
                )
            },
        )

        showPage(
            currentPage.value,
        )
    }

    iframeWindow.addEventListener(
        'afterprint',
        restorePage,
        {
            once: true,
        },
    )

    iframeWindow.focus()
    iframeWindow.print()
}

async function buildDocumentPdf() {
    const document =
        getIframeDocument()

    if (!document) {
        throw new Error(
            'Document preview is not ready.',
        )
    }

    const availablePages =
        getRenderablePageElements()

    if (!availablePages.length) {
        throw new Error(
            'Document pages were not found.',
        )
    }

    const selectedPage =
        currentPage.value

    const [
        {
            default:
                html2canvas,
        },
        {
            jsPDF,
        },
    ] = await Promise.all([
        import('html2canvas'),
        import('jspdf'),
    ])

    await document.fonts
        ?.ready

    await Promise.all(
        Array.from(
            document.images,
        ).map(
            (image) =>
                image.complete
                    ? Promise.resolve()
                    : new Promise<void>(
                        (resolve) => {
                            image.addEventListener(
                                'load',
                                () => resolve(),
                                {
                                    once: true,
                                },
                            )

                            image.addEventListener(
                                'error',
                                () => resolve(),
                                {
                                    once: true,
                                },
                            )
                        },
                    ),
        ),
    )

    const pdf =
        new jsPDF({
            orientation:
                'portrait',
            unit:
                'mm',
            format:
                'a4',
            compress:
                true,
        })

    try {
        for (
            let pageIndex = 0;
            pageIndex < pageCount.value;
            pageIndex += 1
        ) {
            showPage(
                pageIndex,
            )

            await nextTick()

            await new Promise<void>(
                (resolve) => {
                    requestAnimationFrame(
                        () => resolve(),
                    )
                },
            )

            const pages =
                getRenderablePageElements()

            const page =
                pages[pageIndex] ??
                pages[0]

            if (!page) {
                throw new Error(
                    `Document page ${pageIndex + 1} was not found.`,
                )
            }

            const canvas =
                await html2canvas(
                    page,
                    {
                        backgroundColor:
                            '#ffffff',
                        logging:
                            false,
                        scale:
                            2,
                        useCORS:
                            true,
                    },
                )

            if (pageIndex > 0) {
                pdf.addPage(
                    'a4',
                    'portrait',
                )
            }

            pdf.addImage(
                canvas.toDataURL(
                    'image/jpeg',
                    0.96,
                ),
                'JPEG',
                0,
                0,
                210,
                297,
                undefined,
                'FAST',
            )
        }

        return pdf.output(
            'blob',
        )
    } finally {
        showPage(
            selectedPage,
        )
    }
}

defineExpose({
    buildDocumentPdf,
    printDocument,
})

/* -------------------------------------------------------------------------- */
/* Iframe load                                                                */
/* -------------------------------------------------------------------------- */

async function handleIframeLoad() {
    await nextTick()

    const document =
        getIframeDocument()

    if (!document) {
        return
    }

    document.documentElement
        .style.overflow =
        'hidden'

    if (
        document.body
    ) {
        document.body.style.overflow =
            'hidden'
    }

    currentPage.value =
        0

    detectPages()

    emit(
        'pageCountChange',
        pageCount.value,
    )

    showPage(
        0,
    )

    centerDocument()
}

function scheduleIframeInitialization() {
    if (
        iframeInitializationFrame !==
        null
    ) {
        cancelAnimationFrame(
            iframeInitializationFrame,
        )
    }

    iframeInitializationFrame =
        requestAnimationFrame(
            () => {
                iframeInitializationFrame =
                    null

                void handleIframeLoad()
            },
        )
}

/* -------------------------------------------------------------------------- */
/* Reset                                                                      */
/* -------------------------------------------------------------------------- */

function resetPreview() {
    currentPage.value =
        0

    pageCount.value =
        1

    zoom.value =
        1

    centerDocument()
}

/* -------------------------------------------------------------------------- */
/* Watch                                                                      */
/* -------------------------------------------------------------------------- */

watch(
    () =>
        props.html,

    async () => {
        previewDocumentVersion.value++

        resetPreview()

        pageCount.value =
            getDeclaredPageCount(
                props.html,
            )

        await nextTick()

        scheduleIframeInitialization()
    },
    {
        immediate:
            true,

        flush:
            'post',
    },
)

/* -------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* -------------------------------------------------------------------------- */

onMounted(() => {
    if (
        !viewportRef.value ||
        typeof ResizeObserver ===
            'undefined'
    ) {
        return
    }

    resizeObserver =
        new ResizeObserver(
            ([entry]) => {
                const width =
                    entry
                        ?.contentRect
                        .width ??
                    0

                const height =
                    entry
                        ?.contentRect
                        .height ??
                    0

                updateFitScale(
                    width,
                    height,
                )
            },
        )

    resizeObserver.observe(
        viewportRef.value,
    )
})

onBeforeUnmount(() => {
    if (
        iframeInitializationFrame !==
        null
    ) {
        cancelAnimationFrame(
            iframeInitializationFrame,
        )
    }

    iframeInitializationFrame =
        null

    resizeObserver
        ?.disconnect()

    resizeObserver =
        null

    panInteraction =
        null
})
</script>

<template>
    <div
        class="
            relative
            h-full
            min-h-0
            min-w-0
            overflow-hidden
        "
    >
        <!-- ================================================================ -->
        <!-- DOCUMENT VIEWPORT                                                -->
        <!-- ================================================================ -->

        <div
            ref="viewportRef"
            class="
                absolute
                inset-0
                min-h-0
                min-w-0
                touch-none
                select-none
                m-6
            "
            :class="{
                'cursor-grab':
                    zoom > 1 &&
                    !isPanning,

                'cursor-grabbing':
                    isPanning,
            }"
            @pointermove="
                handlePointerMove
            "
            @pointerdown="
                startPan
            "
            @pointerup="
                stopPan
            "
            @pointercancel="
                stopPan
            "
            @lostpointercapture="
                stopPan
            "
        >
            <div
                :style="
                    pageStyle
                "
                class="
                    absolute
                    overflow-hidden
                    bg-white
                    shadow-lg
                    ring-1
                    ring-almostwhite/50
                "
            >
                <iframe
                    v-if="
                        html
                    "
                    ref="iframeRef"
                    :key="
                        previewDocumentVersion
                    "
                    :srcdoc="
                        html
                    "
                    :title="
                        title
                    "
                    :style="
                        frameStyle
                    "
                    scrolling="no"
                    class="
                        pointer-events-none
                        absolute
                        left-0
                        top-0
                        border-0
                        bg-white
                
                    "
                    @load="
                        handleIframeLoad
                    "
                />

                <div
                    v-else
                    class="
                        flex
                        h-full
                        w-full
                        items-center
                        justify-center
                        p-8
                        text-center
                        text-normal
                        text-darkgrey
                    "
                >
                    {{
                        error ??
                        title
                    }}
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- BOTTOM CONTROL PANEL                                             -->
        <!-- ================================================================ -->

        <div
            v-if="
                html
            "
            class="
                absolute
                bottom-0
                left-1/2
                z-10
                flex
                -translate-x-1/2
                items-center
                gap-1
                rounded-md
                bg-darkgrey
                p-1
                text-white
            "
        >
            <template
                v-if="
                    pageCount > 1
                "
            >
                <!-- Previous -->

                <button
                    type="button"
                    :disabled="
                        !hasPreviousPage
                    "
                    class="
                        flex
                        h-8
                        w-8
                        items-center
                        justify-center
                        rounded-md
                        transition
                        hover:bg-white/10
                        disabled:pointer-events-none
                        disabled:opacity-25
                    "
                    aria-label="
                        Predchádzajúca strana
                    "
                    title="
                        Predchádzajúca strana
                    "
                    @click="
                        previousPage
                    "
                >
                    <i
                        class="
                            bi
                            bi-chevron-left
                        "
                    />
                </button>

                <!-- Page -->

                <div
                    class="
                        min-w-12
                        px-1
                        text-center
                        text-xs
                        font-medium
                    "
                >
                    {{
                        currentPage +
                        1
                    }}
                    /
                    {{
                        pageCount
                    }}
                </div>

                <!-- Next -->

                <button
                    type="button"
                    :disabled="
                        !hasNextPage
                    "
                    class="
                        flex
                        h-8
                        w-8
                        items-center
                        justify-center
                        rounded-md
                        transition
                        hover:bg-white/10
                        disabled:pointer-events-none
                        disabled:opacity-25
                    "
                    aria-label="
                        Nasledujúca strana
                    "
                    title="
                        Nasledujúca strana
                    "
                    @click="
                        nextPage
                    "
                >
                    <i
                        class="
                            bi
                            bi-chevron-right
                        "
                    />
                </button>

                <!-- Divider -->

                <div
                    class="
                        mx-1
                        h-5
                        w-px
                        bg-white/20
                    "
                />
            </template>

            <!-- Zoom out -->

            <button
                type="button"
                :disabled="
                    !canZoomOut
                "
                class="
                    flex
                    h-8
                    w-8
                    items-center
                    justify-center
                    rounded-md
                    transition
                    hover:bg-white/10
                    disabled:pointer-events-none
                    disabled:opacity-25
                "
                aria-label="
                    Oddialiť
                "
                title="
                    Oddialiť
                "
                @click="
                    zoomOut
                "
            >
                <i
                    class="
                        bi
                        bi-dash-lg
                    "
                />
            </button>

            <!-- Percentage -->

            <button
                type="button"
                class="
                    min-w-14
                    rounded-md
                    px-1
                    py-1.5
                    text-center
                    text-xs
                    font-medium
                    transition
                    hover:bg-white/10
                "
                title="
                    Prispôsobiť veľkosti
                "
                @click="
                    resetZoom
                "
            >
                {{
                    zoomPercentage
                }}%
            </button>

            <!-- Zoom in -->

            <button
                type="button"
                :disabled="
                    !canZoomIn
                "
                class="
                    flex
                    h-8
                    w-8
                    items-center
                    justify-center
                    rounded-md
                    transition
                    hover:bg-white/10
                    disabled:pointer-events-none
                    disabled:opacity-25
                "
                aria-label="
                    Priblížiť
                "
                title="
                    Priblížiť
                "
                @click="
                    zoomIn
                "
            >
                <i
                    class="
                        bi
                        bi-plus-lg
                    "
                />
            </button>

            <!-- Fit -->

            <button
                type="button"
                class="
                    flex
                    h-8
                    w-8
                    items-center
                    justify-center
                    rounded-md
                    transition
                    hover:bg-white/10
                "
                aria-label="
                    Prispôsobiť veľkosti
                "
                title="
                    Prispôsobiť veľkosti
                "
                @click="
                    resetZoom
                "
            >
                <i
                    class="
                        bi
                        bi-arrows-angle-contract
                    "
                />
            </button>
        </div>

        <!-- ================================================================ -->
        <!-- LOADING                                                          -->
        <!-- ================================================================ -->

        <div
            v-if="
                loading
            "
            class="
                absolute
                inset-0
                z-30
                flex
                items-center
                justify-center
                bg-white/75
                backdrop-blur-sm
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
</template>