<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'

import AppButton from '@/components/AppButton.vue'
import DocumentAlert from '@/components/DocumentAlert.vue'
import FormField from '@/components/FormField.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { usePatientStore } from '@/stores/patientStore'

const router = useRouter()
const toast = useToast()
const authStore = useAuthStore()
const patientStore = usePatientStore()

patientStore.loadFromStorage()

const { currentBranch } = storeToRefs(authStore)
const { current: currentPatient } = storeToRefs(patientStore)

const patientId = computed(() => currentPatient.value?.id ?? null)
const branchId = computed(() => currentBranch.value?.id ?? currentPatient.value?.branch_id ?? null)
const patientDeathDate = computed(() => currentPatient.value?.death_date ?? null)

const date = ref<Date>(new Date())
const submitted = ref(false)
const isGenerating = ref(false)

const documentExists = ref(false)
const documentId = ref<number | null>(null)
const dialogVisible = ref(false)

const previewHtml = ref('')
const isPreviewLoading = ref(false)
const previewError = ref<string | null>(null)
const previewPageRef = ref<HTMLElement | null>(null)

const A4_WIDTH_PX = 210 / 25.4 * 96
const A4_HEIGHT_PX = 297 / 25.4 * 96
const previewScale = ref(1)
const previewFrameStyle = computed(() => ({
    width: `${A4_WIDTH_PX}px`,
    height: `${A4_HEIGHT_PX}px`,
    transform: `scale(${previewScale.value})`,
    transformOrigin: 'top left',
}))

let previewTimer: ReturnType<typeof setTimeout> | null = null
let previewRequestId = 0
let previewResizeObserver: ResizeObserver | null = null

function toLocalYMD(value: Date): string {
    const year = value.getFullYear()
    const month = String(value.getMonth() + 1).padStart(2, '0')
    const day = String(value.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

const deathDateYmd = computed(() => {
    const value = patientDeathDate.value

    return typeof value === 'string' && value ? value.slice(0, 10) : null
})

const maxSelectableDate = computed<Date | undefined>(() => {
    if (!deathDateYmd.value) return undefined

    const value = new Date(`${deathDateYmd.value}T00:00:00`)
    if (Number.isNaN(value.getTime())) return undefined

    return new Date(value.getFullYear(), value.getMonth(), value.getDate())
})

const isDateAfterDeath = computed(() => {
    return !!date.value && !!deathDateYmd.value && toLocalYMD(date.value) > deathDateYmd.value
})

const dateError = computed(() => {
    if (!submitted.value) return null
    if (!date.value) return 'Dátum je povinný.'
    if (isDateAfterDeath.value) return 'Dátum dokumentu nemôže byť po dátume úmrtia pacienta.'

    return null
})

function validateForm(): boolean {
    return !!patientId.value && !!branchId.value && !!date.value && !isDateAfterDeath.value
}

async function checkDocumentExists(): Promise<boolean | null> {
    if (!patientId.value || !date.value || isDateAfterDeath.value) {
        documentExists.value = false
        documentId.value = null
        dialogVisible.value = false
        return false
    }

    try {
        const response = await api.post('/v1/documents/check-exists', {
            type: 'agreement',
            date: toLocalYMD(date.value),
            patient_id: patientId.value,
        })
        const result = response.data?.data ?? response.data

        documentExists.value = result?.exists ?? false
        documentId.value = result?.document_id ?? null
        dialogVisible.value = documentExists.value

        return documentExists.value
    } catch (error) {
        console.error('Failed to check agreement existence:', error)
        documentExists.value = false
        documentId.value = null
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Existenciu dokumentu sa nepodarilo overiť.',
            life: 5000,
        })

        return null
    }
}

async function loadPreview() {
    if (!patientId.value || !branchId.value || !date.value || isDateAfterDeath.value) {
        previewRequestId++
        isPreviewLoading.value = false
        previewHtml.value = ''
        previewError.value = null
        return
    }

    const requestId = ++previewRequestId
    isPreviewLoading.value = true
    previewError.value = null

    try {
        const response = await api.get('/v1/agreements/draft-preview', {
            params: {
                patient_id: patientId.value,
                branch_id: branchId.value,
                date: toLocalYMD(date.value),
            },
            headers: { Accept: 'text/html' },
            responseType: 'text',
        })

        if (requestId !== previewRequestId) return
        previewHtml.value = String(response.data ?? '')
    } catch (error) {
        if (requestId !== previewRequestId) return

        console.error('Failed to load agreement preview:', error)
        previewHtml.value = ''
        previewError.value = 'Náhľad dokumentu sa nepodarilo načítať.'
    } finally {
        if (requestId === previewRequestId) {
            isPreviewLoading.value = false
        }
    }
}

function schedulePreview() {
    if (previewTimer) clearTimeout(previewTimer)
    previewTimer = setTimeout(loadPreview, 250)
}

watch(
    [
        () => date.value,
        () => patientId.value,
        () => branchId.value,
    ],
    schedulePreview,
    { immediate: true },
)

function closeDialog() {
    dialogVisible.value = false
}

function continueDocumentGeneration() {
    dialogVisible.value = false
    void generateDocument({ duplicateConfirmed: true })
}

function handleExistingDocumentDeleted() {
    documentExists.value = false
    documentId.value = null
    dialogVisible.value = false
}

async function generateDocument(
    options: { duplicateConfirmed?: boolean } = {},
) {
    if (isGenerating.value) return

    submitted.value = true

    if (!patientId.value) {
        toast.add({
            severity: 'error',
            summary: 'Chýbajúci pacient',
            detail: 'Najprv vyberte pacienta.',
            life: 3000,
        })
        return
    }

    if (!validateForm()) {
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: isDateAfterDeath.value
                ? 'Dátum dokumentu nemôže byť po dátume úmrtia pacienta.'
                : 'Dokument sa nepodarilo vytvoriť. Skontrolujte zadané údaje.',
            life: 3000,
        })
        return
    }

    isGenerating.value = true

    try {
        if (options.duplicateConfirmed !== true) {
            const exists = await checkDocumentExists()
            if (exists !== false) return
        }

        const response = await api.post('/v1/agreements', {
            patient_id: patientId.value,
            branch_id: branchId.value,
            date: toLocalYMD(date.value),
        })
        const generatedDocumentId = response.data?.data?.document_id

        if (!generatedDocumentId) {
            throw new Error('Server nevrátil ID dokumentu.')
        }

        toast.add({
            severity: 'success',
            summary: 'Úspešne vytvorené',
            detail: 'Dohoda o poskytovaní zdravotnej starostlivosti bola úspešne vytvorená.',
            life: 3000,
        })

        await router.push({
            name: 'documents-agreement',
            params: { documentId: generatedDocumentId },
        })
    } catch (error: any) {
        console.error('Failed to generate agreement document:', error)
        const message = error?.response?.data?.errors
            ? Object.values(error.response.data.errors).flat()[0]
            : error?.response?.data?.message ?? error?.message ?? 'Chyba pri vytváraní dohody.'

        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: String(message),
            life: 5000,
        })
    } finally {
        isGenerating.value = false
    }
}

function submitDocument() {
    void generateDocument()
}

onMounted(() => {
    if (previewPageRef.value && typeof ResizeObserver !== 'undefined') {
        previewResizeObserver = new ResizeObserver(([entry]) => {
            const width = entry?.contentRect.width ?? 0
            if (width > 0) previewScale.value = width / A4_WIDTH_PX
        })
        previewResizeObserver.observe(previewPageRef.value)
    }

    if (!currentPatient.value) {
        toast.add({
            severity: 'warn',
            summary: 'Chýbajúci pacient',
            detail: 'Najprv vyberte pacienta.',
            life: 3000,
        })
    }
})

onBeforeUnmount(() => {
    previewResizeObserver?.disconnect()
    previewResizeObserver = null

    if (previewTimer) clearTimeout(previewTimer)
    previewRequestId++
})
</script>

<template>
    <DocumentAlert
        :visible="dialogVisible"
        :document-id="documentId"
        document-url="/documents/agreement/{id}"
        @update:visible="dialogVisible = $event"
        @close="closeDialog"
        @continue="continueDocumentGeneration"
        @deleted="handleExistingDocumentDeleted"
    />

    <div class="h-full min-h-0 overflow-hidden">
        <div class="grid h-full min-h-0 grid-cols-2">
            <aside class="min-h-0 overflow-y-auto pr-6">
                <form class="flex min-h-full flex-col" @submit.prevent="submitDocument">
                    <section class="flex flex-col gap-6 rounded-md bg-tag3 p-6">
                        <FormField
                            label="Dátum"
                            required
                            :error="dateError"
                            v-slot="{ id, describedBy, invalid }"
                        >
                            <DatePicker
                                :id="id"
                                v-model="date"
                                dateFormat="dd.mm.yy"
                                :showIcon="false"
                                :maxDate="maxSelectableDate"
                                :invalid="invalid"
                                :aria-invalid="invalid || undefined"
                                :aria-describedby="describedBy"
                                class="w-full"
                                inputClass="w-full! cursor-pointer! border-none!"
                            />
                        </FormField>

                        <div class="flex justify-end">
                            <AppButton
                                type="submit"
                                variant="accent"
                                label="Generovať dokument"
                                :loading="isGenerating"
                                loading-label="Generujem"
                                :disabled="!patientId || !branchId"
                            />
                        </div>
                    </section>
                </form>
            </aside>

            <section class="min-h-0 min-w-0 overflow-hidden pl-6" aria-label="Náhľad dokumentu">
                <div class="flex h-full min-h-0 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto bg-white px-6 pb-6">
                        <div class="flex min-h-full justify-center">
                            <div
                                ref="previewPageRef"
                                class="relative aspect-[210/297] w-full max-w-3xl self-start overflow-hidden border border-almostwhite/50 bg-white shadow-lg"
                            >
                                <iframe
                                    v-if="previewHtml"
                                    :srcdoc="previewHtml"
                                    title="Náhľad dohody o poskytovaní zdravotnej starostlivosti"
                                    :style="previewFrameStyle"
                                    class="absolute left-0 top-0 border-0"
                                />

                                <div
                                    v-else
                                    class="flex h-full items-center justify-center p-8 text-center text-normal text-darkgrey"
                                >
                                    {{ previewError ?? 'Náhľad dokumentu' }}
                                </div>

                                <div
                                    v-if="isPreviewLoading"
                                    class="absolute inset-0 flex items-center justify-center bg-white/75"
                                >
                                    <i
                                        class="bi bi-arrow-repeat animate-spin text-xl text-accent"
                                        aria-label="Aktualizujem náhľad"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>