<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import {
    createCornerEditor,
    Scanner,
    type CornerEditor,
    type CornerPoints,
    type Point,
} from 'scanic'

const DETECTION_INTERVAL_MS = 100
const AUTO_CAPTURE_CONFIDENCE = 0.9
const AUTO_CAPTURE_STABLE_FRAMES = 2
const CAPTURE_MAX_DIMENSION = 3200

const emit = defineEmits<{
    captured: [file: File]
    close: []
}>()

const video = ref<HTMLVideoElement | null>(null)
const editorHost = ref<HTMLDivElement | null>(null)
const loading = ref(true)
const cameraReady = ref(false)
const scannerReady = ref(false)
const capturing = ref(false)
const reviewing = ref(false)
const error = ref('')
const status = ref('Pripravujem fotoaparát...')
const torchEnabled = ref(false)
const torchAvailable = ref(false)
const liveCorners = ref<CornerPoints | null>(null)
const editedCorners = ref<CornerPoints | null>(null)
const videoSize = ref({ width: 1, height: 1 })
const detectionConfidence = ref(0)

const scanner = new Scanner()

let scannerInitialization: Promise<void> | null = null
let cornerEditor: CornerEditor | null = null
let stream: MediaStream | null = null
let sourceCanvas: HTMLCanvasElement | null = null
let detectionTimer: number | null = null
let detectionRunning = false
let stableDetectionFrames = 0
let missedDetectionFrames = 0
let stopped = false
let cameraInitialized = false
let autoCaptureArmedAt = 0

const canCapture = computed(() => cameraReady.value && !capturing.value && !error.value)
const confidencePercent = computed(() => Math.round(detectionConfidence.value * 100))
const livePolygonPoints = computed(() => {
    const corners = liveCorners.value
    if (!corners) return ''

    return [
        corners.topLeft,
        corners.topRight,
        corners.bottomRight,
        corners.bottomLeft,
    ].map((point) => `${point.x},${point.y}`).join(' ')
})

function waitForVideoDimensions(videoElement: HTMLVideoElement) {
    return new Promise<void>((resolve, reject) => {
        const startedAt = Date.now()

        const check = () => {
            if (stopped) {
                reject(new Error('Skenovanie bolo zrušené.'))
                return
            }

            if (videoElement.videoWidth > 0 && videoElement.videoHeight > 0) {
                resolve()
                return
            }

            if (Date.now() - startedAt >= 10_000) {
                reject(new Error('Fotoaparát neposkytol obraz.'))
                return
            }

            window.setTimeout(check, 100)
        }

        check()
    })
}

async function initializeScanner() {
    try {
        await scanner.initialize()
        if (stopped) return

        scannerReady.value = true
        status.value = 'Namierte fotoaparát na dokument'
        scheduleDetection()
    } catch (reason) {
        console.error('Could not initialize Scanic', reason)
        status.value = 'Automatické rozpoznávanie nie je dostupné. Dokument môžete odfotiť.'
    }
}

async function startCamera() {
    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Tento prehliadač nepodporuje skenovanie fotoaparátom.')
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
            stopScanner()
            return
        }

        const videoElement = video.value
        if (!videoElement) return

        videoElement.srcObject = stream
        videoElement.muted = true
        videoElement.playsInline = true
        loading.value = false
        status.value = 'Spúšťam obraz z fotoaparátu...'
        await videoElement.play()
        await waitForVideoDimensions(videoElement)
        initializeCamera()
    } catch (reason) {
        console.error('Could not start document scanner', reason)
        error.value = reason instanceof Error
            ? reason.message
            : 'Fotoaparát sa nepodarilo spustiť.'
        status.value = ''
        loading.value = false
    }
}

function initializeCamera() {
    if (cameraInitialized || stopped) return

    const videoElement = video.value
    if (!videoElement || videoElement.videoWidth <= 0 || videoElement.videoHeight <= 0) {
        return
    }

    cameraInitialized = true
    const track = stream?.getVideoTracks()[0]
    const capabilities = track?.getCapabilities?.() as MediaTrackCapabilities & {
        torch?: boolean
    }
    torchAvailable.value = capabilities?.torch === true
    videoSize.value = {
        width: videoElement.videoWidth,
        height: videoElement.videoHeight,
    }
    cameraReady.value = true
    loading.value = false
    status.value = 'Pripravujem rozpoznávanie dokumentu...'
    autoCaptureArmedAt = performance.now() + 1_000
    scannerInitialization = initializeScanner()
}

function smoothPoint(previous: Point, next: Point): Point {
    const nextWeight = 0.45

    return {
        x: previous.x * (1 - nextWeight) + next.x * nextWeight,
        y: previous.y * (1 - nextWeight) + next.y * nextWeight,
    }
}

function smoothCorners(
    previous: CornerPoints | null,
    next: CornerPoints,
): CornerPoints {
    if (!previous) return next

    return {
        topLeft: smoothPoint(previous.topLeft, next.topLeft),
        topRight: smoothPoint(previous.topRight, next.topRight),
        bottomRight: smoothPoint(previous.bottomRight, next.bottomRight),
        bottomLeft: smoothPoint(previous.bottomLeft, next.bottomLeft),
    }
}

function pauseDetection() {
    if (detectionTimer !== null) {
        window.clearTimeout(detectionTimer)
        detectionTimer = null
    }
}

function scheduleDetection(delay = 0) {
    pauseDetection()
    if (!scannerReady.value || reviewing.value || capturing.value || stopped) return

    detectionTimer = window.setTimeout(runDetection, delay)
}

async function runDetection() {
    detectionTimer = null

    const videoElement = video.value
    if (
        !videoElement
        || reviewing.value
        || capturing.value
        || stopped
        || detectionRunning
        || document.hidden
        || videoElement.readyState < 2
    ) {
        scheduleDetection(DETECTION_INTERVAL_MS)
        return
    }

    detectionRunning = true
    const startedAt = performance.now()

    try {
        const result = await scanner.scan(videoElement, { mode: 'detect' })

        if (result.success && result.corners) {
            const confidence = Math.max(0, Math.min(1, result.confidence ?? 0))
            liveCorners.value = smoothCorners(liveCorners.value, result.corners)
            detectionConfidence.value = confidence
            missedDetectionFrames = 0
            status.value = `Dokument rozpoznaný na ${Math.round(confidence * 100)} %`

            if (
                confidence >= AUTO_CAPTURE_CONFIDENCE
                && performance.now() >= autoCaptureArmedAt
            ) {
                stableDetectionFrames++
                if (stableDetectionFrames >= AUTO_CAPTURE_STABLE_FRAMES) {
                    stableDetectionFrames = 0
                    await capture()
                }
            } else {
                stableDetectionFrames = 0
            }
        } else {
            stableDetectionFrames = 0
            missedDetectionFrames++
            detectionConfidence.value = 0

            if (missedDetectionFrames >= 3) {
                liveCorners.value = null
                status.value = 'Namierte fotoaparát na celý dokument'
            }
        }
    } catch (reason) {
        console.error('Scanic live detection failed', reason)
        stableDetectionFrames = 0
    } finally {
        detectionRunning = false
        const elapsed = performance.now() - startedAt
        scheduleDetection(Math.max(0, DETECTION_INTERVAL_MS - elapsed))
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

function cornersForCapture(
    canvas: HTMLCanvasElement,
    videoElement: HTMLVideoElement,
): CornerPoints {
    const detected = liveCorners.value
    if (!detected) return defaultCorners(canvas)

    const scaleX = canvas.width / videoElement.videoWidth
    const scaleY = canvas.height / videoElement.videoHeight
    const scale = (point: Point): Point => ({
        x: point.x * scaleX,
        y: point.y * scaleY,
    })

    return {
        topLeft: scale(detected.topLeft),
        topRight: scale(detected.topRight),
        bottomRight: scale(detected.bottomRight),
        bottomLeft: scale(detected.bottomLeft),
    }
}

function destroyCornerEditor() {
    cornerEditor?.destroy()
    cornerEditor = null
}

function mountCornerEditor() {
    const host = editorHost.value
    if (!host || !sourceCanvas || !editedCorners.value) return

    destroyCornerEditor()
    cornerEditor = createCornerEditor({
        container: host,
        image: sourceCanvas,
        corners: editedCorners.value,
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
            edgeWidth: 3,
            handleSize: 24,
            handleHit: 56,
            mask: 'rgba(0, 0, 0, 0.48)',
        },
        handleHitArea: 56,
        onChange: (value) => {
            editedCorners.value = value
            error.value = ''
        },
    })
}

async function capture() {
    if (!canCapture.value) return

    const videoElement = video.value
    if (!videoElement) return

    capturing.value = true
    pauseDetection()
    error.value = ''

    try {
        const canvas = document.createElement('canvas')
        const scale = Math.min(
            1,
            CAPTURE_MAX_DIMENSION / Math.max(
                videoElement.videoWidth,
                videoElement.videoHeight,
            ),
        )
        canvas.width = Math.max(1, Math.round(videoElement.videoWidth * scale))
        canvas.height = Math.max(1, Math.round(videoElement.videoHeight * scale))
        const context = canvas.getContext('2d')
        if (!context) throw new Error('Scan sa nepodarilo vytvoriť.')

        context.imageSmoothingEnabled = true
        context.imageSmoothingQuality = 'high'
        context.drawImage(videoElement, 0, 0, canvas.width, canvas.height)
        sourceCanvas = canvas
        editedCorners.value = cornersForCapture(canvas, videoElement)
        reviewing.value = true
        stream?.getVideoTracks().forEach((track) => {
            track.enabled = false
        })

        await nextTick()
        mountCornerEditor()
    } catch (reason) {
        console.error('Document capture failed', reason)
        error.value = reason instanceof Error
            ? reason.message
            : 'Scan sa nepodarilo vytvoriť.'
        reviewing.value = false
        sourceCanvas = null
        stream?.getVideoTracks().forEach((track) => {
            track.enabled = true
        })
        scheduleDetection()
    } finally {
        capturing.value = false
    }
}

function canvasToBlob(canvas: HTMLCanvasElement): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => blob
                ? resolve(blob)
                : reject(new Error('Scan sa nepodarilo vytvoriť.')),
            'image/jpeg',
            0.96,
        )
    })
}

async function confirmCapture() {
    if (!sourceCanvas || capturing.value) return

    const selectedCorners = cornerEditor?.getCorners() ?? editedCorners.value
    if (!selectedCorners) return

    capturing.value = true
    error.value = ''

    try {
        scannerInitialization ??= initializeScanner()
        await scannerInitialization

        const result = await scanner.extract(sourceCanvas, selectedCorners, {
            output: 'canvas',
        })
        if (
            !result.success
            || !(result.output instanceof HTMLCanvasElement)
        ) {
            throw new Error(result.message || 'Dokument sa nepodarilo narovnať.')
        }

        const blob = await canvasToBlob(result.output)
        const timestamp = Date.now()
        emit('captured', new File([blob], `scan-${timestamp}.jpg`, {
            type: 'image/jpeg',
            lastModified: timestamp,
        }))
        close()
    } catch (reason) {
        console.error('Document extraction failed', reason)
        error.value = reason instanceof Error
            ? reason.message
            : 'Scan sa nepodarilo vytvoriť.'
    } finally {
        capturing.value = false
    }
}

function retake() {
    destroyCornerEditor()
    reviewing.value = false
    sourceCanvas = null
    editedCorners.value = null
    liveCorners.value = null
    detectionConfidence.value = 0
    stableDetectionFrames = 0
    missedDetectionFrames = 0
    error.value = ''
    autoCaptureArmedAt = performance.now() + 1_000
    stream?.getVideoTracks().forEach((track) => {
        track.enabled = true
    })
    status.value = 'Namierte fotoaparát na dokument'
    scheduleDetection(100)
}

async function toggleTorch() {
    const track = stream?.getVideoTracks()[0]
    if (!track || !torchAvailable.value) return

    const enabled = !torchEnabled.value

    try {
        await track.applyConstraints({
            advanced: [{ torch: enabled } as MediaTrackConstraintSet],
        })
        torchEnabled.value = enabled
    } catch (reason) {
        console.error('Could not toggle camera torch', reason)
    }
}

function stopScanner() {
    stopped = true
    pauseDetection()
    destroyCornerEditor()
    sourceCanvas = null
    stream?.getTracks().forEach((track) => track.stop())
    stream = null
}

function close() {
    stopScanner()
    emit('close')
}

onMounted(startCamera)
onBeforeUnmount(stopScanner)
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[10000] flex h-[100dvh] w-screen flex-col bg-black">
            <div class="relative min-h-0 flex-1 overflow-hidden">
                <video
                    v-show="!reviewing"
                    ref="video"
                    autoplay
                    muted
                    playsinline
                    webkit-playsinline
                    class="absolute inset-0 block h-full w-full object-cover"
                    @loadedmetadata="initializeCamera"
                    @loadeddata="initializeCamera"
                    @canplay="initializeCamera"
                    @playing="initializeCamera"
                />

                <svg
                    v-if="!reviewing && liveCorners"
                    class="pointer-events-none absolute inset-0 h-full w-full"
                    :viewBox="`0 0 ${videoSize.width} ${videoSize.height}`"
                    preserveAspectRatio="xMidYMid slice"
                    aria-hidden="true"
                >
                    <polygon
                        :points="livePolygonPoints"
                        fill="rgb(34 197 94 / 0.1)"
                        stroke="#22c55e"
                        stroke-width="5"
                        vector-effect="non-scaling-stroke"
                    />
                </svg>

                <div
                    v-if="reviewing"
                    ref="editorHost"
                    class="absolute inset-0 touch-none bg-black"
                />

                <div
                    v-if="!reviewing"
                    class="absolute inset-x-0 top-0 flex items-center justify-between p-4"
                    :style="{
                        paddingTop: 'max(1rem, env(safe-area-inset-top))',
                    }"
                >
                    <button
                        type="button"
                        class="rounded-md bg-black/55 px-3 py-2 text-normal text-white backdrop-blur-sm"
                        @click="close"
                    >
                        Zrušiť
                    </button>

                    <button
                        v-if="torchAvailable"
                        type="button"
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-black/55 text-white backdrop-blur-sm"
                        :aria-label="torchEnabled ? 'Vypnúť blesk' : 'Zapnúť blesk'"
                        @click="toggleTorch"
                    >
                        <i :class="torchEnabled ? 'bi bi-lightning-fill' : 'bi bi-lightning'" />
                    </button>
                </div>

                <div
                    v-else
                    class="pointer-events-none absolute inset-x-0 top-0 z-10 flex items-center justify-center bg-gradient-to-b from-black/80 to-transparent p-5 text-center text-normal font-semibold text-white"
                    :style="{
                        paddingTop: 'max(1.25rem, env(safe-area-inset-top))',
                    }"
                >
                    Potiahnite zelené rohy na okraje dokumentu
                </div>

                <div
                    v-if="error && !reviewing"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-black/80 px-8 text-center text-white"
                >
                    <i class="bi bi-camera-video-off text-3xl" />
                    <p class="text-normal">{{ error }}</p>
                    <button
                        type="button"
                        class="rounded-md bg-white px-4 py-2 text-normal text-darkgrey"
                        @click="close"
                    >
                        Zavrieť
                    </button>
                </div>

                <div
                    v-else-if="!reviewing"
                    class="absolute inset-x-4 bottom-5 flex flex-col items-center gap-2 text-center text-normal text-white drop-shadow-md"
                >
                    <div class="flex items-center justify-center gap-2">
                        <i
                            v-if="loading || !cameraReady || !scannerReady"
                            class="bi bi-arrow-repeat animate-spin"
                        />
                        <span>{{ status }}</span>
                    </div>
                    <div
                        v-if="scannerReady"
                        class="h-1.5 w-full max-w-64 overflow-hidden rounded-full bg-white/25"
                    >
                        <div
                            class="h-full rounded-full transition-[width,background-color] duration-150"
                            :class="
                                detectionConfidence >= 0.9
                                    ? 'bg-success'
                                    : 'bg-white'
                            "
                            :style="{ width: `${confidencePercent}%` }"
                        />
                    </div>
                    <span
                        v-if="scannerReady"
                        class="text-mini text-white/80"
                    >
                        Automatické odfotenie pri 90 %
                    </span>
                </div>

                <div
                    v-if="reviewing && error"
                    class="absolute inset-x-4 bottom-4 z-20 rounded-md bg-danger px-3 py-2 text-center text-mini text-white shadow-lg"
                >
                    {{ error }}
                </div>
            </div>

            <div
                v-if="!reviewing"
                class="flex min-h-28 shrink-0 items-center justify-center bg-black px-4 pt-4"
                :style="{
                    paddingBottom: 'max(1rem, env(safe-area-inset-bottom))',
                }"
            >
                <button
                    type="button"
                    class="flex h-18 w-18 items-center justify-center rounded-full border-4 border-white disabled:opacity-50"
                    :disabled="!canCapture"
                    aria-label="Odfotiť dokument"
                    @click="capture"
                >
                    <span class="h-14 w-14 rounded-full bg-white" />
                </button>
            </div>

            <div
                v-else
                class="grid min-h-24 shrink-0 grid-cols-2 gap-3 bg-black px-4 pt-4"
                :style="{
                    paddingBottom: 'max(1rem, env(safe-area-inset-bottom))',
                }"
            >
                <button
                    type="button"
                    class="rounded-md border border-white/40 bg-white/10 px-4 text-normal font-semibold text-white disabled:opacity-50"
                    :disabled="capturing"
                    @click="retake"
                >
                    Odfotiť znova
                </button>
                <button
                    type="button"
                    class="rounded-md bg-white px-4 text-normal font-semibold text-darkgrey disabled:opacity-50"
                    :disabled="capturing"
                    @click="confirmCapture"
                >
                    <i
                        v-if="capturing"
                        class="bi bi-arrow-repeat mr-2 animate-spin"
                    />
                    {{ capturing ? 'Spracúvam' : 'Hotovo' }}
                </button>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
:deep(.scanic-corner-editor),
:deep(.scanic-corner-editor canvas) {
    width: 100%;
    height: 100%;
}
</style>
