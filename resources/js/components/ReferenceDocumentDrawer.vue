<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue'

import api from '@/services/api'

let floatingLayerCounter = 3000

const props = withDefaults(
    defineProps<{
        available?: boolean
        bubbleIndex?: number
        handleLabel?: string
        previewUrl?: string | null
        previewUrlEndpoint?: string | null
        title: string
    }>(),
    {
        available: false,
        bubbleIndex: 0,
        handleLabel:
            'Zobraziť referenčný dokument',
        previewUrl: null,
        previewUrlEndpoint: null,
    },
)

/* -------------------------------------------------------------------------- */
/* Constants                                                                  */
/* -------------------------------------------------------------------------- */

const AREA_MARGIN = 2

const BUBBLE_HEIGHT = 34
const BUBBLE_GAP = 6

const MIN_BUBBLE_WIDTH = 110
const MAX_BUBBLE_WIDTH = 420
const BUBBLE_EXTRA_SPACE = 24

const DEFAULT_WINDOW_WIDTH = 440
const DEFAULT_WINDOW_HEIGHT = 520

const MIN_WINDOW_WIDTH = 320
const MIN_WINDOW_HEIGHT = 280

const VELOCITY_MULTIPLIER = 1.08
const FRICTION = 3.4

const BOUNCE = 0.05
const STOP_SPEED = 0.015

const MORPH_DURATION = 340

/*
 * Content is already loaded at this point.
 *
 * This is only the short pause during which
 * the user can actually see the document
 * before it collapses.
 */
const INITIAL_SHRINK_DELAY = 550

/*
 * Safety timeout for an iframe.
 *
 * We don't want a failed external document
 * to leave the intro permanently open.
 */
const PREVIEW_LOAD_TIMEOUT = 8000

type WindowInteractionType =
    | 'drag'
    | 'resize-top-left'
    | 'resize-top-right'
    | 'resize-bottom-left'
    | 'resize-bottom-right'

/* -------------------------------------------------------------------------- */
/* Playground                                                                 */
/* -------------------------------------------------------------------------- */

const playgroundRef =
    ref<HTMLElement | null>(null)

const bubbleMeasureRef =
    ref<HTMLElement | null>(null)

const areaSize = ref({
    width: 0,
    height: 0,
})

let resizeObserver:
    ResizeObserver |
    null = null

/* -------------------------------------------------------------------------- */
/* General                                                                    */
/* -------------------------------------------------------------------------- */

const isOpen =
    ref(false)

const isLoading =
    ref(false)

const resolvedPreviewUrl =
    ref<string | null>(null)

const previewError =
    ref<string | null>(null)

const isPreviewFrameLoaded =
    ref(false)

const zIndex = ref(
    2000 +
        props.bubbleIndex,
)

let previewRequestId =
    0

/* -------------------------------------------------------------------------- */
/* Bubble                                                                     */
/* -------------------------------------------------------------------------- */

const bubbleWidth =
    ref(MIN_BUBBLE_WIDTH)

const bubblePosition =
    ref({
        x: 0,
        y: 0,
    })

const isBubbleDragging =
    ref(false)

const isBubbleFlying =
    ref(false)

const bubbleHasBeenMoved =
    ref(false)

let suppressBubbleClick =
    false

let bubbleAnimationFrame:
    number |
    null = null

let bubbleDrag:
    | {
          pointerX: number
          pointerY: number

          startX: number
          startY: number

          lastPointerX: number
          lastPointerY: number
          lastTime: number

          velocityX: number
          velocityY: number

          moved: boolean
      }
    | null = null

let activeBubblePointerId:
    number |
    null = null

let activeBubbleElement:
    HTMLElement |
    null = null

/* -------------------------------------------------------------------------- */
/* Window                                                                     */
/* -------------------------------------------------------------------------- */

const windowPosition =
    ref({
        x: 0,
        y: 0,
    })

const windowSize =
    ref({
        width:
            DEFAULT_WINDOW_WIDTH,

        height:
            DEFAULT_WINDOW_HEIGHT,
    })

const preferredWindowSize =
    ref({
        width:
            DEFAULT_WINDOW_WIDTH,

        height:
            DEFAULT_WINDOW_HEIGHT,
    })

const isWindowDragging =
    ref(false)

const isWindowResizing =
    ref(false)

let windowInitialized =
    false

let windowInteraction:
    | {
          type:
              WindowInteractionType

          pointerX: number
          pointerY: number

          x: number
          y: number

          width: number
          height: number

          left: number
          right: number
          top: number
          bottom: number
      }
    | null = null

let activeWindowPointerId:
    number |
    null = null

let activeWindowElement:
    HTMLElement |
    null = null

/* -------------------------------------------------------------------------- */
/* Morph / intro                                                              */
/* -------------------------------------------------------------------------- */

const isMorphing =
    ref(false)

const isInitialIntro =
    ref(false)

let morphTimer:
    ReturnType<
        typeof setTimeout
    > |
    null = null

let minimizeTimer:
    ReturnType<
        typeof setTimeout
    > |
    null = null

let initialShrinkTimer:
    ReturnType<
        typeof setTimeout
    > |
    null = null

let hasPlayedInitialPreview =
    false

/* -------------------------------------------------------------------------- */
/* Computed                                                                   */
/* -------------------------------------------------------------------------- */

const hasDocument =
    computed(
        () =>
            props.available ||
            !!props.previewUrl ||
            !!props.previewUrlEndpoint,
    )

const shellStyle =
    computed(() => {
        /*
         * During the intro, physically anchor
         * the right edge of the shell.
         */
        if (
            isOpen.value &&
            isInitialIntro.value
        ) {
            return {
                left:
                    'auto',

                right:
                    `${AREA_MARGIN}px`,

                top:
                    `${windowPosition.value.y}px`,

                width:
                    `${windowSize.value.width}px`,

                height:
                    `${windowSize.value.height}px`,

                zIndex:
                    zIndex.value,
            }
        }

        /*
         * Normal expanded window.
         */
        if (
            isOpen.value
        ) {
            return {
                left:
                    `${windowPosition.value.x}px`,

                right:
                    'auto',

                top:
                    `${windowPosition.value.y}px`,

                width:
                    `${windowSize.value.width}px`,

                height:
                    `${windowSize.value.height}px`,

                zIndex:
                    zIndex.value,
            }
        }

        /*
         * Minimized bubble.
         */
        return {
            left:
                `${bubblePosition.value.x}px`,

            right:
                'auto',

            top:
                `${bubblePosition.value.y}px`,

            width:
                `${bubbleWidth.value}px`,

            height:
                `${BUBBLE_HEIGHT}px`,

            zIndex:
                zIndex.value,
        }
    })

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

function bringToFront() {
    floatingLayerCounter++

    zIndex.value =
        floatingLayerCounter
}

function getAreaWidth() {
    return areaSize.value.width
}

function getAreaHeight() {
    return areaSize.value.height
}

function getBubbleWidth() {
    return bubbleWidth.value
}

function updateAreaSize() {
    if (
        !playgroundRef.value
    ) {
        return
    }

    areaSize.value = {
        width:
            playgroundRef.value
                .clientWidth,

        height:
            playgroundRef.value
                .clientHeight,
    }
}

/* -------------------------------------------------------------------------- */
/* Dynamic bubble width                                                       */
/* -------------------------------------------------------------------------- */

function updateBubbleWidth() {
    if (
        !bubbleMeasureRef.value
    ) {
        return
    }

    const measuredWidth =
        Math.ceil(
            bubbleMeasureRef.value
                .getBoundingClientRect()
                .width,
        ) +
        BUBBLE_EXTRA_SPACE

    const maximumAvailableWidth =
        Math.max(
            0,

            getAreaWidth() -
                AREA_MARGIN *
                    2,
        )

    if (
        maximumAvailableWidth <=
        0
    ) {
        return
    }

    const minimumWidth =
        Math.min(
            MIN_BUBBLE_WIDTH,
            maximumAvailableWidth,
        )

    const maximumWidth =
        Math.min(
            MAX_BUBBLE_WIDTH,
            maximumAvailableWidth,
        )

    bubbleWidth.value =
        clamp(
            measuredWidth,
            minimumWidth,
            maximumWidth,
        )
}

async function refreshBubbleWidth() {
    await nextTick()

    updateBubbleWidth()

    if (
        isOpen.value
    ) {
        return
    }

    if (
        bubbleHasBeenMoved.value
    ) {
        fitBubbleToArea()

        return
    }

    initializeBubblePosition()
}

/* -------------------------------------------------------------------------- */
/* Initial bubble position                                                    */
/* -------------------------------------------------------------------------- */

function getInitialBubblePosition() {
    const width =
        getAreaWidth()

    const height =
        getAreaHeight()

    const currentBubbleWidth =
        getBubbleWidth()

    const x =
        width -
        currentBubbleWidth -
        AREA_MARGIN

    const y =
        AREA_MARGIN +
        props.bubbleIndex *
            (
                BUBBLE_HEIGHT +
                BUBBLE_GAP
            )

    return {
        x:
            clamp(
                x,

                AREA_MARGIN,

                Math.max(
                    AREA_MARGIN,

                    width -
                        currentBubbleWidth -
                        AREA_MARGIN,
                ),
            ),

        y:
            clamp(
                y,

                AREA_MARGIN,

                Math.max(
                    AREA_MARGIN,

                    height -
                        BUBBLE_HEIGHT -
                        AREA_MARGIN,
                ),
            ),
    }
}

function initializeBubblePosition() {
    if (
        bubbleHasBeenMoved.value
    ) {
        fitBubbleToArea()

        return
    }

    bubblePosition.value =
        getInitialBubblePosition()
}

/* -------------------------------------------------------------------------- */
/* Bubble bounds                                                              */
/* -------------------------------------------------------------------------- */

function fitBubbleToArea() {
    bubblePosition.value = {
        x:
            clamp(
                bubblePosition.value.x,

                AREA_MARGIN,

                Math.max(
                    AREA_MARGIN,

                    getAreaWidth() -
                        getBubbleWidth() -
                        AREA_MARGIN,
                ),
            ),

        y:
            clamp(
                bubblePosition.value.y,

                AREA_MARGIN,

                Math.max(
                    AREA_MARGIN,

                    getAreaHeight() -
                        BUBBLE_HEIGHT -
                        AREA_MARGIN,
                ),
            ),
    }
}

/* -------------------------------------------------------------------------- */
/* Bubble physics                                                             */
/* -------------------------------------------------------------------------- */

function cancelBubblePhysics() {
    if (
        bubbleAnimationFrame !==
        null
    ) {
        cancelAnimationFrame(
            bubbleAnimationFrame,
        )

        bubbleAnimationFrame =
            null
    }

    isBubbleFlying.value =
        false
}

function startBubblePhysics(
    velocityX: number,
    velocityY: number,
) {
    cancelBubblePhysics()

    let vx =
        velocityX *
        VELOCITY_MULTIPLIER

    let vy =
        velocityY *
        VELOCITY_MULTIPLIER

    if (
        Math.abs(vx) <
            STOP_SPEED &&
        Math.abs(vy) <
            STOP_SPEED
    ) {
        return
    }

    isBubbleFlying.value =
        true

    bubbleHasBeenMoved.value =
        true

    let previousTime =
        performance.now()

    const animate = (
        time: number,
    ) => {
        const delta =
            Math.min(
                time -
                    previousTime,

                32,
            )

        previousTime =
            time

        let nextX =
            bubblePosition.value.x +
            vx *
                delta

        let nextY =
            bubblePosition.value.y +
            vy *
                delta

        const minimumX =
            AREA_MARGIN

        const maximumX =
            Math.max(
                AREA_MARGIN,

                getAreaWidth() -
                    getBubbleWidth() -
                    AREA_MARGIN,
            )

        const minimumY =
            AREA_MARGIN

        const maximumY =
            Math.max(
                AREA_MARGIN,

                getAreaHeight() -
                    BUBBLE_HEIGHT -
                    AREA_MARGIN,
            )

        if (
            nextX <
            minimumX
        ) {
            nextX =
                minimumX

            vx =
                Math.abs(
                    vx,
                ) *
                BOUNCE
        } else if (
            nextX >
            maximumX
        ) {
            nextX =
                maximumX

            vx =
                -Math.abs(
                    vx,
                ) *
                BOUNCE
        }

        if (
            nextY <
            minimumY
        ) {
            nextY =
                minimumY

            vy =
                Math.abs(
                    vy,
                ) *
                BOUNCE
        } else if (
            nextY >
            maximumY
        ) {
            nextY =
                maximumY

            vy =
                -Math.abs(
                    vy,
                ) *
                BOUNCE
        }

        bubblePosition.value = {
            x:
                nextX,

            y:
                nextY,
        }

        const friction =
            Math.exp(
                -FRICTION *
                    (
                        delta /
                        1000
                    ),
            )

        vx *= friction
        vy *= friction

        if (
            Math.abs(vx) <
                STOP_SPEED &&
            Math.abs(vy) <
                STOP_SPEED
        ) {
            isBubbleFlying.value =
                false

            bubbleAnimationFrame =
                null

            return
        }

        bubbleAnimationFrame =
            requestAnimationFrame(
                animate,
            )
    }

    bubbleAnimationFrame =
        requestAnimationFrame(
            animate,
        )
}

/* -------------------------------------------------------------------------- */
/* Bubble dragging                                                            */
/* -------------------------------------------------------------------------- */

function startBubbleDrag(
    event: PointerEvent,
) {
    if (
        event.button !==
        0
    ) {
        return
    }

    event.preventDefault()

    bringToFront()
    cancelBubblePhysics()

    const element =
        event.currentTarget as HTMLElement

    activeBubblePointerId =
        event.pointerId

    activeBubbleElement =
        element

    try {
        element.setPointerCapture(
            event.pointerId,
        )
    } catch {
        //
    }

    isBubbleDragging.value =
        true

    suppressBubbleClick =
        false

    const time =
        performance.now()

    bubbleDrag = {
        pointerX:
            event.clientX,

        pointerY:
            event.clientY,

        startX:
            bubblePosition.value.x,

        startY:
            bubblePosition.value.y,

        lastPointerX:
            event.clientX,

        lastPointerY:
            event.clientY,

        lastTime:
            time,

        velocityX:
            0,

        velocityY:
            0,

        moved:
            false,
    }
}

function moveBubble(
    event: PointerEvent,
) {
    if (
        !bubbleDrag ||
        activeBubblePointerId ===
            null ||
        event.pointerId !==
            activeBubblePointerId
    ) {
        return
    }

    const deltaX =
        event.clientX -
        bubbleDrag.pointerX

    const deltaY =
        event.clientY -
        bubbleDrag.pointerY

    if (
        Math.abs(
            deltaX,
        ) >
            2 ||
        Math.abs(
            deltaY,
        ) >
            2
    ) {
        bubbleDrag.moved =
            true

        suppressBubbleClick =
            true

        bubbleHasBeenMoved.value =
            true
    }

    bubblePosition.value = {
        x:
            clamp(
                bubbleDrag.startX +
                    deltaX,

                AREA_MARGIN,

                Math.max(
                    AREA_MARGIN,

                    getAreaWidth() -
                        getBubbleWidth() -
                        AREA_MARGIN,
                ),
            ),

        y:
            clamp(
                bubbleDrag.startY +
                    deltaY,

                AREA_MARGIN,

                Math.max(
                    AREA_MARGIN,

                    getAreaHeight() -
                        BUBBLE_HEIGHT -
                        AREA_MARGIN,
                ),
            ),
    }

    const now =
        performance.now()

    const elapsed =
        Math.max(
            now -
                bubbleDrag.lastTime,

            1,
        )

    const instantaneousX =
        (
            event.clientX -
            bubbleDrag.lastPointerX
        ) /
        elapsed

    const instantaneousY =
        (
            event.clientY -
            bubbleDrag.lastPointerY
        ) /
        elapsed

    bubbleDrag.velocityX =
        bubbleDrag.velocityX *
            0.2 +
        instantaneousX *
            0.8

    bubbleDrag.velocityY =
        bubbleDrag.velocityY *
            0.2 +
        instantaneousY *
            0.8

    bubbleDrag.lastPointerX =
        event.clientX

    bubbleDrag.lastPointerY =
        event.clientY

    bubbleDrag.lastTime =
        now
}

function stopBubbleDrag(
    event?: PointerEvent,
) {
    if (
        !bubbleDrag
    ) {
        return
    }

    if (
        event &&
        activeBubblePointerId !==
            null &&
        event.pointerId !==
            activeBubblePointerId
    ) {
        return
    }

    const {
        velocityX,
        velocityY,
        moved,
    } =
        bubbleDrag

    if (
        activeBubbleElement &&
        activeBubblePointerId !==
            null
    ) {
        try {
            if (
                activeBubbleElement
                    .hasPointerCapture(
                        activeBubblePointerId,
                    )
            ) {
                activeBubbleElement
                    .releasePointerCapture(
                        activeBubblePointerId,
                    )
            }
        } catch {
            //
        }
    }

    activeBubblePointerId =
        null

    activeBubbleElement =
        null

    bubbleDrag =
        null

    isBubbleDragging.value =
        false

    if (
        moved
    ) {
        startBubblePhysics(
            velocityX,
            velocityY,
        )
    }
}

function handleBubbleClick() {
    if (
        suppressBubbleClick
    ) {
        suppressBubbleClick =
            false

        return
    }

    void openDocument()
}

/* -------------------------------------------------------------------------- */
/* Window sizing                                                              */
/* -------------------------------------------------------------------------- */

function initializeWindowSize() {
    if (
        windowInitialized
    ) {
        return
    }

    const maximumWidth =
        Math.max(
            0,

            getAreaWidth() -
                AREA_MARGIN *
                    2,
        )

    const maximumHeight =
        Math.max(
            0,

            getAreaHeight() -
                AREA_MARGIN *
                    2,
        )

    preferredWindowSize.value = {
        width:
            Math.min(
                DEFAULT_WINDOW_WIDTH,
                maximumWidth,
            ),

        height:
            Math.min(
                DEFAULT_WINDOW_HEIGHT,
                maximumHeight,
            ),
    }

    windowSize.value = {
        ...preferredWindowSize.value,
    }

    windowInitialized =
        true
}

/* -------------------------------------------------------------------------- */
/* Window bounds                                                              */
/* -------------------------------------------------------------------------- */

function fitWindowToArea() {
    const maximumWidth =
        Math.max(
            0,

            getAreaWidth() -
                AREA_MARGIN *
                    2,
        )

    const maximumHeight =
        Math.max(
            0,

            getAreaHeight() -
                AREA_MARGIN *
                    2,
        )

    const minimumWidth =
        Math.min(
            MIN_WINDOW_WIDTH,
            maximumWidth,
        )

    const minimumHeight =
        Math.min(
            MIN_WINDOW_HEIGHT,
            maximumHeight,
        )

    windowSize.value = {
        width:
            clamp(
                windowSize.value
                    .width,

                minimumWidth,
                maximumWidth,
            ),

        height:
            clamp(
                windowSize.value
                    .height,

                minimumHeight,
                maximumHeight,
            ),
    }

    windowPosition.value = {
        x:
            clamp(
                windowPosition.value
                    .x,

                AREA_MARGIN,

                Math.max(
                    AREA_MARGIN,

                    getAreaWidth() -
                        windowSize.value
                            .width -
                        AREA_MARGIN,
                ),
            ),

        y:
            clamp(
                windowPosition.value
                    .y,

                AREA_MARGIN,

                Math.max(
                    AREA_MARGIN,

                    getAreaHeight() -
                        windowSize.value
                            .height -
                        AREA_MARGIN,
                ),
            ),
    }
}

/* -------------------------------------------------------------------------- */
/* Morph                                                                      */
/* -------------------------------------------------------------------------- */

function finishMorphLater() {
    if (
        morphTimer
    ) {
        clearTimeout(
            morphTimer,
        )
    }

    morphTimer =
        setTimeout(
            () => {
                isMorphing.value =
                    false
            },

            MORPH_DURATION +
                50,
        )
}

/* -------------------------------------------------------------------------- */
/* Preview                                                                    */
/* -------------------------------------------------------------------------- */

function handlePreviewFrameLoaded() {
    isPreviewFrameLoaded.value =
        true
}

async function waitForPreviewFrame() {
    /*
     * No iframe is being used.
     *
     * This means slot content.
     */
    if (
        !resolvedPreviewUrl.value
    ) {
        await nextTick()

        await new Promise<void>(
            resolve => {
                requestAnimationFrame(
                    () => {
                        requestAnimationFrame(
                            () => {
                                resolve()
                            },
                        )
                    },
                )
            },
        )

        return
    }

    if (
        isPreviewFrameLoaded.value
    ) {
        return
    }

    await new Promise<void>(
        resolve => {
            let finished =
                false

            let stopWatcher:
                (() => void) |
                null = null

            let timeout:
                ReturnType<
                    typeof setTimeout
                > |
                null = null

            const finish = () => {
                if (
                    finished
                ) {
                    return
                }

                finished =
                    true

                stopWatcher?.()

                if (
                    timeout
                ) {
                    clearTimeout(
                        timeout,
                    )
                }

                resolve()
            }

            stopWatcher =
                watch(
                    isPreviewFrameLoaded,

                    loaded => {
                        if (
                            loaded
                        ) {
                            finish()
                        }
                    },
                )

            timeout =
                setTimeout(
                    finish,
                    PREVIEW_LOAD_TIMEOUT,
                )
        },
    )
}

async function loadPreview() {
    const requestId =
        ++previewRequestId

    isLoading.value =
        true

    previewError.value =
        null

    isPreviewFrameLoaded.value =
        false

    resolvedPreviewUrl.value =
        props.previewUrl ??
        null

    /*
     * Slot mode.
     */
    if (
        !props.previewUrl &&
        !props.previewUrlEndpoint
    ) {
        isLoading.value =
            false

        return
    }

    try {
        if (
            !resolvedPreviewUrl.value &&
            props.previewUrlEndpoint
        ) {
            const response =
                await api.get(
                    props.previewUrlEndpoint,
                )

            if (
                requestId !==
                previewRequestId
            ) {
                return
            }

            resolvedPreviewUrl.value =
                response.data
                    ?.data
                    ?.preview_url ??
                response.data
                    ?.preview_url ??
                null
        }

        if (
            !resolvedPreviewUrl.value
        ) {
            throw new Error(
                'Preview URL is missing.',
            )
        }
    } catch (error) {
        if (
            requestId !==
            previewRequestId
        ) {
            return
        }

        console.error(
            'Reference document preview failed:',
            error,
        )

        previewError.value =
            'Dokument sa nepodarilo načítať.'

        resolvedPreviewUrl.value =
            null
    } finally {
        if (
            requestId ===
            previewRequestId
        ) {
            isLoading.value =
                false
        }
    }
}

/* -------------------------------------------------------------------------- */
/* Open                                                                       */
/* -------------------------------------------------------------------------- */

async function openDocument() {
    cancelBubblePhysics()

    bringToFront()

    initializeWindowSize()

    isInitialIntro.value =
        false

    const targetWidth =
        preferredWindowSize.value
            .width

    const targetHeight =
        preferredWindowSize.value
            .height

    const currentBubbleWidth =
        getBubbleWidth()

    const anchorRight =
        bubblePosition.value.x +
        currentBubbleWidth

    const anchorTop =
        bubblePosition.value.y

    windowPosition.value = {
        x:
            anchorRight -
            currentBubbleWidth,

        y:
            anchorTop,
    }

    windowSize.value = {
        width:
            currentBubbleWidth,

        height:
            BUBBLE_HEIGHT,
    }

    isMorphing.value =
        true

    isOpen.value =
        true

    void loadPreview()

    await nextTick()

    requestAnimationFrame(
        () => {
            requestAnimationFrame(
                () => {
                    const finalWidth =
                        Math.min(
                            targetWidth,

                            Math.max(
                                0,

                                getAreaWidth() -
                                    AREA_MARGIN *
                                        2,
                            ),
                        )

                    const finalHeight =
                        Math.min(
                            targetHeight,

                            Math.max(
                                0,

                                getAreaHeight() -
                                    AREA_MARGIN *
                                        2,
                            ),
                        )

                    const targetX =
                        clamp(
                            anchorRight -
                                finalWidth,

                            AREA_MARGIN,

                            Math.max(
                                AREA_MARGIN,

                                getAreaWidth() -
                                    finalWidth -
                                    AREA_MARGIN,
                            ),
                        )

                    const targetY =
                        clamp(
                            anchorTop,

                            AREA_MARGIN,

                            Math.max(
                                AREA_MARGIN,

                                getAreaHeight() -
                                    finalHeight -
                                    AREA_MARGIN,
                            ),
                        )

                    windowPosition.value = {
                        x:
                            targetX,

                        y:
                            targetY,
                    }

                    windowSize.value = {
                        width:
                            finalWidth,

                        height:
                            finalHeight,
                    }
                },
            )
        },
    )

    finishMorphLater()
}

/* -------------------------------------------------------------------------- */
/* Normal minimize                                                            */
/* -------------------------------------------------------------------------- */

function minimizeDocument(
    markAsMoved = true,
) {
    if (
        isMorphing.value
    ) {
        return
    }

    if (
        isInitialIntro.value
    ) {
        minimizeInitialIntro()

        return
    }

    bringToFront()

    preferredWindowSize.value = {
        ...windowSize.value,
    }

    const currentBubbleWidth =
        getBubbleWidth()

    const anchorRight =
        windowPosition.value.x +
        windowSize.value.width

    const anchorTop =
        windowPosition.value.y

    const targetBubbleX =
        clamp(
            anchorRight -
                currentBubbleWidth,

            AREA_MARGIN,

            Math.max(
                AREA_MARGIN,

                getAreaWidth() -
                    currentBubbleWidth -
                    AREA_MARGIN,
            ),
        )

    const targetBubbleY =
        clamp(
            anchorTop,

            AREA_MARGIN,

            Math.max(
                AREA_MARGIN,

                getAreaHeight() -
                    BUBBLE_HEIGHT -
                    AREA_MARGIN,
            ),
        )

    bubblePosition.value = {
        x:
            targetBubbleX,

        y:
            targetBubbleY,
    }

    if (
        markAsMoved
    ) {
        bubbleHasBeenMoved.value =
            true
    }

    isMorphing.value =
        true

    windowPosition.value = {
        x:
            targetBubbleX,

        y:
            targetBubbleY,
    }

    windowSize.value = {
        width:
            currentBubbleWidth,

        height:
            BUBBLE_HEIGHT,
    }

    if (
        minimizeTimer
    ) {
        clearTimeout(
            minimizeTimer,
        )
    }

    minimizeTimer =
        setTimeout(
            () => {
                isOpen.value =
                    false

                fitBubbleToArea()

                windowSize.value = {
                    ...preferredWindowSize.value,
                }

                finishMorphLater()
            },

            MORPH_DURATION,
        )
}

/* -------------------------------------------------------------------------- */
/* Intro minimize                                                             */
/* -------------------------------------------------------------------------- */

function minimizeInitialIntro() {
    if (
        !isOpen.value ||
        isMorphing.value ||
        !isInitialIntro.value
    ) {
        return
    }

    const currentBubbleWidth =
        getBubbleWidth()

    bubblePosition.value =
        getInitialBubblePosition()

    isMorphing.value =
        true

    /*
     * Right remains fixed because shellStyle
     * uses right: AREA_MARGIN during the intro.
     */
    windowSize.value = {
        width:
            currentBubbleWidth,

        height:
            BUBBLE_HEIGHT,
    }

    windowPosition.value = {
        ...windowPosition.value,

        y:
            bubblePosition.value.y,
    }

    if (
        minimizeTimer
    ) {
        clearTimeout(
            minimizeTimer,
        )
    }

    minimizeTimer =
        setTimeout(
            () => {
                isOpen.value =
                    false

                isInitialIntro.value =
                    false

                isMorphing.value =
                    false

                windowSize.value = {
                    ...preferredWindowSize.value,
                }

                fitBubbleToArea()
            },

            MORPH_DURATION,
        )
}

/* -------------------------------------------------------------------------- */
/* Initial intro                                                              */
/* -------------------------------------------------------------------------- */

async function playInitialPreview() {
    if (
        hasPlayedInitialPreview ||
        !hasDocument.value
    ) {
        return
    }

    hasPlayedInitialPreview =
        true

    initializeWindowSize()

    bubbleHasBeenMoved.value =
        false

    bubblePosition.value =
        getInitialBubblePosition()

    const finalWidth =
        Math.min(
            preferredWindowSize.value
                .width,

            Math.max(
                0,

                getAreaWidth() -
                    AREA_MARGIN *
                        2,
            ),
        )

    const finalHeight =
        Math.min(
            preferredWindowSize.value
                .height,

            Math.max(
                0,

                getAreaHeight() -
                    AREA_MARGIN *
                        2,
            ),
        )

    windowPosition.value = {
        x:
            Math.max(
                AREA_MARGIN,

                getAreaWidth() -
                    finalWidth -
                    AREA_MARGIN,
            ),

        y:
            bubblePosition.value.y,
    }

    windowSize.value = {
        width:
            finalWidth,

        height:
            finalHeight,
    }

    /*
     * Put the expanded window on screen first.
     */
    isInitialIntro.value =
        true

    isOpen.value =
        true

    isMorphing.value =
        false

    bringToFront()

    /*
     * Now actually load/render the document.
     */
    await nextTick()

    await loadPreview()

    await nextTick()

    /*
     * If this is an iframe, this waits for the
     * iframe's actual load event.
     *
     * For slot content, it waits until Vue has
     * mounted and painted the slot.
     */
    await waitForPreviewFrame()

    if (
        !isOpen.value ||
        !isInitialIntro.value
    ) {
        return
    }

    /*
     * Document is visible now.
     *
     * Leave it there briefly, then collapse.
     */
    if (
        initialShrinkTimer
    ) {
        clearTimeout(
            initialShrinkTimer,
        )
    }

    initialShrinkTimer =
        setTimeout(
            () => {
                if (
                    isOpen.value &&
                    isInitialIntro.value &&
                    !isMorphing.value
                ) {
                    minimizeInitialIntro()
                }
            },

            INITIAL_SHRINK_DELAY,
        )
}

/* -------------------------------------------------------------------------- */
/* Window interaction                                                         */
/* -------------------------------------------------------------------------- */

function startWindowInteraction(
    event: PointerEvent,
    type:
        WindowInteractionType,
) {
    if (
        event.button !==
            0 ||
        isMorphing.value ||
        isInitialIntro.value
    ) {
        return
    }

    event.preventDefault()

    bringToFront()

    const element =
        event.currentTarget as HTMLElement

    activeWindowPointerId =
        event.pointerId

    activeWindowElement =
        element

    try {
        element.setPointerCapture(
            event.pointerId,
        )
    } catch {
        //
    }

    const left =
        windowPosition.value.x

    const top =
        windowPosition.value.y

    const right =
        left +
        windowSize.value.width

    const bottom =
        top +
        windowSize.value.height

    windowInteraction = {
        type,

        pointerX:
            event.clientX,

        pointerY:
            event.clientY,

        x:
            left,

        y:
            top,

        width:
            windowSize.value.width,

        height:
            windowSize.value.height,

        left,
        right,
        top,
        bottom,
    }

    if (
        type ===
        'drag'
    ) {
        isWindowDragging.value =
            true
    } else {
        isWindowResizing.value =
            true
    }
}

/* -------------------------------------------------------------------------- */
/* Window movement                                                            */
/* -------------------------------------------------------------------------- */

function moveWindow(
    event: PointerEvent,
) {
    if (
        !windowInteraction ||
        activeWindowPointerId ===
            null ||
        event.pointerId !==
            activeWindowPointerId
    ) {
        return
    }

    const deltaX =
        event.clientX -
        windowInteraction.pointerX

    const deltaY =
        event.clientY -
        windowInteraction.pointerY

    /* Drag */

    if (
        windowInteraction.type ===
        'drag'
    ) {
        windowPosition.value = {
            x:
                clamp(
                    windowInteraction.x +
                        deltaX,

                    AREA_MARGIN,

                    Math.max(
                        AREA_MARGIN,

                        getAreaWidth() -
                            windowSize.value
                                .width -
                            AREA_MARGIN,
                    ),
                ),

            y:
                clamp(
                    windowInteraction.y +
                        deltaY,

                    AREA_MARGIN,

                    Math.max(
                        AREA_MARGIN,

                        getAreaHeight() -
                            windowSize.value
                                .height -
                            AREA_MARGIN,
                    ),
                ),
        }

        return
    }

    /* Corner resizing */

    const type =
        windowInteraction.type

    const resizeLeft =
        type ===
            'resize-top-left' ||
        type ===
            'resize-bottom-left'

    const resizeRight =
        type ===
            'resize-top-right' ||
        type ===
            'resize-bottom-right'

    const resizeTop =
        type ===
            'resize-top-left' ||
        type ===
            'resize-top-right'

    const resizeBottom =
        type ===
            'resize-bottom-left' ||
        type ===
            'resize-bottom-right'

    let left =
        windowInteraction.left

    let right =
        windowInteraction.right

    let top =
        windowInteraction.top

    let bottom =
        windowInteraction.bottom

    if (
        resizeLeft
    ) {
        const effectiveMinWidth =
            Math.min(
                MIN_WINDOW_WIDTH,

                Math.max(
                    0,

                    getAreaWidth() -
                        AREA_MARGIN *
                            2,
                ),
            )

        const maximumLeft =
            Math.max(
                AREA_MARGIN,

                windowInteraction.right -
                    effectiveMinWidth,
            )

        left =
            clamp(
                windowInteraction.left +
                    deltaX,

                AREA_MARGIN,
                maximumLeft,
            )
    }

    if (
        resizeRight
    ) {
        const effectiveMinWidth =
            Math.min(
                MIN_WINDOW_WIDTH,

                Math.max(
                    0,

                    getAreaWidth() -
                        AREA_MARGIN *
                            2,
                ),
            )

        const minimumRight =
            Math.min(
                getAreaWidth() -
                    AREA_MARGIN,

                windowInteraction.left +
                    effectiveMinWidth,
            )

        right =
            clamp(
                windowInteraction.right +
                    deltaX,

                minimumRight,

                getAreaWidth() -
                    AREA_MARGIN,
            )
    }

    if (
        resizeTop
    ) {
        const effectiveMinHeight =
            Math.min(
                MIN_WINDOW_HEIGHT,

                Math.max(
                    0,

                    getAreaHeight() -
                        AREA_MARGIN *
                            2,
                ),
            )

        const maximumTop =
            Math.max(
                AREA_MARGIN,

                windowInteraction.bottom -
                    effectiveMinHeight,
            )

        top =
            clamp(
                windowInteraction.top +
                    deltaY,

                AREA_MARGIN,
                maximumTop,
            )
    }

    if (
        resizeBottom
    ) {
        const effectiveMinHeight =
            Math.min(
                MIN_WINDOW_HEIGHT,

                Math.max(
                    0,

                    getAreaHeight() -
                        AREA_MARGIN *
                            2,
                ),
            )

        const minimumBottom =
            Math.min(
                getAreaHeight() -
                    AREA_MARGIN,

                windowInteraction.top +
                    effectiveMinHeight,
            )

        bottom =
            clamp(
                windowInteraction.bottom +
                    deltaY,

                minimumBottom,

                getAreaHeight() -
                    AREA_MARGIN,
            )
    }

    windowPosition.value = {
        x:
            left,

        y:
            top,
    }

    windowSize.value = {
        width:
            right -
            left,

        height:
            bottom -
            top,
    }
}

function stopWindowInteraction(
    event?: PointerEvent,
) {
    if (
        !windowInteraction
    ) {
        return
    }

    if (
        event &&
        activeWindowPointerId !==
            null &&
        event.pointerId !==
            activeWindowPointerId
    ) {
        return
    }

    if (
        isWindowResizing.value
    ) {
        preferredWindowSize.value = {
            ...windowSize.value,
        }
    }

    if (
        activeWindowElement &&
        activeWindowPointerId !==
            null
    ) {
        try {
            if (
                activeWindowElement
                    .hasPointerCapture(
                        activeWindowPointerId,
                    )
            ) {
                activeWindowElement
                    .releasePointerCapture(
                        activeWindowPointerId,
                    )
            }
        } catch {
            //
        }
    }

    activeWindowPointerId =
        null

    activeWindowElement =
        null

    windowInteraction =
        null

    isWindowDragging.value =
        false

    isWindowResizing.value =
        false
}

/* -------------------------------------------------------------------------- */
/* Parent resize                                                              */
/* -------------------------------------------------------------------------- */

function handleAreaResize() {
    updateAreaSize()

    updateBubbleWidth()

    cancelBubblePhysics()

    if (
        isInitialIntro.value
    ) {
        return
    }

    if (
        isOpen.value
    ) {
        fitWindowToArea()

        preferredWindowSize.value = {
            ...windowSize.value,
        }

        return
    }

    if (
        bubbleHasBeenMoved.value
    ) {
        fitBubbleToArea()

        return
    }

    initializeBubblePosition()
}

/* -------------------------------------------------------------------------- */
/* Playground lifecycle                                                       */
/* -------------------------------------------------------------------------- */

function disconnectResizeObserver() {
    resizeObserver?.disconnect()

    resizeObserver =
        null
}

async function initializePlayground() {
    await nextTick()

    if (
        !playgroundRef.value
    ) {
        return
    }

    updateAreaSize()

    await nextTick()

    updateBubbleWidth()

    if (
        bubbleHasBeenMoved.value
    ) {
        fitBubbleToArea()
    } else {
        initializeBubblePosition()
    }

    disconnectResizeObserver()

    resizeObserver =
        new ResizeObserver(
            handleAreaResize,
        )

    resizeObserver.observe(
        playgroundRef.value,
    )
}

/* -------------------------------------------------------------------------- */
/* Keyboard                                                                   */
/* -------------------------------------------------------------------------- */

function handleKeydown(
    event: KeyboardEvent,
) {
    if (
        event.key ===
            'Escape' &&
        isOpen.value &&
        !isInitialIntro.value
    ) {
        minimizeDocument()
    }
}

/* -------------------------------------------------------------------------- */
/* Watches                                                                    */
/* -------------------------------------------------------------------------- */

watch(
    hasDocument,

    async available => {
        if (
            !available
        ) {
            cancelBubblePhysics()

            isOpen.value =
                false

            isInitialIntro.value =
                false

            disconnectResizeObserver()

            if (
                initialShrinkTimer
            ) {
                clearTimeout(
                    initialShrinkTimer,
                )

                initialShrinkTimer =
                    null
            }

            return
        }

        await initializePlayground()

        await playInitialPreview()
    },

    {
        immediate:
            true,
    },
)

watch(
    () =>
        props.title,

    () => {
        void refreshBubbleWidth()
    },
)

/* -------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* -------------------------------------------------------------------------- */

onMounted(() => {
    window.addEventListener(
        'keydown',
        handleKeydown,
    )
})

onBeforeUnmount(() => {
    cancelBubblePhysics()

    disconnectResizeObserver()

    window.removeEventListener(
        'keydown',
        handleKeydown,
    )

    if (
        activeBubbleElement &&
        activeBubblePointerId !==
            null
    ) {
        try {
            if (
                activeBubbleElement
                    .hasPointerCapture(
                        activeBubblePointerId,
                    )
            ) {
                activeBubbleElement
                    .releasePointerCapture(
                        activeBubblePointerId,
                    )
            }
        } catch {
            //
        }
    }

    if (
        activeWindowElement &&
        activeWindowPointerId !==
            null
    ) {
        try {
            if (
                activeWindowElement
                    .hasPointerCapture(
                        activeWindowPointerId,
                    )
            ) {
                activeWindowElement
                    .releasePointerCapture(
                        activeWindowPointerId,
                    )
            }
        } catch {
            //
        }
    }

    activeBubblePointerId =
        null

    activeBubbleElement =
        null

    activeWindowPointerId =
        null

    activeWindowElement =
        null

    if (
        morphTimer
    ) {
        clearTimeout(
            morphTimer,
        )
    }

    if (
        minimizeTimer
    ) {
        clearTimeout(
            minimizeTimer,
        )
    }

    if (
        initialShrinkTimer
    ) {
        clearTimeout(
            initialShrinkTimer,
        )
    }

    previewRequestId++
})
</script>

<template>
    <div
        v-if="hasDocument"
        ref="playgroundRef"
        class="
            pointer-events-none
            absolute
            inset-[2px]
            overflow-hidden
        "
    >
        <!-- ================================================================ -->
        <!-- HIDDEN BUBBLE WIDTH MEASUREMENT                                  -->
        <!-- ================================================================ -->

        <div
            ref="bubbleMeasureRef"
            aria-hidden="true"
            class="
                pointer-events-none
                invisible
                absolute
                left-0
                top-0
                flex
                h-[34px]
                w-max
                items-center
                gap-2
                whitespace-nowrap
                pl-1
                pr-1
                text-sm
                font-medium
            "
        >
            <span
                class="
                    flex
                    h-7
                    w-7
                    shrink-0
                    items-center
                    justify-center
                "
            >
                <i
                    class="
                        bi
                        bi-file-earmark-text
                    "
                />
            </span>

            <span>
                {{ title }}
            </span>

            <i
                class="
                    bi
                    bi-grip-vertical
                    shrink-0
                "
            />
        </div>

        <!-- ================================================================ -->
        <!-- FLOATING SHELL                                                   -->
        <!-- ================================================================ -->

        <div
            :style="shellStyle"
            class="
                reference-document-shell
                pointer-events-auto
                absolute
                origin-top-right
                overflow-hidden
                bg-darkgrey
            "
            :class="{
                'rounded-md shadow-lg':
                    !isOpen,

                'rounded-md shadow-[0_24px_64px_rgb(0_0_0/0.28)]':
                    isOpen,

                'is-bubble-dragging':
                    isBubbleDragging,

                'is-bubble-flying':
                    isBubbleFlying,

                'is-window-dragging':
                    isWindowDragging,

                'is-window-resizing':
                    isWindowResizing,
            }"
            @pointerdown="
                bringToFront
            "
        >
            <!-- ============================================================ -->
            <!-- BUBBLE                                                       -->
            <!-- ============================================================ -->

            <button
                type="button"
                class="
                    group
                    absolute
                    inset-x-0
                    top-0
                    flex
                    h-[34px]
                    w-full
                    touch-none
                    select-none
                    items-center
                    gap-2
                    overflow-hidden
                    rounded-[inherit]
                    border-0
                    bg-darkgrey
                    py-0
                    pl-1
                    pr-1
                    text-white
                    cursor-grab
                    transition-[opacity,transform]
                    duration-200
                    active:cursor-grabbing
                "
                :class="{
                    'pointer-events-none opacity-0':
                        isOpen,
                }"
                :aria-label="
                    `${handleLabel}: ${title}`
                "
                :title="
                    `${handleLabel}: ${title}`
                "
                @pointerdown="
                    startBubbleDrag
                "
                @pointermove="
                    moveBubble
                "
                @pointerup="
                    stopBubbleDrag
                "
                @pointercancel="
                    stopBubbleDrag
                "
                @lostpointercapture="
                    stopBubbleDrag
                "
                @click="
                    handleBubbleClick
                "
            >
                <span
                    class="
                        flex
                        h-7
                        w-7
                        shrink-0
                        items-center
                        justify-center
                    "
                >
                    <i
                        class="
                            bi
                            bi-file-earmark-text
                        "
                        aria-hidden="true"
                    />
                </span>

                <span
                    class="
                        min-w-0
                        flex-1
                        truncate
                        text-left
                        text-sm
                        font-medium
                    "
                >
                    {{ title }}
                </span>

                <i
                    class="
                        bi
                        bi-grip-vertical
                        shrink-0
                        opacity-40
                        transition-opacity
                        duration-150
                        group-hover:opacity-100
                    "
                    aria-hidden="true"
                />
            </button>

            <!-- ============================================================ -->
            <!-- OPEN DOCUMENT                                                -->
            <!-- ============================================================ -->

            <div
                class="
                    absolute
                    inset-0
                    flex
                    min-h-0
                    min-w-0
                    origin-top-right
                    flex-col
                    overflow-hidden
                    transition-[opacity,transform]
                    duration-300
                "
                :class="{
                    'pointer-events-none opacity-0':
                        !isOpen,

                    'opacity-100':
                        isOpen,
                }"
                role="dialog"
                aria-modal="false"
                :aria-label="
                    title
                "
            >
                <!-- HEADER -->

                <header
                    class="
                        group
                        relative
                        z-20
                        flex
                        h-[34px]
                        shrink-0
                        touch-none
                        select-none
                        items-center
                        gap-2
                        bg-darkgrey
                        pl-1
                        pr-1
                        text-white
                        cursor-move
                    "
                    @pointerdown="
                        startWindowInteraction(
                            $event,
                            'drag',
                        )
                    "
                    @pointermove="
                        moveWindow
                    "
                    @pointerup="
                        stopWindowInteraction
                    "
                    @pointercancel="
                        stopWindowInteraction
                    "
                    @lostpointercapture="
                        stopWindowInteraction
                    "
                >
                    <span
                        class="
                            flex
                            h-7
                            w-7
                            shrink-0
                            items-center
                            justify-center
                        "
                    >
                        <i
                            class="
                                bi
                                bi-file-earmark-text
                            "
                            aria-hidden="true"
                        />
                    </span>

                    <span
                        class="
                            min-w-0
                            flex-1
                            truncate
                            text-left
                            text-sm
                            font-medium
                        "
                    >
                        {{ title }}
                    </span>

                    <div
                        class="
                            flex
                            shrink-0
                            items-center
                            gap-0.5
                        "
                        @pointerdown.stop
                    >
                        <button
                            type="button"
                            class="
                                flex
                                h-7
                                w-7
                                shrink-0
                                items-center
                                justify-center
                                rounded-md
                                border-0
                                bg-transparent
                                text-white
                                transition
                                duration-150
                                hover:bg-white/10
                                active:scale-95
                            "
                            aria-label="
                                Minimalizovať dokument
                            "
                            title="
                                Minimalizovať dokument
                            "
                            :disabled="
                                isInitialIntro
                            "
                            @click="
                                minimizeDocument()
                            "
                        >
                            <i
                                class="
                                    bi
                                    bi-dash-lg
                                "
                                aria-hidden="true"
                            />
                        </button>
                    </div>

                    <i
                        class="
                            bi
                            bi-grip-vertical
                            shrink-0
                            opacity-40
                            transition-opacity
                            duration-150
                            group-hover:opacity-100
                        "
                        aria-hidden="true"
                    />
                </header>

                <!-- CONTENT -->

                <div
                    class="
                        relative
                        min-h-0
                        min-w-0
                        flex-1
                        overflow-hidden
                        bg-white
                    "
                >
                    <!-- SLOT CONTENT -->

                    <slot
                        v-if="
                            $slots.default &&
                            isOpen
                        "
                    />

                    <!-- LOADING -->

                    <div
                        v-else-if="
                            isLoading
                        "
                        class="
                            absolute
                            inset-0
                            z-10
                            flex
                            items-center
                            justify-center
                            bg-white/80
                        "
                    >
                        <i
                            class="
                                bi
                                bi-arrow-repeat
                                animate-spin
                                text-2xl
                                text-accent
                            "
                            aria-label="
                                Načítavam dokument
                            "
                        />
                    </div>

                    <!-- ERROR -->

                    <div
                        v-else-if="
                            previewError
                        "
                        class="
                            absolute
                            inset-0
                            flex
                            items-center
                            justify-center
                            p-6
                            text-center
                            text-normal
                            text-danger
                        "
                    >
                        {{
                            previewError
                        }}
                    </div>

                    <!-- IFRAME -->

                    <iframe
                        v-else-if="
                            resolvedPreviewUrl &&
                            isOpen
                        "
                        :src="
                            resolvedPreviewUrl
                        "
                        :title="
                            title
                        "
                        class="
                            h-full
                            w-full
                            border-0
                            bg-white
                        "
                        @load="
                            handlePreviewFrameLoaded
                        "
                    />
                </div>

                <!-- ======================================================== -->
                <!-- INVISIBLE CORNER RESIZE HANDLES                          -->
                <!-- ======================================================== -->

                <!-- Top-left -->

                <div
                    class="
                        absolute
                        left-0
                        top-0
                        z-50
                        h-4
                        w-4
                        touch-none
                        cursor-nwse-resize
                    "
                    @pointerdown.stop="
                        startWindowInteraction(
                            $event,
                            'resize-top-left',
                        )
                    "
                    @pointermove="
                        moveWindow
                    "
                    @pointerup="
                        stopWindowInteraction
                    "
                    @pointercancel="
                        stopWindowInteraction
                    "
                    @lostpointercapture="
                        stopWindowInteraction
                    "
                />

                <!-- Top-right -->

                <div
                    class="
                        absolute
                        right-0
                        top-0
                        z-50
                        h-4
                        w-4
                        touch-none
                        cursor-nesw-resize
                    "
                    @pointerdown.stop="
                        startWindowInteraction(
                            $event,
                            'resize-top-right',
                        )
                    "
                    @pointermove="
                        moveWindow
                    "
                    @pointerup="
                        stopWindowInteraction
                    "
                    @pointercancel="
                        stopWindowInteraction
                    "
                    @lostpointercapture="
                        stopWindowInteraction
                    "
                />

                <!-- Bottom-left -->

                <div
                    class="
                        absolute
                        bottom-0
                        left-0
                        z-50
                        h-4
                        w-4
                        touch-none
                        cursor-nesw-resize
                    "
                    @pointerdown.stop="
                        startWindowInteraction(
                            $event,
                            'resize-bottom-left',
                        )
                    "
                    @pointermove="
                        moveWindow
                    "
                    @pointerup="
                        stopWindowInteraction
                    "
                    @pointercancel="
                        stopWindowInteraction
                    "
                    @lostpointercapture="
                        stopWindowInteraction
                    "
                />

                <!-- Bottom-right -->

                <div
                    class="
                        absolute
                        bottom-0
                        right-0
                        z-50
                        h-4
                        w-4
                        touch-none
                        cursor-nwse-resize
                    "
                    @pointerdown.stop="
                        startWindowInteraction(
                            $event,
                            'resize-bottom-right',
                        )
                    "
                    @pointermove="
                        moveWindow
                    "
                    @pointerup="
                        stopWindowInteraction
                    "
                    @pointercancel="
                        stopWindowInteraction
                    "
                    @lostpointercapture="
                        stopWindowInteraction
                    "
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.reference-document-shell {
    transition:
        left 340ms cubic-bezier(0.22, 1, 0.36, 1),
        right 340ms cubic-bezier(0.22, 1, 0.36, 1),
        top 340ms cubic-bezier(0.22, 1, 0.36, 1),
        width 340ms cubic-bezier(0.22, 1, 0.36, 1),
        height 340ms cubic-bezier(0.22, 1, 0.36, 1),
        border-radius 340ms cubic-bezier(0.22, 1, 0.36, 1),
        box-shadow 340ms ease;
}

.reference-document-shell.is-bubble-dragging,
.reference-document-shell.is-bubble-flying,
.reference-document-shell.is-window-dragging,
.reference-document-shell.is-window-resizing {
    transition: none;
}

@media (prefers-reduced-motion: reduce) {
    .reference-document-shell,
    .reference-document-shell * {
        animation: none !important;
        transition-duration: 0.01ms !important;
    }
}
</style>