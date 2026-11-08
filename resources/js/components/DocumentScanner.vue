<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
    createCornerEditor,
    Scanner,
    type CornerEditor,
    type CornerPoints,
    type Point,
} from 'scanic'

const props = withDefaults(defineProps<{
    paused?: boolean
    galleryFile?: File | null
    galleryDisabled?: boolean
}>(), {
    paused: false,
    galleryFile: null,
    galleryDisabled: false,
})

const emit = defineEmits<{
    captured: [file: File]
    galleryRequested: []
    galleryFinished: []
    galleryError: [message: string]
    reviewingChange: [reviewing: boolean]
}>()

const DETECTION_INTERVAL_MS = 300
const MIN_CONFIDENCE = 0.80
const STABLE_FRAMES = 2
const FRAMES_WITHOUT_PAPER_TO_REARM = 6
const CAPTURE_MAX_DIMENSION = 3200

const video = ref<HTMLVideoElement | null>(null)
const editorHost = ref<HTMLDivElement | null>(null)
const loading = ref(true)
const cameraReady = ref(false)
const scannerReady = ref(false)
const capturing = ref(false)
const reviewing = ref(false)
const savedAnimation = ref(false)
const error = ref('')
const status = ref('Zapínam fotoaparát…')
const torchAvailable = ref(false)
const torchEnabled = ref(false)
const liveCorners = ref<CornerPoints | null>(null)
const editedCorners = ref<CornerPoints | null>(null)
const reviewSource = ref<'camera' | 'gallery' | null>(null)
const videoSize = ref({ width: 1, height: 1 })

const scanner = new Scanner()
let stream: MediaStream | null = null
let editor: CornerEditor | null = null
let sourceCanvas: HTMLCanvasElement | null = null
let scannerInitialization: Promise<void> | null = null
let galleryLoading = false
let detectionTimer: number | null = null
let animationTimer: number | null = null
let detectionRunning = false
let cameraInitialized = false
let stopped = false
let stableFrames = 0
let missingFrames = 0
let candidateCorners: CornerPoints | null = null
let waitingForNewPage = false
let captureArmedAt = 0

const canTakePhoto = computed(() =>
    cameraReady.value && !props.paused && !capturing.value && !reviewing.value && !savedAnimation.value,
)
const canOpenGallery = computed(() =>
    !props.galleryDisabled && !props.paused && !capturing.value && !reviewing.value && !savedAnimation.value,
)

const polygonPoints = computed(() => {
    const corners = liveCorners.value
    if (!corners) return ''

    return [
        corners.topLeft,
        corners.topRight,
        corners.bottomRight,
        corners.bottomLeft,
    ].map((point) => `${point.x},${point.y}`).join(' ')
})

function stopDetection() {
    if (detectionTimer !== null) {
        window.clearTimeout(detectionTimer)
        detectionTimer = null
    }
}

function scheduleDetection(delay = DETECTION_INTERVAL_MS) {
    stopDetection()
    if (
        stopped || props.paused || !scannerReady.value ||
        reviewing.value || capturing.value || savedAnimation.value
    ) {
        return
    }

    detectionTimer = window.setTimeout(() => {
        void detectPage()
    }, delay)
}

function areaOf(corners: CornerPoints): number {
    const points = [
        corners.topLeft,
        corners.topRight,
        corners.bottomRight,
        corners.bottomLeft,
    ]

    return Math.abs(points.reduce((area, point, index) => {
        const next = points[(index + 1) % points.length]
        return area + point.x * next.y - next.x * point.y
    }, 0)) / 2
}

function cornersAreSteady(previous: CornerPoints, current: CornerPoints): boolean {
    const diagonal = Math.hypot(videoSize.value.width, videoSize.value.height)
    const maxMovement = diagonal * 0.018
    const keys = ['topLeft', 'topRight', 'bottomRight', 'bottomLeft'] as const

    return keys.every((key) =>
        Math.hypot(
            previous[key].x - current[key].x,
            previous[key].y - current[key].y,
        ) <= maxMovement,
    )
}

function onPaperMissing() {
    stableFrames = 0
    candidateCorners = null
    missingFrames++

    if (missingFrames >= 3) {
        liveCorners.value = null
    }

    // A scan is not armed again while the same sheet is still in the viewfinder.
    if (waitingForNewPage && missingFrames >= FRAMES_WITHOUT_PAPER_TO_REARM) {
        waitingForNewPage = false
        captureArmedAt = performance.now() + 350
    }

    status.value = waitingForNewPage
        ? 'Ukážte ďalšiu stranu'
        : 'Ukážte celý papier vo fotoaparáte'
}

async function detectPage() {
    detectionTimer = null
    const element = video.value

    if (
        stopped || props.paused || reviewing.value || capturing.value ||
        savedAnimation.value || !element || document.hidden ||
        element.readyState < HTMLMediaElement.HAVE_CURRENT_DATA
    ) {
        scheduleDetection(250)
        return
    }

    if (detectionRunning) return
    detectionRunning = true

    try {
        const result = await scanner.scan(element, { mode: 'detect' })
        if (stopped || props.paused || reviewing.value) return

        if (!result.success || !result.corners) {
            onPaperMissing()
            return
        }

        const confidence = Math.max(0, Math.min(1, result.confidence ?? 0))
        const corners = result.corners
        const coverage = areaOf(corners) / (element.videoWidth * element.videoHeight)
        const isGood = confidence >= MIN_CONFIDENCE && coverage >= 0.15
        missingFrames = 0
        liveCorners.value = corners

        if (waitingForNewPage) {
            status.value = 'Strana je pridaná. Odložte ju a ukážte ďalšiu.'
            return
        }

        if (!isGood) {
            stableFrames = 0
            candidateCorners = null
            status.value = 'Podržte papier celý v zábere'
            return
        }

        status.value = 'Výborne, chvíľu podržte papier…'
        stableFrames = candidateCorners && cornersAreSteady(candidateCorners, corners)
            ? stableFrames + 1
            : 1
        candidateCorners = corners

        if (stableFrames >= STABLE_FRAMES && performance.now() >= captureArmedAt) {
            stableFrames = 0
            candidateCorners = null
            await captureAutomatically(corners)
        }
    } catch (reason) {
        console.error('Page detection failed', reason)
        onPaperMissing()
    } finally {
        detectionRunning = false
        scheduleDetection()
    }
}

function scaledCorners(
    corners: CornerPoints,
    canvas: HTMLCanvasElement,
    element: HTMLVideoElement,
): CornerPoints {
    const scaleX = canvas.width / element.videoWidth
    const scaleY = canvas.height / element.videoHeight
    const scale = (point: Point): Point => ({
        x: point.x * scaleX,
        y: point.y * scaleY,
    })

    return {
        topLeft: scale(corners.topLeft),
        topRight: scale(corners.topRight),
        bottomRight: scale(corners.bottomRight),
        bottomLeft: scale(corners.bottomLeft),
    }
}

function defaultCorners(canvas: HTMLCanvasElement): CornerPoints {
    const insetX = canvas.width * 0.07
    const insetY = canvas.height * 0.07

    return {
        topLeft: { x: insetX, y: insetY },
        topRight: { x: canvas.width - insetX, y: insetY },
        bottomRight: { x: canvas.width - insetX, y: canvas.height - insetY },
        bottomLeft: { x: insetX, y: canvas.height - insetY },
    }
}

function videoFrame(): HTMLCanvasElement {
    const element = video.value
    if (!element || !element.videoWidth || !element.videoHeight) {
        throw new Error('Fotoaparát ešte nie je pripravený.')
    }

    const scale = Math.min(
        1,
        CAPTURE_MAX_DIMENSION / Math.max(element.videoWidth, element.videoHeight),
    )
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(element.videoWidth * scale))
    canvas.height = Math.max(1, Math.round(element.videoHeight * scale))
    const context = canvas.getContext('2d')
    if (!context) throw new Error('Fotku sa nepodarilo vytvoriť.')

    context.imageSmoothingEnabled = true
    context.imageSmoothingQuality = 'high'
    context.drawImage(element, 0, 0, canvas.width, canvas.height)
    return canvas
}

function loadGalleryCanvas(file: File): Promise<HTMLCanvasElement> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file)
        const image = new Image()
        image.onload = () => {
            URL.revokeObjectURL(url)
            const width = image.naturalWidth
            const height = image.naturalHeight
            if (width <= 0 || height <= 0) {
                reject(new Error('Fotka sa nedá otvoriť.'))
                return
            }

            const scale = Math.min(1, CAPTURE_MAX_DIMENSION / Math.max(width, height))
            const canvas = document.createElement('canvas')
            canvas.width = Math.max(1, Math.round(width * scale))
            canvas.height = Math.max(1, Math.round(height * scale))
            const context = canvas.getContext('2d')
            if (!context) {
                reject(new Error('Fotku sa nedá otvoriť.'))
                return
            }
            context.imageSmoothingEnabled = true
            context.imageSmoothingQuality = 'high'
            context.drawImage(image, 0, 0, canvas.width, canvas.height)
            resolve(canvas)
        }
        image.onerror = () => {
            URL.revokeObjectURL(url)
            reject(new Error('Túto fotku sa nepodarilo otvoriť.'))
        }
        image.src = url
    })
}

function toBlob(canvas: HTMLCanvasElement): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => blob ? resolve(blob) : reject(new Error('Fotku sa nepodarilo uložiť.')),
            'image/jpeg',
            0.95,
        )
    })
}

function destroyEditor() {
    editor?.destroy()
    editor = null
}

function enableVideo() {
    stream?.getVideoTracks().forEach((track) => {
        track.enabled = true
    })
}

function resetReview() {
    destroyEditor()
    sourceCanvas = null
    editedCorners.value = null
    reviewing.value = false
    reviewSource.value = null
    liveCorners.value = null
    stableFrames = 0
    candidateCorners = null
    missingFrames = 0
    error.value = ''
    enableVideo()
    emit('reviewingChange', false)
    captureArmedAt = performance.now() + 1000
    scheduleDetection(150)
}

async function beginReview(
    canvas: HTMLCanvasElement,
    corners: CornerPoints,
    source: 'camera' | 'gallery',
) {
    stopDetection()
    sourceCanvas = canvas
    editedCorners.value = corners
    reviewSource.value = source
    reviewing.value = true
    error.value = ''
    stream?.getVideoTracks().forEach((track) => {
        track.enabled = false
    })
    emit('reviewingChange', true)

    await nextTick()
    if (stopped || !editorHost.value) return

    destroyEditor()
    editor = createCornerEditor({
        container: editorHost.value,
        image: canvas,
        corners,
        toolbar: { enabled: false },
        magnifier: {
            enabled: true,
            size: 96,
            zoom: 2,
            borderColor: '#22c55e',
            crosshairColor: '#22c55e',
        },
        theme: {
            accent: '#22c55e',
            edgeColor: '#22c55e',
            edgeWidth: 2,
            handleSize: 18,
            handleHit: 54,
            mask: 'rgba(0, 0, 0, 0.20)',
        },
        handleHitArea: 54,
        onChange: (value) => {
            editedCorners.value = value
            error.value = ''
        },
    })
}

async function captureAutomatically(corners: CornerPoints) {
    if (!canTakePhoto.value || waitingForNewPage) return
    capturing.value = true
    stopDetection()
    error.value = ''

    try {
        const canvas = videoFrame()
        const element = video.value
        if (!element) return
        await beginReview(canvas, scaledCorners(corners, canvas, element), 'camera')
    } catch (reason) {
        console.error('Automatic capture failed', reason)
        resetReview()
        status.value = 'Skúste papier vyrovnať alebo ho odfoťte tlačidlom.'
        captureArmedAt = performance.now() + 1800
    } finally {
        capturing.value = false
        scheduleDetection(350)
    }
}

async function captureManually() {
    if (!canTakePhoto.value) return
    capturing.value = true
    stopDetection()
    error.value = ''

    try {
        const canvas = videoFrame()
        const element = video.value
        const corners = element && liveCorners.value
            ? scaledCorners(liveCorners.value, canvas, element)
            : defaultCorners(canvas)
        await beginReview(canvas, corners, 'camera')
    } catch (reason) {
        console.error('Manual capture failed', reason)
        resetReview()
        error.value = reason instanceof Error ? reason.message : 'Skúste to znova.'
    } finally {
        capturing.value = false
    }
}

async function reviewGalleryFile(file: File) {
    if (stopped || galleryLoading) return
    galleryLoading = true
    stopDetection()
    error.value = ''

    try {
        const canvas = await loadGalleryCanvas(file)
        await initializeScanner()
        if (stopped || props.galleryFile !== file) return
        const detection = await scanner.scan(canvas, { mode: 'detect' })
        if (stopped || props.galleryFile !== file) return
        const corners = detection.success && detection.corners
            ? detection.corners
            : defaultCorners(canvas)
        await beginReview(canvas, corners, 'gallery')
    } catch (reason) {
        console.error('Could not open gallery image', reason)
        if (!stopped && props.galleryFile === file) {
            emit('galleryError', 'Jednu fotku sa nepodarilo otvoriť. Skúste inú.')
            resetReview()
            emit('galleryFinished')
        }
    } finally {
        galleryLoading = false
    }
}

function cancelReview() {
    if (capturing.value) return
    const wasGallery = reviewSource.value === 'gallery'
    resetReview()
    if (wasGallery) {
        emit('galleryFinished')
    }
}

async function confirmCapture() {
    if (capturing.value || !sourceCanvas) return
    const corners = editor?.getCorners() ?? editedCorners.value
    if (!corners) return

    capturing.value = true
    error.value = ''
    const wasGallery = reviewSource.value === 'gallery'
    try {
        await initializeScanner()
        const result = await scanner.extract(sourceCanvas, corners, { output: 'canvas' })
        if (!result.success || !(result.output instanceof HTMLCanvasElement)) {
            throw new Error(result.message || 'Fotku sa nepodarilo upraviť. Posuňte rohy a skúste znova.')
        }

        const blob = await toBlob(result.output)
        if (stopped) return
        const stamp = Date.now()
        const file = new File([blob], `strana-${stamp}.jpg`, {
            type: 'image/jpeg',
            lastModified: stamp,
        })
        if (!wasGallery) {
            waitingForNewPage = true
        }
        emit('captured', file)
        resetReview()

        savedAnimation.value = true
        if (animationTimer !== null) window.clearTimeout(animationTimer)
        animationTimer = window.setTimeout(() => {
            savedAnimation.value = false
            captureArmedAt = performance.now() + 450
            scheduleDetection(150)
        }, 700)

        if (wasGallery) {
            emit('galleryFinished')
        }
    } catch (reason) {
        console.error('Could not finish photo', reason)
        error.value = 'Fotku sa nepodarilo orezať. Skontrolujte rohy a skúste znova.'
    } finally {
        capturing.value = false
    }
}

async function toggleTorch() {
    const track = stream?.getVideoTracks()[0]
    if (!track || !torchAvailable.value) return

    try {
        const enabled = !torchEnabled.value
        await track.applyConstraints({
            advanced: [{ torch: enabled } as MediaTrackConstraintSet],
        })
        torchEnabled.value = enabled
    } catch (reason) {
        console.error('Could not toggle torch', reason)
    }
}

function initializeScanner(): Promise<void> {
    if (!scannerInitialization) {
        scannerInitialization = scanner.initialize().then(() => {
            if (stopped) return
            scannerReady.value = true
            status.value = 'Ukážte celý papier vo fotoaparáte'
            scheduleDetection(200)
        }).catch((reason) => {
            scannerInitialization = null
            console.error('Could not initialize Scanic', reason)
            status.value = 'Môžete použiť tlačidlo Odfotiť.'
            throw reason
        })
    }
    return scannerInitialization
}

function syncVideoSize() {
    const element = video.value
    if (!element || !element.videoWidth || !element.videoHeight) return

    if (
        videoSize.value.width !== element.videoWidth ||
        videoSize.value.height !== element.videoHeight
    ) {
        videoSize.value = { width: element.videoWidth, height: element.videoHeight }
        stableFrames = 0
        candidateCorners = null
        liveCorners.value = null
    }
}

function initializeCamera() {
    syncVideoSize()
    if (cameraInitialized || stopped) return
    const element = video.value
    if (!element || !element.videoWidth || !element.videoHeight) return

    cameraInitialized = true
    cameraReady.value = true
    loading.value = false
    const track = stream?.getVideoTracks()[0]
    const capabilities = track?.getCapabilities?.() as (MediaTrackCapabilities & {
        torch?: boolean
    }) | undefined
    torchAvailable.value = capabilities?.torch === true
    status.value = 'Pripravujem fotoaparát…'
    captureArmedAt = performance.now() + 1200
    void initializeScanner().catch(() => {
        // The camera can still be used manually if automatic detection fails.
    })
}

async function startCamera() {
    error.value = ''
    loading.value = true
    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Fotoaparát nie je dostupný. Môžete vybrať fotku z galérie.')
        }

        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 3840 },
                height: { ideal: 2160 },
            },
        })
        if (stopped) {
            stream.getTracks().forEach((track) => track.stop())
            stream = null
            return
        }

        const element = video.value
        if (!element) return
        element.srcObject = stream
        element.muted = true
        element.playsInline = true
        await element.play()
        initializeCamera()
    } catch (reason) {
        console.error('Could not start camera', reason)
        error.value = 'Fotoaparát sa nedá zapnúť. Skúste to znova alebo vyberte fotky.'
        loading.value = false
    }
}

async function retryCamera() {
    error.value = ''
    if (stream && video.value) {
        try {
            await video.value.play()
            initializeCamera()
            return
        } catch {
            stream.getTracks().forEach((track) => track.stop())
            stream = null
        }
    }
    await startCamera()
}

function stopScanner() {
    stopped = true
    stopDetection()
    destroyEditor()
    if (animationTimer !== null) window.clearTimeout(animationTimer)
    stream?.getTracks().forEach((track) => track.stop())
    stream = null
    sourceCanvas = null
}

watch(() => props.paused, (paused) => {
    if (paused) {
        stopDetection()
        stableFrames = 0
        candidateCorners = null
    } else {
        captureArmedAt = performance.now() + 450
        scheduleDetection(150)
    }
})

watch(() => props.galleryFile, (file) => {
    if (file && !stopped) {
        void reviewGalleryFile(file)
    }
}, { immediate: true })

onMounted(() => {
    void startCamera()
})
onBeforeUnmount(stopScanner)
</script>

<template>
    <div class=" overflow-hidden rounded-md bg-black text-white">
        <div
            id="scan-preview-anchor"
            class="relative mx-auto aspect-[9/16] w-full max-w-[43.875dvh] overflow-hidden bg-black"
        >
            <video
                ref="video"
                autoplay
                muted
                playsinline
                webkit-playsinline
                class="absolute inset-0 h-full w-full object-cover"
                @loadedmetadata="initializeCamera"
                @resize="syncVideoSize"
                @loadeddata="initializeCamera"
                @playing="initializeCamera"
            />

            <svg
                v-if="!reviewing && liveCorners"
                class="pointer-events-none absolute inset-0 h-full w-full"
                :viewBox="`0 0 ${videoSize.width} ${videoSize.height}`"
                preserveAspectRatio="xMidYMid meet"
                aria-hidden="true"
            >
                <polygon
                    :points="polygonPoints"
                    fill="none"
                    stroke="#22c55e"
                    stroke-width="2"
                    vector-effect="non-scaling-stroke"
                />
            </svg>

            <div v-if="reviewing" ref="editorHost" class="absolute inset-0 z-10 touch-none bg-black" />

            <div
                v-if="loading"
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/60 text-center"
            >
                <i class="bi bi-arrow-repeat animate-spin text-2xl" aria-hidden="true" />
                <p class="text-normal">Zapínam fotoaparát…</p>
            </div>

            <div
                v-if="error && !reviewing"
                class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-black/80 px-5 text-center"
                role="alert"
            >
                <i class="bi bi-camera-video-off text-3xl" aria-hidden="true" />
                <p class="max-w-sm text-normal">{{ error }}</p>
                <button
                    type="button"
                    class="mt-2 min-h-11 rounded-md bg-white px-4 text-normal text-darkgrey"
                    @click="retryCamera"
                >
                    Skúsiť znova
                </button>
            </div>

            <Transition name="scan-success">
                <div
                    v-if="savedAnimation"
                    class="pointer-events-none absolute inset-0 z-30 flex items-center justify-center bg-black/25"
                    role="status"
                >
                    <div class="flex items-center gap-3 rounded-md bg-white px-5 py-3 text-darkgrey shadow-xl">
                        <i class="bi bi-check-circle-fill text-2xl text-accent" aria-hidden="true" />
                        <span class="text-normal font-semibold">Strana pridaná</span>
                    </div>
                </div>
            </Transition>

            <button
                v-if="!reviewing && !error && !loading"
                type="button"
                class="
                    absolute
                    bottom-4
                    left-1/2
                    z-20
                    flex
                    size-16
                    -translate-x-1/2
                    items-center
                    justify-center
                    rounded-full
                    border-4
                    border-white
                    bg-black/30
                    shadow-xl
                    transition-transform
                    active:scale-95
                    disabled:opacity-50
                "
                :disabled="!canTakePhoto"
                aria-label="Odfotiť dokument"
                title="Odfotiť"
                @click="captureManually"
            >
                <span class="size-12 rounded-full bg-white" aria-hidden="true" />
            </button>

            <button
                v-if="!reviewing && !error && !loading"
                type="button"
                class="
                    absolute
                    bottom-[1.625rem]
                    right-4
                    z-20
                    flex
                    h-11
                    items-center
                    justify-center
                    gap-2
                    px-3
                    text-2xl
                    text-white
                    disabled:opacity-50
                "
                :disabled="!canOpenGallery"
                @click="emit('galleryRequested')"
            >
                <i class="bi bi-images" aria-hidden="true" />
            </button>

        </div>

        <div
            v-if="!reviewing && !error"
            class="flex min-h-16 items-center justify-between gap-3 bg-black px-3 py-3"
        >
            <p class="min-w-0 flex-1 text-mini text-white/90" role="status" aria-live="polite">
                {{ status }}
            </p>
            <div class="flex shrink-0 items-center gap-2">
                <button
                    v-if="torchAvailable"
                    type="button"
                    class="flex h-11 w-11 items-center justify-center rounded-md bg-white/15 text-white"
                    :aria-label="torchEnabled ? 'Vypnúť svetlo' : 'Zapnúť svetlo'"
                    @click="toggleTorch"
                >
                    <i :class="torchEnabled ? 'bi bi-lightning-fill' : 'bi bi-lightning'" aria-hidden="true" />
                </button>
            </div>
        </div>

        <div v-if="reviewing" class="flex flex-col gap-2 bg-black px-3 py-3">
            <p class="text-center text-mini text-white/90">
                Posuňte rohy na okraje papiera a pridajte stranu.
            </p>
            <p v-if="error" class="rounded-md bg-danger px-3 py-2 text-mini text-white" role="alert">{{ error }}</p>
            <div class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    class="min-h-12 rounded-md border border-white/40 bg-black/70 px-4 text-normal text-white disabled:opacity-50"
                    :disabled="capturing"
                    @click="cancelReview"
                >
                    {{ reviewSource === 'gallery' ? 'Preskočiť fotku' : 'Odfotiť znova' }}
                </button>
                <button
                    type="button"
                    class="min-h-12 rounded-md bg-white px-4 text-normal font-semibold text-darkgrey disabled:opacity-50"
                    :disabled="capturing"
                    @click="confirmCapture"
                >
                    {{ capturing ? 'Ukladám…' : 'Pridať stranu' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.scan-success-enter-active,
.scan-success-leave-active {
    transition: opacity 240ms ease;
}

.scan-success-enter-from,
.scan-success-leave-to {
    opacity: 0;
}

.scan-success-enter-active > div {
    animation: scan-confirm 420ms cubic-bezier(0.2, 0.9, 0.2, 1) both;
}

@keyframes scan-confirm {
    from { transform: translateY(14px) scale(0.9); }
    to { transform: translateY(0) scale(1); }
}

:deep(.scanic-corner-editor),
:deep(.scanic-corner-editor canvas) {
    width: 100%;
    height: 100%;
}

@media (prefers-reduced-motion: reduce) {
    .scan-success-enter-active,
    .scan-success-leave-active {
        transition: none;
    }

    .scan-success-enter-active > div {
        animation: none;
    }
}
</style>
