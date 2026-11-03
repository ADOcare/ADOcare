<script setup lang="ts">
import cvModule from '@techstark/opencv-js'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

type Point = {
    x: number
    y: number
}

type Corners = {
    topLeft: Point
    topRight: Point
    bottomLeft: Point
    bottomRight: Point
}

const emit = defineEmits<{
    captured: [file: File]
    close: []
}>()

const video = ref<HTMLVideoElement | null>(null)
const preview = ref<HTMLCanvasElement | null>(null)
const detection = ref<HTMLCanvasElement | null>(null)
const loading = ref(true)
const capturing = ref(false)
const error = ref('')
const status = ref('Pripravujem skener...')
const torchEnabled = ref(false)
const torchAvailable = ref(false)

let cv: Awaited<typeof cvModule> | null = null
let stream: MediaStream | null = null
let animationFrame: number | null = null
let detectionTimer: number | null = null
let detectedCorners: Corners | null = null
let stopped = false

const canCapture = computed(() => !loading.value && !capturing.value && !error.value)

async function loadOpenCv() {
    const module = await cvModule

    if (typeof module.Mat === 'function') {
        return module
    }

    await new Promise<void>((resolve, reject) => {
        const timeout = window.setTimeout(
            () => reject(new Error('OpenCV sa nepodarilo načítať.')),
            20_000,
        )

        module.onRuntimeInitialized = () => {
            window.clearTimeout(timeout)
            resolve()
        }
    })

    return module
}

async function startCamera() {
    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Tento prehliadač nepodporuje skenovanie fotoaparátom.')
        }

        cv = await loadOpenCv()
        if (stopped) return

        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 2560 },
                height: { ideal: 1440 },
            },
        })

        if (stopped) {
            stopCamera()
            return
        }

        const videoElement = video.value
        if (!videoElement) return

        videoElement.srcObject = stream
        await new Promise<void>((resolve) => {
            videoElement.onloadedmetadata = () => resolve()
        })
        await videoElement.play()
        setupCanvases()

        const track = stream.getVideoTracks()[0]
        const capabilities = track?.getCapabilities?.() as MediaTrackCapabilities & {
            torch?: boolean
        }
        torchAvailable.value = capabilities?.torch === true
        loading.value = false
        status.value = 'Namierte fotoaparát na dokument'
        drawPreview()
        detectionTimer = window.setInterval(detectDocument, 250)
    } catch (reason) {
        console.error('Could not start document scanner', reason)
        error.value = reason instanceof Error
            ? reason.message
            : 'Fotoaparát sa nepodarilo spustiť.'
        status.value = ''
        loading.value = false
    }
}

function setupCanvases() {
    const videoElement = video.value
    const previewCanvas = preview.value
    const detectionCanvas = detection.value
    if (!videoElement || !previewCanvas || !detectionCanvas) return

    const scale = Math.min(1, 900 / videoElement.videoWidth)
    const width = Math.max(1, Math.round(videoElement.videoWidth * scale))
    const height = Math.max(1, Math.round(videoElement.videoHeight * scale))

    previewCanvas.width = width
    previewCanvas.height = height
    detectionCanvas.width = width
    detectionCanvas.height = height
}

function drawPreview() {
    const videoElement = video.value
    const canvas = preview.value
    const context = canvas?.getContext('2d')
    if (!videoElement || !canvas || !context || stopped) return

    context.drawImage(videoElement, 0, 0, canvas.width, canvas.height)

    if (detectedCorners) {
        context.save()
        context.strokeStyle = '#22c55e'
        context.lineWidth = 4
        context.shadowColor = 'rgba(0, 0, 0, 0.6)'
        context.shadowBlur = 4
        context.beginPath()
        context.moveTo(detectedCorners.topLeft.x, detectedCorners.topLeft.y)
        context.lineTo(detectedCorners.topRight.x, detectedCorners.topRight.y)
        context.lineTo(detectedCorners.bottomRight.x, detectedCorners.bottomRight.y)
        context.lineTo(detectedCorners.bottomLeft.x, detectedCorners.bottomLeft.y)
        context.closePath()
        context.stroke()
        context.restore()
    }

    animationFrame = window.requestAnimationFrame(drawPreview)
}

function cornersForContour(contour: InstanceType<NonNullable<typeof cv>['Mat']>): Corners | null {
    if (!cv) return null

    const center = cv.minAreaRect(contour).center
    const corners: Partial<Corners> = {}
    const distances: Record<keyof Corners, number> = {
        topLeft: 0,
        topRight: 0,
        bottomLeft: 0,
        bottomRight: 0,
    }

    for (let index = 0; index < contour.data32S.length; index += 2) {
        const point = {
            x: contour.data32S[index],
            y: contour.data32S[index + 1],
        }
        const key: keyof Corners = point.x < center.x
            ? (point.y < center.y ? 'topLeft' : 'bottomLeft')
            : (point.y < center.y ? 'topRight' : 'bottomRight')
        const distance = Math.hypot(point.x - center.x, point.y - center.y)

        if (distance > distances[key]) {
            corners[key] = point
            distances[key] = distance
        }
    }

    return corners.topLeft && corners.topRight && corners.bottomLeft && corners.bottomRight
        ? corners as Corners
        : null
}

function detectDocument() {
    const videoElement = video.value
    const canvas = detection.value
    const context = canvas?.getContext('2d')
    if (!cv || !videoElement || !canvas || !context || videoElement.readyState < 2) return

    context.drawImage(videoElement, 0, 0, canvas.width, canvas.height)

    const source = cv.imread(canvas)
    const gray = new cv.Mat()
    const blurred = new cv.Mat()
    const edges = new cv.Mat()
    const contours = new cv.MatVector()
    const hierarchy = new cv.Mat()
    let bestContour: InstanceType<typeof cv.Mat> | null = null

    try {
        cv.cvtColor(source, gray, cv.COLOR_RGBA2GRAY)
        cv.GaussianBlur(gray, blurred, new cv.Size(5, 5), 0, 0, cv.BORDER_DEFAULT)
        cv.Canny(blurred, edges, 50, 180)
        cv.findContours(
            edges,
            contours,
            hierarchy,
            cv.RETR_EXTERNAL,
            cv.CHAIN_APPROX_SIMPLE,
        )

        let largestArea = canvas.width * canvas.height * 0.12

        for (let index = 0; index < contours.size(); index++) {
            const contour = contours.get(index)
            const area = cv.contourArea(contour)

            if (area > largestArea) {
                bestContour?.delete()
                bestContour = contour.clone()
                largestArea = area
            }

            contour.delete()
        }

        detectedCorners = bestContour ? cornersForContour(bestContour) : null
        status.value = detectedCorners
            ? 'Dokument rozpoznaný'
            : 'Namierte fotoaparát na dokument'
    } catch (reason) {
        console.error('Document detection failed', reason)
        detectedCorners = null
        status.value = 'Namierte fotoaparát na dokument'
    } finally {
        bestContour?.delete()
        hierarchy.delete()
        contours.delete()
        edges.delete()
        blurred.delete()
        gray.delete()
        source.delete()
    }
}

function pointDistance(first: Point, second: Point) {
    return Math.hypot(first.x - second.x, first.y - second.y)
}

function scaleCorners(corners: Corners, scaleX: number, scaleY: number): Corners {
    const scale = (point: Point): Point => ({
        x: point.x * scaleX,
        y: point.y * scaleY,
    })

    return {
        topLeft: scale(corners.topLeft),
        topRight: scale(corners.topRight),
        bottomLeft: scale(corners.bottomLeft),
        bottomRight: scale(corners.bottomRight),
    }
}

function extractDocument(sourceCanvas: HTMLCanvasElement, corners: Corners) {
    if (!cv) return sourceCanvas

    const width = Math.max(
        pointDistance(corners.topLeft, corners.topRight),
        pointDistance(corners.bottomLeft, corners.bottomRight),
    )
    const height = Math.max(
        pointDistance(corners.topLeft, corners.bottomLeft),
        pointDistance(corners.topRight, corners.bottomRight),
    )
    const outputScale = Math.min(1, 2400 / Math.max(width, height))
    const outputWidth = Math.max(1, Math.round(width * outputScale))
    const outputHeight = Math.max(1, Math.round(height * outputScale))
    const outputCanvas = document.createElement('canvas')
    const source = cv.imread(sourceCanvas)
    const result = new cv.Mat()
    const sourcePoints = cv.matFromArray(4, 1, cv.CV_32FC2, [
        corners.topLeft.x,
        corners.topLeft.y,
        corners.topRight.x,
        corners.topRight.y,
        corners.bottomLeft.x,
        corners.bottomLeft.y,
        corners.bottomRight.x,
        corners.bottomRight.y,
    ])
    const destinationPoints = cv.matFromArray(4, 1, cv.CV_32FC2, [
        0,
        0,
        outputWidth,
        0,
        0,
        outputHeight,
        outputWidth,
        outputHeight,
    ])
    const transform = cv.getPerspectiveTransform(sourcePoints, destinationPoints)

    try {
        cv.warpPerspective(
            source,
            result,
            transform,
            new cv.Size(outputWidth, outputHeight),
            cv.INTER_LINEAR,
            cv.BORDER_CONSTANT,
            new cv.Scalar(),
        )
        cv.imshow(outputCanvas, result)

        return outputCanvas
    } finally {
        transform.delete()
        destinationPoints.delete()
        sourcePoints.delete()
        result.delete()
        source.delete()
    }
}

function canvasToBlob(canvas: HTMLCanvasElement): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => blob
                ? resolve(blob)
                : reject(new Error('Scan sa nepodarilo vytvoriť.')),
            'image/jpeg',
            0.94,
        )
    })
}

async function capture() {
    if (!canCapture.value) return

    const videoElement = video.value
    const detectionCanvas = detection.value
    if (!videoElement || !detectionCanvas) return

    capturing.value = true

    try {
        const fullCanvas = document.createElement('canvas')
        fullCanvas.width = videoElement.videoWidth
        fullCanvas.height = videoElement.videoHeight
        const context = fullCanvas.getContext('2d')
        if (!context) throw new Error('Scan sa nepodarilo vytvoriť.')

        context.drawImage(videoElement, 0, 0, fullCanvas.width, fullCanvas.height)

        const corners = detectedCorners
            ? scaleCorners(
                detectedCorners,
                fullCanvas.width / detectionCanvas.width,
                fullCanvas.height / detectionCanvas.height,
            )
            : null
        const result = corners ? extractDocument(fullCanvas, corners) : fullCanvas
        const blob = await canvasToBlob(result)
        const timestamp = Date.now()

        emit('captured', new File([blob], `scan-${timestamp}.jpg`, {
            type: 'image/jpeg',
            lastModified: timestamp,
        }))
        close()
    } catch (reason) {
        console.error('Document capture failed', reason)
        error.value = reason instanceof Error
            ? reason.message
            : 'Scan sa nepodarilo vytvoriť.'
    } finally {
        capturing.value = false
    }
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

function stopCamera() {
    stopped = true

    if (animationFrame !== null) {
        window.cancelAnimationFrame(animationFrame)
        animationFrame = null
    }
    if (detectionTimer !== null) {
        window.clearInterval(detectionTimer)
        detectionTimer = null
    }

    stream?.getTracks().forEach((track) => track.stop())
    stream = null
}

function close() {
    stopCamera()
    emit('close')
}

onMounted(startCamera)
onBeforeUnmount(stopCamera)
</script>

<template>
    <div class="fixed inset-0 z-[10000] flex flex-col bg-black">
        <video ref="video" autoplay muted playsinline class="hidden" />
        <canvas ref="detection" class="hidden" />

        <div class="relative min-h-0 flex-1 overflow-hidden">
            <canvas
                ref="preview"
                class="h-full w-full object-contain"
            />

            <div class="absolute inset-x-0 top-0 flex items-center justify-between p-4">
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
                v-if="loading"
                class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/70 text-white"
            >
                <i class="bi bi-arrow-repeat animate-spin text-xl" />
                <span class="text-normal">{{ status }}</span>
            </div>

            <div
                v-else-if="error"
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
                v-else
                class="absolute inset-x-0 bottom-5 text-center text-normal text-white drop-shadow-md"
            >
                {{ status }}
            </div>
        </div>

        <div class="flex h-28 shrink-0 items-center justify-center bg-black">
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
    </div>
</template>
