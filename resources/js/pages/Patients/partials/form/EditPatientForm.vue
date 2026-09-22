<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { usePatientStore } from '@/stores/patientStore';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToast } from 'primevue/usetoast';
import PatientForm from './PatientForm.vue';
import type { IModalContentProps } from '@/types/ui';
import usePatientFormValidation from '@/composables/usePatientFormValidation';
import type { Patient } from '@/types/models';
import {
    normalizePatientCoverage,
    type PatientWithCoverage,
} from '@/composables/patientCoverage';


const patientStore = usePatientStore();
const authStore = useAuthStore();
const props = defineProps<IModalContentProps & { patientId: number; isManagerView: boolean; }>();

const patient = ref<PatientWithCoverage>(normalizePatientCoverage());
const { submitted, errors, validateForm, clearError } = usePatientFormValidation(patient);

onMounted(async () => {
    try {
        const fetched = await api.fetchEntity<Patient>(`v1/patients/${props.patientId}`, {
            with: ['nurse', 'doctor', 'insuranceCompany', 'currentCoverage.insuranceCompany'],
        });
        patient.value = normalizePatientCoverage(fetched);

    } catch (e) {
        console.error('Failed to fetch patient', e);
    }
});

const toast = useToast();

const onSubmit = async () => {
    submitted.value = true;

    if (!validateForm()) {
        const firstError = Object.values(errors.value ?? {})[0] ?? 'Skontrolujte prosím formulár.';
        toast.add({ severity: 'warn', summary: 'Neúplné údaje', detail: firstError, life: 3500 });
        return;
    }

    await savePatient();
};

const savePatient = async () => {
    if (!patient.value.first_name || !patient.value.last_name) {
        toast.add({ severity: 'warn', summary: 'Neúplné údaje', detail: 'Meno a priezvisko sú povinné.', life: 3500 });
        return;
    }

    try {
        const fresh = await patientStore.persistPatientData(patient.value)
        patient.value = normalizePatientCoverage(fresh);
        toast.add({ severity: 'success', summary: 'Pacient uložený', detail: `Pacient ${patient.value.first_name} bol úspešne uložený.`, life: 3000 });
        if (props.modalResolve) {
            props.modalResolve(patient.value);
        }
    } catch (e) {
        console.error('Failed to save patient', e);
        toast.add({ severity: 'error', summary: 'Chyba', detail: 'Nepodarilo sa uložiť pacienta. Skúste to znova.', life: 5000 });
    }

};

// Branch/nurse assignment is now handled by PatientForm when the user is allowed.

</script>


<template>
    <PatientForm :disabled="props.isManagerView" v-if="patient?.id" v-model:patient="patient" :submitted="submitted"
        :errors="errors" @clear-error="clearError"
        :allow-assignment-editing="props.isManagerView || authStore.isSuperadmin" />

    <div class="mt-4 flex justify-end">
        <Button label="Uložiť" class="bg-accent! px-md! text-white! hover:bg-darkgrey! border-0!"
            @click="onSubmit" />
    </div>
</template>
