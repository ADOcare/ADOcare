<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { usePatientStore } from '@/stores/patientStore';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToast } from 'primevue/usetoast';
import PatientForm from './PatientForm.vue';
import PatientEffectiveDateDialog from './PatientEffectiveDateDialog.vue';
import PatientHistoryDialog from './PatientHistoryDialog.vue';
import type { IModalContentProps } from '@/types/ui';
import usePatientFormValidation from '@/composables/usePatientFormValidation';
import type { Patient } from '@/types/models';
import {
    detectPatientHistoryChanges,
    type PatientHistoryChangeGroup,
} from '@/composables/patientHistoryChanges';
import {
    normalizePatientCoverage,
    type PatientWithCoverage,
} from '@/composables/patientCoverage';
import AppButton from '@/components/AppButton.vue';


const patientStore = usePatientStore();
const authStore = useAuthStore();
const props = defineProps<IModalContentProps & { patientId: number; isManagerView: boolean; }>();

const patient = ref<PatientWithCoverage>(normalizePatientCoverage());
const originalPatient = ref<PatientWithCoverage | null>(null);
const pendingChanges = ref<PatientHistoryChangeGroup[]>([]);
const showEffectiveDateDialog = ref(false);
const showHistoryDialog = ref(false);
const { submitted, errors, validateForm, clearError } = usePatientFormValidation(patient);

function clonePatient(source: PatientWithCoverage): PatientWithCoverage {
    return JSON.parse(JSON.stringify(source)) as PatientWithCoverage;
}

onMounted(async () => {
    try {
        const fetched = await api.fetchEntity<Patient>(`v1/patients/${props.patientId}`, {
            with: ['nurse', 'doctor', 'insuranceCompany', 'currentCoverage.insuranceCompany'],
        });
        patient.value = normalizePatientCoverage(fetched);
        originalPatient.value = clonePatient(patient.value);

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

    const changes = originalPatient.value
        ? detectPatientHistoryChanges(originalPatient.value, patient.value)
        : [];
    if (changes.length === 0) {
        await savePatient();
        return;
    }

    pendingChanges.value = changes;
    showEffectiveDateDialog.value = true;
};

const savePatient = async (history?: {
    change_type: 'change' | 'correction';
    effective_date: string;
    effective_dates: Partial<Record<string, string>>;
    change_reason: string | null;
}) => {
    if (!patient.value.first_name || !patient.value.last_name) {
        toast.add({ severity: 'warn', summary: 'Neúplné údaje', detail: 'Meno a priezvisko sú povinné.', life: 3500 });
        return;
    }

    try {
        const payload = {
            ...patient.value,
            effective_date: history?.effective_date ?? null,
            effective_dates: history?.effective_dates ?? {},
            change_type: history?.change_type ?? 'change',
            change_reason: history?.change_reason ?? null,
        };
        const fresh = await patientStore.persistPatientData(payload)
        patient.value = normalizePatientCoverage(fresh);
        originalPatient.value = clonePatient(patient.value);

        if (patient.value.coverage.category === 'domestic') {
            try {
                await patientStore.checkPatientInsurance(patient.value.id)
            } catch (error) {
                console.error('Failed to re-check saved patient insurance', error)
            }
        }

        toast.add({ severity: 'success', summary: 'Pacient uložený', detail: `Pacient ${patient.value.first_name} bol úspešne uložený.`, life: 3000 });
        if (props.modalResolve) {
            props.modalResolve(patient.value);
        }
    } catch (error: any) {
        console.error('Failed to save patient', error);
        const personalNumberError = error?.response?.data?.errors?.personal_number?.[0];
        const validationError = Object.values(error?.response?.data?.errors ?? {})
            .flat()
            .find((message): message is string => typeof message === 'string');

        toast.add({
            severity: 'error',
            summary: personalNumberError ? 'Pacient už existuje' : 'Chyba',
            detail: personalNumberError
                ?? validationError
                ?? error?.response?.data?.message
                ?? 'Nepodarilo sa uložiť pacienta. Skúste to znova.',
            life: personalNumberError ? 10000 : 5000,
        });
    }

};

async function confirmEffectiveDates(configuration: {
    change_type: 'change' | 'correction';
    effective_date: string;
    effective_dates: Partial<Record<string, string>>;
    change_reason: string | null;
}) {
    showEffectiveDateDialog.value = false;
    await savePatient(configuration);
}

function onCancel() {
    props.modalResolve(null);
}


// Branch/nurse assignment is now handled by PatientForm when the user is allowed.

</script>


<template>

    <PatientForm v-if="patient?.id" v-model:patient="patient" :submitted="submitted"
        :errors="errors" @clear-error="clearError"
        :allow-assignment-editing="props.isManagerView || authStore.isSuperadmin" />

    <PatientEffectiveDateDialog
        v-if="patient?.id"
        v-model:visible="showEffectiveDateDialog"
        :patient-id="patient.id"
        :changes="pendingChanges"
        @confirm="confirmEffectiveDates"
    />

    <PatientHistoryDialog
        v-if="patient?.id"
        v-model:visible="showHistoryDialog"
        :patient-id="patient.id"
        :allow-assignment-editing="props.isManagerView || authStore.isSuperadmin"
    />

    <div class="mt-6 flex items-center justify-between gap-3">
        <AppButton
            type="button"
            label="História zmien"
            icon="bi bi-clock-history"
            variant="dark"
            aria-label="História zmien"
            @click="showHistoryDialog = true"
            :expand-on-hover="true"
        />

        <div class="flex items-center gap-2">
            <AppButton
                type="button"
                label="Zrušiť"
                variant="secondary"
                aria-label="Zrušiť"
                @click="onCancel"
            />
            <AppButton
                type="button"
                label="Uložiť"
                variant="accent"
                aria-label="Uložiť"
                @click="onSubmit"
            />
        </div>
    </div>
</template>
