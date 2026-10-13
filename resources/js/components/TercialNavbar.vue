<script setup lang="ts">
import {
    computed,
    ref,
    watch,
    useAttrs,
} from 'vue'

import {
    RouterLink,
    useRouter,
} from 'vue-router'

import Popover from 'primevue/popover'

import {
    openPatientDocumentsModal,
    openPatientEditModal,
    openScanDocumentModal,
} from '@/helpers/modalHelpers'

import {
    usePatientStore,
    type PatientWithCoverage,
} from '@/stores/patientStore'

import { useAuthStore } from '@/stores/auth'
import { getPatientIdentifier } from '@/utils/patientIdentifier'

import AppButton from '@/components/AppButton.vue'

defineOptions({
    inheritAttrs: false,
})

const attrs = useAttrs()

const router = useRouter()

const patientStore = usePatientStore()
const authStore = useAuthStore()

const patient = computed<PatientWithCoverage | null>(
    () => patientStore.current,
)

const currentBranchId = computed(
    () => authStore.currentBranch?.id ?? null,
)

const patientName = computed(() =>
    patient.value
        ? `${patient.value.first_name ?? ''} ${patient.value.last_name ?? ''}`.trim()
        : '',
)

const patientIdentifier = computed(() =>
    getPatientIdentifier(patient.value),
)

/* -------------------------------------------------------------------------- */
/* Patient actions                                                            */
/* -------------------------------------------------------------------------- */

const patientActionsPopover =
    ref<InstanceType<typeof Popover> | null>(null)

function togglePatientActions(
    event: Event,
) {
    patientActionsPopover.value?.toggle(
        event,
    )
}

function openPatientDocuments(
    patientId: number,
) {
    patientActionsPopover.value?.hide()

    void openPatientDocumentsModal(
        patientId,
    )
}

function openEditPatient(
    patientId: number,
) {
    patientActionsPopover.value?.hide()

    void openPatientEditModal(
        patientId,
    )
}

function openScanDocument(
    patientId: number | undefined,
    branchId: number | null,
) {
    if (
        patientId &&
        branchId
    ) {
        void openScanDocumentModal(
            patientId,
            branchId,
        )
    }
}

/* -------------------------------------------------------------------------- */
/* Close patient                                                              */
/* -------------------------------------------------------------------------- */

const isHovered = ref(false)

function closePatient() {
    isHovered.value = false

    patientStore.clear()

    router.push({
        name: 'patients',
    })
}

/* -------------------------------------------------------------------------- */
/* Incomplete info modal                                                      */
/* -------------------------------------------------------------------------- */

const showIncompleteModal = ref(false)

const incompletePatientId =
    ref<number | null>(null)

function patientIsComplete(
    patient: PatientWithCoverage | null,
) {
    if (!patient) {
        return true
    }

    const first =
        String(
            patient.first_name ?? '',
        ).trim()

    const last =
        String(
            patient.last_name ?? '',
        ).trim()

    const personalNumber =
        String(
            patient.personal_number ?? '',
        ).trim()

    const sex =
        patient.sex ?? null

    const doctorId =
        patient.doctor_id ?? null

    const coverage =
        patient.coverage ?? null

    const insuranceId =
        coverage?.insurance_company_id ??
        patient.insurance_company_id ??
        null

    const street =
        String(
            patient.address ?? '',
        ).trim()

    const city =
        String(
            patient.city ?? '',
        ).trim()

    const zip =
        String(
            patient.zip ?? '',
        ).trim()

    const lat =
        patient.latitude

    const lng =
        patient.longitude

    if (
        !first ||
        !last ||
        !sex ||
        !doctorId ||
        !coverage
    ) {
        return false
    }

    if (!insuranceId) {
        return false
    }

    if (
        coverage.identification_method ===
            'slovak_identifier' &&
        !personalNumber
    ) {
        return false
    }

    if (
        coverage.identification_method ===
        'foreign_triad'
    ) {
        const state =
            String(
                coverage.member_state_code ??
                    '',
            ).trim()

        const foreignId =
            String(
                coverage.foreign_insured_id ??
                    '',
            ).trim()

        if (
            !state ||
            !foreignId
        ) {
            return false
        }
    }

    if (
        !coverage.identification_method
    ) {
        return false
    }

    if (
        coverage.category ===
            'special' &&
        !coverage.special_category
    ) {
        return false
    }

    if (
        !street &&
        !city &&
        !zip
    ) {
        return false
    }

    if (
        lat == null ||
        lng == null
    ) {
        return false
    }

    return true
}

watch(
    () => patientStore.current,

    (currentPatient) => {
        if (
            !currentPatient?.id
        ) {
            showIncompleteModal.value =
                false

            incompletePatientId.value =
                null

            return
        }

        if (
            !patientIsComplete(
                currentPatient,
            )
        ) {
            incompletePatientId.value =
                currentPatient.id

            showIncompleteModal.value =
                true
        } else {
            showIncompleteModal.value =
                false

            incompletePatientId.value =
                null
        }
    },

    {
        immediate: true,
        deep: true,
    },
)
</script>

<template>
    <Menubar
        v-if="patient"
        v-bind="attrs"
        class="
            bg-tag2!
            px-3!
            py-2
            flex
            items-center
            justify-between
        "
    >
        <!-- Patient information -->
        <template #start>
            <div class="flex items-center gap-2">
                <h2
                    class="
                        text-normal!
                        text-white!
                    "
                >
                    {{ patientName }}
                </h2>

    
                <span
                    class="
                        px-2
                        text-normal!
                        text-white!
                        bg-darkgrey!
                        rounded-md!
                    "
                >
                    {{ patientIdentifier }}
                </span>

                <button
                    type="button"
                    class="
                        flex
                        h-7
                        shrink-0
                        items-center
                        justify-center
                        text-white
                        cursor-pointer
                        transition-colors
                        duration-200
                    "
                    aria-label="Možnosti pacienta"
                    title="Možnosti pacienta"
                    @click="togglePatientActions"
                >
                    <i
                        class="
                            bi
                            bi-three-dots-vertical
                            text-normal
                        "
                    />
                </button>
            </div>
        </template>

        <!-- Patient navigation -->
        <template #end>
            <div
                class="
                    flex
                    items-center
                    gap-2
                "
            >
                <RouterLink
                    :to="{
                        path: '/patient/points',
                    }"
                    class="
                        text-mini!
                        underline
                        px-2!
                        pr-4!
                        transition-colors
                        text-almostwhite!
                        hover:text-accent!
                        border-r
                        border-almostwhite!
                    "
                >
                    bodovanie
                </RouterLink>

                <button
                    type="button"
                    class="
                        text-mini!
                        underline
                        px-2!
                        transition-colors
                        text-almostwhite!
                        hover:text-accent!
                        cursor-pointer
                    "
                    @click="
                        openScanDocument(
                            patient?.id,
                            currentBranchId,
                        )
                    "
                >
                    nález
                </button>

                <RouterLink
                    :to="{
                        path: '/patient/agreement',
                    }"
                    class="
                        text-mini!
                        underline
                        px-2!
                        transition-colors
                        text-almostwhite!
                        hover:text-accent!
                    "
                >
                    dohoda
                </RouterLink>

                <RouterLink
                    :to="{
                        path: '/patient/proposal',
                    }"
                    class="
                        text-mini!
                        underline
                        px-2!
                        transition-colors
                        text-almostwhite!
                        hover:text-accent!
                    "
                >
                    návrh
                </RouterLink>

                <RouterLink
                    :to="{
                        path: '/patient/dekurz',
                    }"
                    class="
                        text-mini!
                        underline
                        px-2!
                        transition-colors
                        text-almostwhite!
                        hover:text-accent!
                    "
                >
                    dekurz
                </RouterLink>

                <RouterLink
                    :to="{
                        path: '/patient/record',
                    }"
                    class="
                        text-mini!
                        underline
                        px-2!
                        transition-colors
                        text-almostwhite!
                        hover:text-accent!
                    "
                >
                    ošetrovateľský záznam
                </RouterLink>

                <RouterLink
                    :to="{
                        path: '/patient/leave',
                    }"
                    class="
                        text-mini!
                        underline
                        px-2!
                        pr-0
                        transition-colors
                        text-almostwhite!
                        hover:text-accent!
                    "
                >
                    prepúšťacia správa
                </RouterLink>
            </div>
        </template>
    </Menubar>

    <!-- Patient actions popover -->
    <Popover 
        ref="patientActionsPopover" 
        :pt="{
            content: {
                class: 'p-1!',
            }
        }"
    >
        <div class="flex w-44 flex-col gap-2">
            <AppButton
                type="button"
                variant="secondary"
                icon="bi bi-pencil"
                label="Upraviť"
                class=" justify-start!"
                @click="
                    openEditPatient(
                        patient!.id,
                    )
                "
            />

            <AppButton
                type="button"
                variant="secondary"
                icon="bi bi-folder2-open"
                label="Dokumenty"
                class="w-full! justify-start!"
                @click="
                    openPatientDocuments(
                        patient!.id,
                    )
                "
            />
        </div>
    </Popover>

    <!-- Incomplete patient warning -->
    <Dialog
        v-model:visible="
            showIncompleteModal
        "
        modal
        header="Ajaj"
        :style="{
            width: '420px',
            maxWidth: '95vw',
        }"
    >
        <div
            class="
                flex
                flex-col
                gap-4
            "
        >
            <p class="text-normal">
                Zdá sa, že informácie o tomto
                pacientovi nie sú kompletné alebo
                správne.
            </p>

            <div
                class="
                    flex
                    justify-end
                    gap-2
                "
            >
                <AppButton
                    v-if="incompletePatientId"
                    label="Upraviť teraz"
                    @click="
                        openEditPatient(
                            incompletePatientId,
                        )
                    "
                />
            </div>
        </div>
    </Dialog>
</template>