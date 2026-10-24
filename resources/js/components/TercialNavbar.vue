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

defineOptions({
    inheritAttrs: false,
})

const attrs = useAttrs()

const router = useRouter()

const patientStore =
    usePatientStore()

const authStore =
    useAuthStore()

const patient =
    computed<PatientWithCoverage | null>(
        () => patientStore.current,
    )

const currentBranchId =
    computed(
        () =>
            authStore.currentBranch
                ?.id ??
            null,
    )

const patientName =
    computed(() =>
        patient.value
            ? `${patient.value.first_name ?? ''} ${patient.value.last_name ?? ''}`.trim()
            : '',
    )

const patientIdentifier =
    computed(() =>
        getPatientIdentifier(
            patient.value,
        ),
    )

/* -------------------------------------------------------------------------- */
/* Patient actions                                                            */
/* -------------------------------------------------------------------------- */

function openPatientDocuments(
    patientId: number,
) {
    void openPatientDocumentsModal(
        patientId,
    )
}

function openEditPatient(
    patientId: number,
) {
    void openPatientEditModal(
        patientId,
    )
}

function openScanDocument(
    patientId:
        | number
        | undefined,
    branchId:
        | number
        | null,
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

const isHovered =
    ref(false)

function closePatient() {
    isHovered.value =
        false

    patientStore.clear()

    router.push({
        name:
            'patients',
    })
}

/* -------------------------------------------------------------------------- */
/* Incomplete info modal                                                      */
/* -------------------------------------------------------------------------- */

const showIncompleteModal =
    ref(false)

const incompletePatientId =
    ref<number | null>(
        null,
    )

function patientIsComplete(
    patient:
        | PatientWithCoverage
        | null,
) {
    if (!patient) {
        return true
    }

    const first =
        String(
            patient.first_name ??
                '',
        ).trim()

    const last =
        String(
            patient.last_name ??
                '',
        ).trim()

    const personalNumber =
        String(
            patient.personal_number ??
                '',
        ).trim()

    const sex =
        patient.sex ??
        null

    const doctorId =
        patient.doctor_id ??
        null

    const coverage =
        patient.coverage ??
        null

    const insuranceId =
        coverage
            ?.insurance_company_id ??
        patient
            .insurance_company_id ??
        null

    const street =
        String(
            patient.address ??
                '',
        ).trim()

    const city =
        String(
            patient.city ??
                '',
        ).trim()

    const zip =
        String(
            patient.zip ??
                '',
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

    if (
        !insuranceId
    ) {
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
    () =>
        patientStore.current,

    (
        currentPatient,
    ) => {
        if (
            !currentPatient
                ?.id
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
        immediate:
            true,

        deep:
            true,
    },
)
</script>

<template>
    <Menubar
        v-if="patient"
        v-bind="attrs"
        class="
            flex
            items-center
            justify-between
            bg-tag2!
            px-3!
            py-2
        "
    >
        <!-- ================================================================ -->
        <!-- PATIENT INFORMATION                                              -->
        <!-- ================================================================ -->

        <template #start>
            <div
                class="
                    flex
                    items-center
                    gap-2
                "
            >
                <h2
                    class="
                        text-normal!
                        text-white!
                    "
                >
                    {{
                        patientName
                    }}
                </h2>

                <span
                    class="
                        rounded-md!
                        bg-darkgrey!
                        px-2
                        text-normal!
                        text-white!
                    "
                >
                    {{
                        patientIdentifier
                    }}
                </span>

                <!-- Edit patient -->

                <button
                    type="button"
                    class="
                        flex
                        h-7
                        w-7
                        shrink-0
                        cursor-pointer
                        items-center
                        justify-center
                        text-white
                        transition-colors
                        duration-200
                        hover:text-lightgrey
                    "
                    aria-label="
                        Upraviť pacienta
                    "
                    title="
                        Upraviť pacienta
                    "
                    @click="
                        openEditPatient(
                            patient.id,
                        )
                    "
                >
                    <i
                        class="
                            bi
                            bi-pencil
                            text-normal
                        "
                    />
                </button>

                <!-- Patient documents -->

                <button
                    type="button"
                    class="
                        flex
                        h-7
                        w-7
                        shrink-0
                        cursor-pointer
                        items-center
                        justify-center
                        rounded-md
                        text-white
                        transition-colors
                        duration-200
                        hover:text-lightgrey
                    "
                    aria-label="
                        Dokumenty pacienta
                    "
                    title="
                        Dokumenty pacienta
                    "
                    @click="
                        openPatientDocuments(
                            patient.id,
                        )
                    "
                >
                    <i
                        class="
                            bi
                            bi-folder2-open
                            text-normal
                        "
                    />
                </button>
            </div>
        </template>

        <!-- ================================================================ -->
        <!-- PATIENT NAVIGATION                                               -->
        <!-- ================================================================ -->

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
                        path:
                            '/patient/points',
                    }"
                    class="
                        border-r
                        border-almostwhite!
                        px-2!
                        pr-4!
                        text-mini!
                        text-almostwhite!
                        underline
                        transition-colors
                        hover:text-lightgrey!
                    "
                >
                    bodovanie
                </RouterLink>

                <button
                    type="button"
                    class="
                        cursor-pointer
                        px-2!
                        text-mini!
                        text-almostwhite!
                        underline
                        transition-colors
                        hover:text-lightgrey!
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
                        path:
                            '/patient/agreement',
                    }"
                    class="
                        px-2!
                        text-mini!
                        text-almostwhite!
                        underline
                        transition-colors
                        hover:text-lightgrey!
                    "
                >
                    dohoda
                </RouterLink>

                <RouterLink
                    :to="{
                        path:
                            '/patient/proposal',
                    }"
                    class="
                        px-2!
                        text-mini!
                        text-almostwhite!
                        underline
                        transition-colors
                        hover:text-lightgrey!
                    "
                >
                    návrh
                </RouterLink>

                <RouterLink
                    :to="{
                        path:
                            '/patient/dekurz',
                    }"
                    class="
                        px-2!
                        text-mini!
                        text-almostwhite!
                        underline
                        transition-colors
                        hover:text-lightgrey!
                    "
                >
                    dekurz
                </RouterLink>

                <RouterLink
                    :to="{
                        path:
                            '/patient/record',
                    }"
                    class="
                        px-2!
                        text-mini!
                        text-almostwhite!
                        underline
                        transition-colors
                        hover:text-lightgrey!
                    "
                >
                    ošetrovateľský záznam
                </RouterLink>

                <RouterLink
                    :to="{
                        path:
                            '/patient/leave',
                    }"
                    class="
                        px-2!
                        pr-0
                        text-mini!
                        text-almostwhite!
                        underline
                        transition-colors
                        hover:text-lightgrey!
                    "
                >
                    prepúšťacia správa
                </RouterLink>
            </div>
        </template>
    </Menubar>
</template>