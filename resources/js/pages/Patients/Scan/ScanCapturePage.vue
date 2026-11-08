<script setup lang="ts">
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    nextTick,
    watch,
    ref,
} from 'vue'
import { useRoute } from 'vue-router'
import DatePicker from 'primevue/datepicker'
import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'
import Appbutton from '@/components/AppButton.vue'
import { toApiDate } from '@/utils/dateUtils'
const route = useRoute()
const DocumentScanner = defineAsyncComponent(
    () => import('@/components/DocumentScanner.vue'),
)
const sessionToken = computed(
    () => route.params.token as string,
)
/* -------------------------------------------------------------------------- */
/* Page state                                                                 */
/* -------------------------------------------------------------------------- */
const patientName = ref('')
const sessionValid = ref(false)
const isLoading = ref(true)
const isUploading = ref(false)
const isProcessingFiles = ref(false)
const fileSelectionError =
    ref('')
const referenceDate =
    ref<Date | null>(null)
const submitted = ref(false)
/* -------------------------------------------------------------------------- */
/* Session expiration                                                         */
/* -------------------------------------------------------------------------- */
const expiresIn = ref(0)
const maxTime = ref(0)
let expiryInterval:
    | number
    | null = null
const expiryProgress = computed(() => {
    if (
        maxTime.value <= 0 ||
        expiresIn.value <= 0
    ) {
        return 0
    }
    return Math.max(
        0,
        Math.min(
            100,
            (
                expiresIn.value /
                maxTime.value
            ) * 100,
        ),
    )
})
const formattedExpiry = computed(() => {
    const seconds = Math.max(0, Math.floor(expiresIn.value))
    const minutes = Math.floor(seconds / 60)
    const remainingSeconds = seconds % 60
    return `${minutes}:${String(remainingSeconds).padStart(2, '0')}`
})
/* -------------------------------------------------------------------------- */
/* Selected files                                                             */
/* -------------------------------------------------------------------------- */
const selectedFiles =
    ref<File[]>([])
const previewUrls =
    ref<string[]>([])
const galleryInputRef =
    ref<HTMLInputElement | null>(
        null,
    )
const galleryQueue = ref<File[]>([])
const galleryFile = computed(() => galleryQueue.value[0] ?? null)
const scannerReviewing = ref(false)

function finishGalleryFile() {
    galleryQueue.value.shift()
}

function handleGalleryError(message: string) {
    fileSelectionError.value = message
}
const previewedImageIndex =
    ref<number | null>(null)
const previewedImageUrl =
    computed(() => {
        if (
            previewedImageIndex.value ===
            null
        ) {
            return ''
        }
        return previewUrls.value[
            previewedImageIndex.value
        ] ?? ''
    })
const galleryCloseButton = ref<HTMLButtonElement | null>(null)
const imageZoomed = ref(false)
const lastAddedIndex = ref<number | null>(null)
const scannerPaused = computed(() =>
    isUploading.value || isProcessingFiles.value ||
    previewedImageIndex.value !== null || isFinalScreen.value ||
    galleryQueue.value.length > 0,
)
let galleryTouchX: number | null = null
let galleryTouchY: number | null = null
let highlightTimer: number | null = null
const flyingImages = new Set<HTMLElement>()
let previousBodyOverflow = ''

function animateIntoGallery(url: string, index: number) {
    lastAddedIndex.value = index
    if (highlightTimer !== null) window.clearTimeout(highlightTimer)
    highlightTimer = window.setTimeout(() => {
        lastAddedIndex.value = null
    }, 1500)

    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return
    const source = document.getElementById('scan-preview-anchor')
    const target = document.querySelector<HTMLElement>(`[data-scan-index="${index}"]`)
    if (!source || !target) return

    const from = source.getBoundingClientRect()
    const to = target.getBoundingClientRect()
    if (!from.width || !to.width) return

    const initialWidth = Math.min(from.width * 0.34, 170)
    const initialHeight = initialWidth * 1.33
    const startX = from.left + from.width / 2 - initialWidth / 2
    const startY = from.top + from.height / 2 - initialHeight / 2
    const endX = to.left + to.width / 2 - initialWidth / 2
    const endY = to.top + to.height / 2 - initialHeight / 2
    const endScale = Math.min(to.width / initialWidth, to.height / initialHeight)

    const ghost = document.createElement('img')
    ghost.src = url
    ghost.alt = ''
    ghost.setAttribute('aria-hidden', 'true')
    Object.assign(ghost.style, {
        position: 'fixed',
        left: `${startX}px`,
        top: `${startY}px`,
        width: `${initialWidth}px`,
        height: `${initialHeight}px`,
        objectFit: 'contain',
        background: 'white',
        borderRadius: '0.375rem',
        boxShadow: '0 12px 35px rgba(0, 0, 0, 0.24)',
        pointerEvents: 'none',
        zIndex: '10020',
        transformOrigin: 'center',
    })
    document.body.appendChild(ghost)
    flyingImages.add(ghost)

    if (!ghost.animate) {
        ghost.remove()
        flyingImages.delete(ghost)
        return
    }
    const animation = ghost.animate([
        { transform: 'translate(0, 0) scale(1)', opacity: 0.98 },
        {
            transform: `translate(${endX - startX}px, ${endY - startY}px) scale(${endScale})`,
            opacity: 0.25,
        },
    ], { duration: 780, easing: 'cubic-bezier(.22, 1, .36, 1)' })
    const remove = () => {
        ghost.remove()
        flyingImages.delete(ghost)
    }
    animation.onfinish = remove
    animation.oncancel = remove
}

function previousImage() {
    if (previewedImageIndex.value === null || previewUrls.value.length < 2) return
    previewedImageIndex.value = (
        previewedImageIndex.value - 1 + previewUrls.value.length
    ) % previewUrls.value.length
    imageZoomed.value = false
}

function nextImage() {
    if (previewedImageIndex.value === null || previewUrls.value.length < 2) return
    previewedImageIndex.value = (
        previewedImageIndex.value + 1
    ) % previewUrls.value.length
    imageZoomed.value = false
}

function onGalleryKeydown(event: KeyboardEvent) {
    if (previewedImageIndex.value === null) return
    if (event.key === 'Escape') closeImagePreview()
    if (event.key === 'ArrowRight') nextImage()
    if (event.key === 'ArrowLeft') previousImage()
}

function onGalleryTouchStart(event: TouchEvent) {
    if (event.touches.length !== 1) return
    galleryTouchX = event.touches[0].clientX
    galleryTouchY = event.touches[0].clientY
}

function onGalleryTouchEnd(event: TouchEvent) {
    if (galleryTouchX === null || galleryTouchY === null || imageZoomed.value) return
    const end = event.changedTouches[0]
    const deltaX = end.clientX - galleryTouchX
    const deltaY = end.clientY - galleryTouchY
    galleryTouchX = null
    galleryTouchY = null
    if (Math.abs(deltaX) < 60 || Math.abs(deltaX) <= Math.abs(deltaY) * 1.4) return
    if (deltaX < 0) nextImage()
    else previousImage()
}

watch(previewedImageIndex, async (index, previous) => {
    imageZoomed.value = false
    if (index !== null && previous === null) {
        previousBodyOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        await nextTick()
        galleryCloseButton.value?.focus()
    } else if (index === null && previous !== null) {
        document.body.style.overflow = previousBodyOverflow
    }
})

/* -------------------------------------------------------------------------- */
/* Final screen                                                               */
/* -------------------------------------------------------------------------- */
type FinalSeverity =
    | 'success'
    | 'error'
const isFinalScreen =
    ref(false)
const finalTitle =
    ref('')
const finalMessage =
    ref('')
const finalSeverity =
    ref<FinalSeverity>('success')
/* -------------------------------------------------------------------------- */
/* Upload limits                                                              */
/* -------------------------------------------------------------------------- */
const MAX_SOURCE_FILE_BYTES =
    25 * 1024 * 1024
const TARGET_FILE_BYTES =
    4 * 1024 * 1024
const MAX_FILE_BYTES =
    8 * 1024 * 1024
const MAX_TOTAL_BYTES =
    80 * 1024 * 1024
const MAX_IMAGE_DIMENSION =
    3200
/* -------------------------------------------------------------------------- */
/* Session helpers                                                            */
/* -------------------------------------------------------------------------- */
function stopExpiryTimer() {
    if (
        expiryInterval !== null
    ) {
        window.clearInterval(
            expiryInterval,
        )
        expiryInterval = null
    }
}
function startExpiryTimer() {
    stopExpiryTimer()
    expiryInterval =
        window.setInterval(
            () => {
                expiresIn.value--
                if (
                    expiresIn.value <= 0
                ) {
                    stopExpiryTimer()
                    showFinalScreen(
                        'error',
                        'Chyba',
                        'Čas na nahratie vypršal.',
                    )
                }
            },
            1000,
        )
}
function showFinalScreen(
    severity: FinalSeverity,
    title: string,
    message: string,
) {
    stopExpiryTimer()
    isLoading.value = false
    isUploading.value = false
    sessionValid.value = false
    previewedImageIndex.value = null
    isFinalScreen.value = true
    finalSeverity.value =
        severity
    finalTitle.value =
        title
    finalMessage.value =
        message
}
/* -------------------------------------------------------------------------- */
/* File helpers                                                               */
/* -------------------------------------------------------------------------- */
function totalSelectedBytes() {
    return selectedFiles.value
        .reduce(
            (
                total,
                file,
            ) =>
                total +
                (
                    file?.size ??
                    0
                ),
            0,
        )
}
function loadImage(
    file: File,
): Promise<HTMLImageElement> {
    return new Promise(
        (
            resolve,
            reject,
        ) => {
            const url =
                URL.createObjectURL(
                    file,
                )
            const image =
                new Image()
            image.onload = () => {
                URL.revokeObjectURL(
                    url,
                )
                resolve(image)
            }
            image.onerror = () => {
                URL.revokeObjectURL(
                    url,
                )
                reject(
                    new Error(
                        'Image could not be decoded.',
                    ),
                )
            }
            image.src = url
        },
    )
}
function canvasToBlob(
    canvas: HTMLCanvasElement,
    quality: number,
): Promise<Blob> {
    return new Promise(
        (
            resolve,
            reject,
        ) => {
            canvas.toBlob(
                (blob) => {
                    if (blob) {
                        resolve(blob)
                        return
                    }
                    reject(
                        new Error(
                            'Image could not be compressed.',
                        ),
                    )
                },
                'image/jpeg',
                quality,
            )
        },
    )
}
async function compressImage(
    file: File,
): Promise<File> {
    const image =
        await loadImage(file)
    const largestDimension =
        Math.max(
            image.naturalWidth,
            image.naturalHeight,
        )
    if (
        file.size <=
            TARGET_FILE_BYTES &&
        largestDimension <=
            MAX_IMAGE_DIMENSION
    ) {
        return file
    }
    const scale =
        Math.min(
            1,
            MAX_IMAGE_DIMENSION /
                largestDimension,
        )
    const canvas =
        document.createElement(
            'canvas',
        )
    canvas.width =
        Math.max(
            1,
            Math.round(
                image.naturalWidth *
                    scale,
            ),
        )
    canvas.height =
        Math.max(
            1,
            Math.round(
                image.naturalHeight *
                    scale,
            ),
        )
    const context =
        canvas.getContext('2d')
    if (!context) {
        throw new Error(
            'Canvas is unavailable.',
        )
    }
    context.fillStyle = '#ffffff'
    context.imageSmoothingEnabled = true
    context.imageSmoothingQuality = 'high'
    context.fillRect(
        0,
        0,
        canvas.width,
        canvas.height,
    )
    context.drawImage(
        image,
        0,
        0,
        canvas.width,
        canvas.height,
    )
    let compressed =
        await canvasToBlob(
            canvas,
            0.86,
        )
    for (
        const quality
        of [0.74, 0.62, 0.5]
    ) {
        if (
            compressed.size <=
            TARGET_FILE_BYTES
        ) {
            break
        }
        compressed =
            await canvasToBlob(
                canvas,
                quality,
            )
    }
    const filename =
        file.name.replace(
            /\.[^.]+$/,
            '',
        ) || 'scan'
    return new File(
        [compressed],
        `${filename}.jpg`,
        {
            type: 'image/jpeg',
            lastModified:
                file.lastModified,
        },
    )
}
async function addFiles(
    files: File[],
    animateArrival = false,
) {
    isProcessingFiles.value =
        true
    fileSelectionError.value =
        ''
    let rejectedFiles = 0
    try {
        for (
            const file
            of files
        ) {
            if (
                !file ||
                !file.type.startsWith(
                    'image/',
                ) ||
                file.size >
                    MAX_SOURCE_FILE_BYTES
            ) {
                rejectedFiles++
                continue
            }
            try {
                const processedFile =
                    await compressImage(
                        file,
                    )
                const nextTotal =
                    totalSelectedBytes() +
                    processedFile.size
                if (
                    processedFile.size >
                        MAX_FILE_BYTES ||
                    nextTotal >
                        MAX_TOTAL_BYTES
                ) {
                    rejectedFiles++
                    continue
                }
                selectedFiles.value
                    .push(
                        processedFile,
                    )
                previewUrls.value
                    .push(
                        URL.createObjectURL(
                            processedFile,
                        ),
                    )
                if (animateArrival) {
                    const index = previewUrls.value.length - 1
                    const imageUrl = previewUrls.value[index]
                    await nextTick()
                    try {
                        animateIntoGallery(imageUrl, index)
                    } catch (animationError) {
                        // Visual feedback must never prevent a successful image add.
                        console.warn('Could not animate saved page', animationError)
                    }
                }
            } catch (error) {
                console.error(
                    'Failed to process scan image:',
                    error,
                )
                rejectedFiles++
            }
        }
    } finally {
        isProcessingFiles.value =
            false
    }
    if (rejectedFiles > 0) {
        fileSelectionError.value =
            rejectedFiles === 1
                ? 'Jednu fotku sa nepodarilo pridať. Skúste inú.'
                : `${rejectedFiles} fotiek sa nepodarilo pridať. Skúste iné.`
    }
}
function openGallery() {
    if (
        !sessionValid.value ||
        isUploading.value ||
        isProcessingFiles.value ||
        scannerReviewing.value ||
        galleryQueue.value.length > 0
    ) {
        return
    }
    galleryInputRef.value?.click()
}
async function handleScannedFile(file: File) {
    await addFiles([file], true)
}
function handleGallerySelected(event: Event) {
    const input = event.target as HTMLInputElement
    const files = Array.from(input.files ?? [])
    input.value = ''

    if (
        !sessionValid.value || scannerReviewing.value ||
        galleryQueue.value.length > 0 || isUploading.value ||
        isProcessingFiles.value || !files.length
    ) {
        return
    }

    const validFiles = files.filter((file) =>
        file.type.startsWith('image/') && file.size <= MAX_SOURCE_FILE_BYTES,
    )
    const rejected = files.length - validFiles.length
    fileSelectionError.value = rejected
        ? rejected === 1
            ? 'Jednu fotku nemožno použiť. Vyberte menšiu fotku.'
            : `${rejected} fotiek nemožno použiť. Vyberte menšie fotky.`
        : ''

    // Each image enters the same corner-adjustment screen as a camera scan.
    // The scanner emits galleryFinished only after confirmation or skipping.
    galleryQueue.value = validFiles
}
function removeImage(
    index: number,
) {
    if (
        previewedImageIndex.value ===
        index
    ) {
        previewedImageIndex.value =
            null
    } else if (
        previewedImageIndex.value !==
            null &&
        previewedImageIndex.value >
            index
    ) {
        previewedImageIndex.value--
    }
    const url =
        previewUrls.value[index]
    if (url) {
        URL.revokeObjectURL(
            url,
        )
    }
    previewUrls.value.splice(
        index,
        1,
    )
    selectedFiles.value.splice(
        index,
        1,
    )
}
function openImagePreview(
    index: number,
) {
    previewedImageIndex.value =
        index
}
function closeImagePreview() {
    previewedImageIndex.value = null
    imageZoomed.value = false
}
/* -------------------------------------------------------------------------- */
/* Upload                                                                     */
/* -------------------------------------------------------------------------- */
async function finalizeScan() {
    if (
        !sessionValid.value ||
        isUploading.value ||
        isProcessingFiles.value ||
        scannerReviewing.value ||
        galleryQueue.value.length > 0
    ) {
        return
    }
    submitted.value = true
    if (!referenceDate.value) {
        return
    }
    if (
        selectedFiles.value
            .length === 0
    ) {
        return
    }
    isUploading.value = true
    try {
        const form =
            new FormData()
        form.append(
            'session_token',
            sessionToken.value,
        )
        form.append(
            'reference_date',
            toApiDate(
                referenceDate.value,
            ) ?? '',
        )
        selectedFiles.value
            .forEach(
                (
                    file,
                    index,
                ) => {
                    form.append(
                        'images[]',
                        file,
                        file.name ||
                            `image_${index}.jpg`,
                    )
                },
            )
        const response =
            await fetch(
                '/api/scan/finalize',
                {
                    method:
                        'POST',
                    body:
                        form,
                },
            )
        const raw =
            await response.text()
        let data: any =
            null
        try {
            data =
                JSON.parse(raw)
        } catch {
            data = null
        }
        if (!response.ok) {
            console.error(
                'finalize failed:',
                response.status,
                raw,
            )
            const message =
                data?.message ||
                (
                    data?.errors
                        ? Object.values(
                            data.errors,
                        )
                            .flat()
                            .join(', ')
                        : null
                ) ||
                'Nahrávanie zlyhalo. Skúste znova.'
            showFinalScreen(
                'error',
                'Chyba',
                message,
            )
            return
        }
        const explicitlyFailed =
            data?.success ===
                false ||
            data?.error === true ||
            (
                typeof data?.status ===
                    'string' &&
                [
                    'error',
                    'failed',
                    'fail',
                ].includes(
                    data.status
                        .toLowerCase(),
                )
            )
        if (
            explicitlyFailed
        ) {
            const message =
                data?.message ||
                (
                    data?.errors
                        ? Object.values(
                            data.errors,
                        )
                            .flat()
                            .join(', ')
                        : null
                ) ||
                'Nahrávanie zlyhalo. Skúste znova.'
            showFinalScreen(
                'error',
                'Chyba',
                message,
            )
            return
        }
        showFinalScreen(
            'success',
            'Hotovo',
            'Lekársky nález bol odoslaný.',
        )
    } catch (error) {
        console.error(
            'finalize error:',
            error,
        )
        showFinalScreen(
            'error',
            'Chyba',
            'Nahrávanie zlyhalo. Skúste znova.',
        )
    } finally {
        isUploading.value =
            false
    }
}
/* -------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* -------------------------------------------------------------------------- */
onMounted(
    async () => {
        try {
            const response =
                await fetch(
                    '/api/scan/info',
                    {
                        method:
                            'POST',
                        headers: {
                            'Content-Type':
                                'application/json',
                        },
                        body:
                            JSON.stringify(
                                {
                                    session_token:
                                        sessionToken.value,
                                },
                            ),
                    },
                )
            const text =
                await response.text()
            let data: any =
                null
            try {
                data =
                    JSON.parse(text)
            } catch {
                data = null
            }
            if (!response.ok) {
                console.error(
                    'scan/info failed:',
                    response.status,
                    text,
                )
                showFinalScreen(
                    'error',
                    'Chyba',
                    'Lekársky nález sa nepodarilo nahrať.',
                )
                return
            }
            if (!data?.data) {
                showFinalScreen(
                    'error',
                    'Chyba',
                    data?.message ||
                        'Odkaz už neplatí.',
                )
                return
            }
            if (
                data.data
                    .is_expired ||
                data.data
                    .expires_in <=
                    0
            ) {
                showFinalScreen(
                    'error',
                    'Chyba',
                    'Čas na odoslanie už vypršal.',
                )
                return
            }
            sessionValid.value =
                true
            patientName.value =
                data.data
                    .patient_name
            expiresIn.value =
                data.data
                    .expires_in
            maxTime.value =
                Math.max(
                    Number(
                        data.data
                            .lifetime_seconds,
                    ) || 0,
                    expiresIn.value,
                )
            startExpiryTimer()
        } catch (error) {
            console.error(
                'Error during onMounted:',
                error,
            )
            showFinalScreen(
                'error',
                'Chyba',
                'Odkaz sa nepodarilo otvoriť. Skúste to znova.',
            )
        } finally {
            isLoading.value =
                false
        }
    },
)
onMounted(() => {
    window.addEventListener('keydown', onGalleryKeydown)
})
onBeforeUnmount(() => {
    stopExpiryTimer()
    window.removeEventListener('keydown', onGalleryKeydown)
    if (previewedImageIndex.value !== null) {
        document.body.style.overflow = previousBodyOverflow
    }
    if (highlightTimer !== null) window.clearTimeout(highlightTimer)
    flyingImages.forEach((image) => image.remove())
    flyingImages.clear()
    previewUrls.value
        .forEach(
            (url) =>
                URL.revokeObjectURL(
                    url,
                ),
        )
})
</script>
<template>
    <div
        v-if="isLoading"
        class="flex min-h-[100dvh] w-full items-center justify-center bg-tag3"
        role="status"
        aria-label="Otváram stránku"
    >
        <i class="bi bi-arrow-repeat animate-spin text-xl text-accent" aria-hidden="true" />
    </div>

    <Transition name="final-screen">
        <div
            v-if="isFinalScreen"
            class="fixed inset-0 z-[9999] flex min-h-[100dvh] items-center justify-center bg-accent px-5 py-8"
            :class="finalSeverity === 'success' ? 'bg-accent' : 'bg-danger'"
            role="status"
        >
            <div class="final-message flex w-full max-w-md flex-col items-center text-center text-white">
                <div class="mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-white/15">
                    <i
                        :class="finalSeverity === 'success' ? 'bi bi-check-lg' : 'bi bi-x-lg'"
                        class="text-4xl"
                        aria-hidden="true"
                    />
                </div>
                <h1 class="mb-3 text-heading-accent text-white!">{{ finalTitle }}</h1>
                <p class="text-normal">{{ finalMessage }}</p>
                <p class="mt-6 text-mini text-white/80">Túto stránku už môžete zatvoriť.</p>
            </div>
        </div>
    </Transition>

    <main
        v-if="!isLoading && !isFinalScreen"
        class="flex min-h-[100dvh] w-full flex-col bg-tag3 px-3 py-5 text-darkgrey sm:px-6 sm:py-8 lg:px-8"
    >
        <header class="mb-5 flex flex-col items-start justify-between gap-3 sm:mb-7">
            <div
                v-if="sessionValid"
                class="w-full shrink-0"
                role="timer"
                aria-live="off"
            >
                <div class="mt-1 h-1 overflow-hidden bg-white rounded-md">
                    <div
                        class="h-full rounded-full bg-accent transition-[width] duration-500"
                        :style="{ width: `${expiryProgress}%` }"
                    />
                </div>
            </div>

            <div class="min-w-0 flex gap-2">
                <h1 class="break-words text-normal text-darkgrey!">{{ patientName }}</h1>
                <Tag 
                    value="Lekársky nález"
                    class="bg-darkgrey!"
                />
            </div>


        </header>

        <form class="flex w-full flex-col gap-7 sm:gap-9" @submit.prevent="finalizeScan">
            <section class="flex w-full flex-col gap-3" aria-labelledby="scan-title">
                <DocumentScanner
                    v-if="sessionValid"
                    :paused="scannerPaused"
                    :gallery-file="galleryFile"
                    :gallery-disabled="scannerPaused || scannerReviewing"
                    @captured="handleScannedFile"
                    @gallery-requested="openGallery"
                    @gallery-finished="finishGalleryFile"
                    @gallery-error="handleGalleryError"
                    @reviewing-change="scannerReviewing = $event"
                />
            </section>

            <!-- Second: pages appear here automatically, and can be opened or removed. -->
            <section class="flex w-full flex-col gap-3" aria-labelledby="pages-title">
                <p v-if="galleryQueue.length" class="text-mini text-darkgrey/70" role="status">
                    Vyberte okraje každej fotky. Zostáva: {{ galleryQueue.length }}.
                </p>

                <input
                    ref="galleryInputRef"
                    type="file"
                    accept="image/*"
                    multiple
                    class="hidden"
                    aria-label="Vybrať fotky dokumentu"
                    @change="handleGallerySelected"
                />

                <TransitionGroup
                    v-if="previewUrls.length"
                    name="saved-page"
                    tag="div"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6"
                >
                    <div
                        v-for="(url, index) in previewUrls"
                        :key="url"
                        :data-scan-index="index"
                        class="relative aspect-[3/4] overflow-hidden rounded-md border bg-white transition-shadow duration-300"
                        :class="lastAddedIndex === index ? 'border-accent shadow-lg shadow-accent/15' : 'border-darkgrey/10'"
                    >
                        <button
                            type="button"
                            class="absolute inset-0 h-full w-full cursor-pointer"
                            :aria-label="`Zobraziť stranu ${index + 1}`"
                            :disabled="scannerReviewing || galleryQueue.length > 0"
                            @click="openImagePreview(index)"
                        >
                            <img :src="url" :alt="`Strana ${index + 1}`" class="h-full w-full object-cover" />
                        </button>

                        <Appbutton
                            type="button"
                            variant="accent"
                            class="absolute left-2 top-2 flex h-10 w-10 items-center justify-center rounded-md bg-accent text-white disabled:opacity-50"
                            icon="bi-x-lg"
                            :disabled="isUploading || isProcessingFiles || scannerReviewing || galleryQueue.length > 0"
                            :aria-label="`Odstrániť stranu ${index + 1}`"
                            @click="removeImage(index)"
                        >
                        </Appbutton>
                    </div>
                </TransitionGroup>


                <p
                    v-if="fileSelectionError"
                    class="rounded-md bg-danger/10 px-3 py-2 text-mini text-danger"
                    role="alert"
                >
                    {{ fileSelectionError }}
                </p>
                <p
                    v-if="submitted && selectedFiles.length === 0"
                    class="rounded-md bg-danger/10 px-3 py-2 text-mini text-danger"
                    role="alert"
                >
                    Najprv pridajte aspoň jednu stranu.
                </p>
            </section>

            <section class="flex w-full flex-col gap-4 pb-5" aria-labelledby="finish-title">
                <FormField
                    label="Dátum odporučenia"
                    required
                    :error="submitted && !referenceDate ? 'Vyberte dátum.' : null"
                    v-slot="{ id, describedBy, invalid }"
                >
                    <DatePicker
                        :id="id"
                        v-model="referenceDate"
                        dateFormat="dd.mm.yy"
                        :showIcon="false"
                        :manualInput="false"
                        class="w-full"
                        :inputClass="[
                            'w-full! cursor-pointer! rounded-md! bg-white! px-3! py-3! text-normal! text-darkgrey!',
                            invalid ? 'border-danger!' : 'border-none!'
                        ]"
                        :invalid="invalid"
                        :aria-invalid="invalid || undefined"
                        :aria-describedby="describedBy"
                    />
                </FormField>
                <AppButton
                    type="submit"
                    label="Poslať nález"
                    :loading="isUploading || isProcessingFiles"
                    :loading-label="isProcessingFiles ? 'Pripravujem fotky' : 'Posielam'"
                    :disabled="isUploading || isProcessingFiles || !sessionValid || scannerReviewing || galleryQueue.length > 0"
                    class="w-full! rounded-md!"
                    :align="'center'"
                />
            </section>
        </form>
    </main>

    <!-- Custom full-screen image viewer; no PrimeVue dialog padding. -->
    <Teleport to="body">
        <Transition name="gallery">
            <div
                v-if="previewedImageIndex !== null"
                class="fixed inset-0 z-[10050] flex h-[100dvh] w-screen flex-col bg-black/95 text-white"
                role="dialog"
                aria-modal="true"
                aria-label="Zobrazenie strany"
                @touchstart.passive="onGalleryTouchStart"
                @touchend.passive="onGalleryTouchEnd"
            >
                <div
                    class="flex min-h-14 shrink-0 items-center justify-end gap-3 px-3 py-2 sm:px-5"
                    :style="{ paddingTop: 'max(0.5rem, env(safe-area-inset-top))' }"
                >
                    <button
                        ref="galleryCloseButton"
                        type="button"
                        class="flex h-11 w-11 items-center justify-center text-white cursor-pointer"
                        aria-label="Zavrieť obrázok"
                        @click="closeImagePreview"
                    >
                        <i class="bi bi-x-lg text-lg" aria-hidden="true" />
                    </button>
                </div>

                <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-auto" @click.self="closeImagePreview">
                    <button
                        v-if="previewedImageUrl"
                        type="button"
                        class="flex h-full w-full items-center justify-center"
                        :aria-label="imageZoomed ? 'Zmenšiť obrázok' : 'Zväčšiť obrázok'"
                        @click="imageZoomed = !imageZoomed"
                    >
                        <img
                            :key="previewedImageUrl"
                            :src="previewedImageUrl"
                            :alt="`Strana ${previewedImageIndex + 1}`"
                            class="block max-h-full max-w-full object-contain transition-transform duration-200"
                            :class="imageZoomed ? 'cursor-zoom-out' : 'cursor-zoom-in'"
                            :style="{ transform: imageZoomed ? 'scale(2)' : 'scale(1)' }"
                            draggable="false"
                        />
                    </button>
                </div>

                <div
                    class="flex min-h-16 shrink-0 items-center justify-center gap-6 px-4 py-2"
                    :style="{ paddingBottom: 'max(0.5rem, env(safe-area-inset-bottom))' }"
                >
                    <button
                        type="button"
                        class="flex h-11 w-14 items-center justify-center text-white disabled:opacity-30"
                        :disabled="previewUrls.length < 2"
                        aria-label="Predchádzajúca strana"
                        @click="previousImage"
                    >
                        <i class="bi bi-chevron-left text-lg" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        class="flex h-11 w-14 items-center justify-center text-white disabled:opacity-30"
                        :disabled="previewUrls.length < 2"
                        aria-label="Ďalšia strana"
                        @click="nextImage"
                    >
                        <i class="bi bi-chevron-right text-lg" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.final-screen-enter-active {
    transition: opacity 400ms ease;
}

.final-screen-enter-from {
    opacity: 0;
}

.final-screen-enter-from .final-message {
    opacity: 0;
    transform: translateY(16px) scale(0.92);
}

.final-screen-enter-active .final-message {
    transition: opacity 500ms ease 120ms, transform 500ms cubic-bezier(0.22, 1, 0.36, 1) 120ms;
}

.saved-page-enter-active {
    transition: opacity 500ms ease, transform 600ms cubic-bezier(0.22, 1, 0.36, 1);
}

.saved-page-enter-from {
    opacity: 0;
    transform: translateY(-20px) scale(0.77) rotate(-3deg);
}

.saved-page-leave-active {
    transition: opacity 180ms ease, transform 180ms ease;
}

.saved-page-leave-to {
    opacity: 0;
    transform: scale(0.9);
}

.saved-page-move {
    transition: transform 240ms ease;
}

.gallery-enter-active,
.gallery-leave-active {
    transition: opacity 180ms ease;
}

.gallery-enter-from,
.gallery-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .final-screen-enter-active,
    .final-screen-enter-active .final-message,
    .saved-page-enter-active,
    .saved-page-leave-active,
    .saved-page-move,
    .gallery-enter-active,
    .gallery-leave-active {
        transition: none;
    }
}
</style>
