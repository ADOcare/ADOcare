<script setup lang="ts">
import { ref } from 'vue'
import { usePatientStore } from '@/stores/patientStore'
import { useToast } from 'primevue/usetoast'
import PatientForm from './PatientForm.vue'
import type { IModalContentProps } from '@/types/ui'
import useAuthStore from '@/stores/auth'
import usePatientFormValidation from '@/composables/usePatientFormValidation'
import {
    createEmptyPatientCoverage,
    normalizePatientCoverage,
    type PatientWithCoverage,
} from '@/composables/patientCoverage'

const patientStore = usePatientStore()
const authStore = useAuthStore()
const toast = useToast()

const props = defineProps<IModalContentProps>()

const patient = ref<PatientWithCoverage>({
    coverage: createEmptyPatientCoverage(),
} as PatientWithCoverage)

// validation handled by composable
const { submitted, errors, validateForm, clearError } = usePatientFormValidation(patient)

const createPatient = async () => {
    // basic validation
    if (!patient.value.first_name || !patient.value.last_name) return

    try {
        const branchId = authStore.currentBranch?.id ?? null
        if (!branchId) {
            throw new Error('Nie ste priradený k žiadnej pobočke. Skúste sa znovu prihlásiť.')
        }

        // ✅ IMPORTANT: use returned created patient (includes id)
        const created = await patientStore.createPatient(patient.value, branchId)

        // ✅ update local state + store
        patient.value = normalizePatientCoverage(created)
        patientStore.setPatient(created)

        toast.add({
            severity: 'success',
            summary: 'Pacient vytvorený',
            detail: `Pacient ${created.first_name} bol úspešne vytvorený.`,
            life: 5000,
        })

        if (props.modalResolve) {
            props.modalResolve(patient.value)
        }
    } catch (e) {
        console.error('Failed to save patient', e)
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Nepodarilo sa uložiť pacienta. Skúste to znova.',
            life: 5000,
        })
    }
}
</script>

<template>
    <PatientForm v-if="patient" v-model:patient="patient" :submitted="submitted" :errors="errors"
        @clear-error="clearError" :allow-assignment-editing="authStore.isManager || authStore.isSuperadmin" />

    <div class="mt-4 flex justify-end">
        <Button label="Uložiť" class="bg-accent! px-md! text-white! hover:bg-darkgrey! border-0!"
            @click="(async () => { submitted = true; if (!validateForm()) return; await createPatient(); })()" />
    </div>
</template>
