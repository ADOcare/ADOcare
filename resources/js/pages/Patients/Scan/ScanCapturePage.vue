<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue'

import { useRoute } from 'vue-router'
import DatePicker from 'primevue/datepicker'

import AppButton from '@/components/AppButton.vue'
import FormField from '@/components/FormField.vue'

import { toApiDate } from '@/utils/dateUtils'

const route = useRoute()

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
    2400

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
                ? 'Jeden obrázok sa nepodarilo pridať. Maximálna veľkosť pôvodného obrázka je 25 MB.'
                : `${rejectedFiles} obrázkov sa nepodarilo pridať. Maximálna veľkosť jedného pôvodného obrázka je 25 MB.`
    }
}

function openGallery() {
    if (
        !sessionValid.value ||
        isUploading.value ||
        isProcessingFiles.value
    ) {
        return
    }

    galleryInputRef.value
        ?.click()
}

async function handleGallerySelected(
    event: Event,
) {
    if (!sessionValid.value) {
        return
    }

    const input =
        event.target as HTMLInputElement

    const files =
        Array.from(
            input.files ?? [],
        )

    if (!files.length) {
        return
    }

    await addFiles(files)

    input.value = ''
}

function removeImage(
    index: number,
) {
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

/* -------------------------------------------------------------------------- */
/* Upload                                                                     */
/* -------------------------------------------------------------------------- */

async function finalizeScan() {
    if (
        !sessionValid.value ||
        isUploading.value ||
        isProcessingFiles.value
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
            'Úspech',
            'Lekársky nález bol úspešne nahratý.',
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
                        'Nevalidná relácia.',
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
                    'Relácia je nevalidná alebo vypršala.',
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
                'Chyba pri overovaní relácie.',
            )
        } finally {
            isLoading.value =
                false
        }
    },
)

onBeforeUnmount(() => {
    stopExpiryTimer()

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
    <!-- Loading -->
    <div
        v-if="isLoading"
        class="
            flex
            min-h-[50vh]
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

    <!-- Final animated screen -->
    <Transition name="final-screen">
        <div
            v-if="isFinalScreen"
            class="
                fixed
                inset-0
                z-[9999]
                flex
                items-center
                justify-center
                px-6
            "
            :class="
                finalSeverity ===
                'success'
                    ? 'bg-accent'
                    : 'bg-danger'
            "
        >
            <div
                class="
                    final-message
                    flex
                    flex-col
                    items-center
                    text-center
                    text-white
                "
            >
                <div
                    class="
                        mb-6
                        flex
                        h-20
                        w-20
                        items-center
                        justify-center
                        rounded-full
                        bg-white/15
                        backdrop-blur-sm
                    "
                >
                    <i
                        :class="
                            finalSeverity ===
                            'success'
                                ? 'bi bi-check-lg'
                                : 'bi bi-x-lg'
                        "
                        class="
                            text-4xl
                            text-white
                        "
                    />
                </div>

                <h1
                    class="
                        mb-3
                        text-heading-accent
                        text-white!
                    "
                >
                    {{ finalTitle }}
                </h1>

                <p
                    class="
                        text-normal
                        text-white
                    "
                >
                    {{ finalMessage }}
                </p>

                <p
                    class="
                        mt-6
                        text-mini
                        text-white/80
                    "
                >
                    Môžete zatvoriť túto stránku.
                </p>
            </div>
        </div>
    </Transition>

    <!-- Main page -->
    <div
        v-if="
            !isLoading &&
            !isFinalScreen
        "
        class="
            flex
            min-h-full
            w-full
            flex-col
            gap-4
            p-8
            sm:p-6
        "
    >
        <!-- Header -->
        <header
            class="
                flex
                items-center
                justify-between
                pb-2
            "
        >
            <div
                class="
                    flex
                    min-w-0
                    items-center
                "
            >
                <h1
                    class="
                        truncate
                        border-r-2!
                        border-accent!
                        pr-sm!
                        text-heading-accent!
                    "
                >
                    {{ patientName }}
                </h1>

                <h1
                    class="
                        whitespace-nowrap
                        px-sm!
                        font-light!
                        text-heading-accent!
                        text-darkgrey!
                    "
                >
                    Lekársky nález
                </h1>
            </div>
        </header>

        <form
            class="
                flex
                w-full
                flex-col
                gap-3
            "
            @submit.prevent="
                finalizeScan
            "
        >
            <!-- Main section -->
            <section
                class="
                    flex
                    flex-col
                    gap-4
                    rounded-md
                    bg-tag3
                    p-4
                "
            >
                <!-- Date -->
                <FormField
                    label="Dátum odporučenia"
                    required
                    :error="
                        submitted &&
                        !referenceDate
                            ? 'Dátum odporučenia je povinný.'
                            : null
                    "
                    v-slot="{
                        id,
                        describedBy,
                        invalid,
                    }"
                >
                    <DatePicker
                        :id="id"
                        v-model="
                            referenceDate
                        "
                        dateFormat="dd.mm.yy"
                        :showIcon="false"
                        :manualInput="false"
                        class="w-full"
                        inputClass="
                            w-full!
                            cursor-pointer!
                            border-none!
                        "
                        :invalid="
                            invalid
                        "
                        :aria-invalid="
                            invalid ||
                            undefined
                        "
                        :aria-describedby="
                            describedBy
                        "
                    />
                </FormField>

                <!-- Hidden gallery input -->
                <input
                    ref="galleryInputRef"
                    type="file"
                    accept="image/*"
                    multiple
                    class="hidden"
                    @change="
                        handleGallerySelected
                    "
                />

                <!-- Photo grid -->
                <FormField
                    label="Stránky dokumentu"
                    required
                >
                <div
                    class="
                        grid
                        grid-cols-2
                        gap-2
                        sm:grid-cols-6
                    "
                >
                    <!-- Filled placeholders -->
                    <div
                        v-for="(
                            url,
                            index
                        ) in previewUrls"
                        :key="url"
                        class="
                            group
                            relative
                            aspect-square
                            overflow-hidden
                            rounded-md
                            bg-white
                        "
                    >
                        <img
                            :src="url"
                            alt=""
                            class="
                                h-full
                                w-full
                                object-cover
                            "
                        />

                        <!-- Order -->
                        <div
                            class="
                                absolute
                                left-2
                                top-2
                                flex
                                h-7
                                min-w-7
                                items-center
                                justify-center
                                rounded-md
                                bg-darkgrey
                                px-1.5
                                text-mini
                                text-white
                            "
                        >
                            {{
                                index +
                                1
                            }}
                        </div>

                        <!-- Remove -->
                        <AppButton
                            type="button"
                            variant="secondary"
                            icon="
                                bi
                                bi-x-lg
                            "
                            aria-label="
                                Odstrániť obrázok
                            "
                            class="
                                absolute!
                                right-2!
                                top-2!
                                h-6!
                                min-w-6!
                                px-0!
                                text-xs!
                            "
                            @click="
                                removeImage(
                                    index,
                                )
                            "
                        />
                    </div>

                    <!-- Permanent empty placeholder -->
                    <button
                        type="button"
                        class="
                            flex
                            aspect-square
                            cursor-pointer
                            flex-col
                            items-center
                            justify-center
                            gap-2
                            rounded-md
                            bg-white
                            text-tag3
                            transition-all
                            duration-200
                            hover:scale-[0.98]
                            hover:bg-tag2
                            disabled:cursor-wait
                            disabled:opacity-60
                        "
                        :disabled="
                            isProcessingFiles ||
                            isUploading
                        "
                        @click="
                            openGallery
                        "
                    >
                        <div
                            class="
                                flex
                                h-10
                                w-10
                                items-center
                                justify-center
                                rounded-full
                            "
                        >
                            <i
                                    class="bi"
                                    :class="
                                        isProcessingFiles
                                            ? 'bi-arrow-repeat animate-spin'
                                            : 'bi-plus-lg'
                                    "
                            />
                        </div>
                    </button>
                </div>
                </FormField>

                <div
                    v-if="
                        fileSelectionError
                    "
                    class="
                        rounded-md
                        bg-danger/10
                        px-3
                        py-2
                        text-mini
                        text-danger
                    "
                >
                    {{
                        fileSelectionError
                    }}
                </div>

                <!-- Image validation -->
                <div
                    v-if="
                        submitted &&
                        selectedFiles.length ===
                            0
                    "
                    class="
                        rounded-md
                        bg-danger/10
                        px-3
                        py-2
                        text-mini
                        text-danger
                    "
                >
                    Pridajte aspoň jeden obrázok lekárskeho nálezu.
                </div>

                <!-- Submit -->
                <AppButton
                    type="submit"
                    label="
                        Nahrať nález
                    "
                    :loading="
                        isUploading ||
                        isProcessingFiles
                    "
                    :loading-label="
                        isProcessingFiles
                            ? 'Spracúvam obrázky'
                            : 'Nahrávam'
                    "
                    :disabled="
                        isUploading ||
                        isProcessingFiles ||
                        !sessionValid
                    "
                    class="
                        w-full!
                    "
                    :align="'center'"
                />
            </section>

            <!-- Progress bar -->
            <div
                class="
                    h-1
                    w-full
                    overflow-hidden
                    rounded-full
                    bg-tag3
                "
            >
                <div
                    class="
                        h-full
                        rounded-full
                        bg-darkgrey
                        transition-[width]
                        duration-500
                    "
                    :style="{
                        width:
                            `${expiryProgress}%`,
                    }"
                />
            </div>
        </form>
    </div>
</template>

<style scoped>
.final-screen-enter-active {
    transition:
        opacity 400ms ease,
        background-color 500ms ease;
}

.final-screen-enter-from {
    opacity: 0;
}

.final-screen-enter-to {
    opacity: 1;
}

.final-screen-enter-from .final-message {
    opacity: 0;
    transform:
        translateY(16px)
        scale(0.92);
}

.final-screen-enter-active .final-message {
    transition:
        opacity 500ms ease 120ms,
        transform 500ms
            cubic-bezier(
                0.22,
                1,
                0.36,
                1
            )
            120ms;
}

.final-screen-enter-to .final-message {
    opacity: 1;

    transform:
        translateY(0)
        scale(1);
}
</style>