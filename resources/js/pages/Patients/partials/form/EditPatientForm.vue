<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePatientStore } from '@/stores/patientStore';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToast } from 'primevue/usetoast';
import PatientForm from './PatientForm.vue';
import PatientEffectiveDateDialog from './PatientEffectiveDateDialog.vue';
import PatientHistoryDialog from './PatientHistoryDialog.vue';
import type { IModalContentProps } from '@/types/ui';
import usePatientFormValidation from '@/composables/usePatientFormValidation';
import type { Branch, Doctor, Patient, User } from '@/types/models';
import {
    detectPatientHistoryChanges,
    type PatientHistoryChangeGroup,
} from '@/composables/patientHistoryChanges';
import {
    normalizePatientCoverage,
    type PatientWithCoverage,
} from '@/composables/patientCoverage';
import AppButton from '@/components/AppButton.vue';
import { formatBranchFullName, formatUserFullName } from '@/utils/formatUtils';


const patientStore = usePatientStore();
const authStore = useAuthStore();
const props = defineProps<IModalContentProps & { patientId: number; isManagerView: boolean; }>();
const canEditAssignments = computed(
    () => props.isManagerView
        || authStore.isManager
        || authStore.isSuperadmin
        || authStore.user?.role?.position === 'manager',
);

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
            with: ['nurse', 'branch', 'doctor', 'insuranceCompany', 'currentCoverage.insuranceCompany'],
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

    const detectedChanges = originalPatient.value
        ? detectPatientHistoryChanges(originalPatient.value, patient.value)
        : [];
    if (detectedChanges.length === 0) {
        await savePatient();
        return;
    }

    try {
        pendingChanges.value = await resolveAssignmentSummaries(detectedChanges);
        showEffectiveDateDialog.value = true;
    } catch (error) {
        console.error('Failed to resolve patient assignment names', error);
        toast.add({
            severity: 'error',
            summary: 'Chyba',
            detail: 'Názvy lekára, sestry alebo prevádzky sa nepodarilo načítať.',
            life: 5000,
        });
    }
};

async function resolveAssignmentSummaries(
    changes: PatientHistoryChangeGroup[],
): Promise<PatientHistoryChangeGroup[]> {
    return Promise.all(changes.map(async (change) => {
        const field = `${change.category}_id`;
        if (!['doctor', 'nurse', 'branch'].includes(change.category)) return change;

        const previousId = numericId(change.previousValues[field]);
        const newId = numericId(change.newValues[field]);
        const [previousSummary, newSummary] = await Promise.all([
            assignmentName(change.category, previousId, originalPatient.value),
            assignmentName(change.category, newId, patient.value),
        ]);

        return {
            ...change,
            previousSummary,
            newSummary,
        };
    }));
}

function numericId(value: unknown): number | null {
    const id = Number(value)
    return Number.isInteger(id) && id > 0 ? id : null
}

async function assignmentName(
    category: PatientHistoryChangeGroup['category'],
    id: number | null,
    source: PatientWithCoverage | null,
): Promise<string> {
    if (!id) return '—'

    if (category === 'doctor') {
        const doctor = source?.doctor?.id === id
            ? source.doctor
            : await api.fetchEntity<Doctor>(`v1/doctors/${id}`)
        return formatPersonName(doctor)
    }

    if (category === 'nurse') {
        const nurse = source?.nurse?.id === id
            ? source.nurse
            : await api.fetchEntity<User>(`v1/users/${id}`)
        return formatUserFullName(nurse)
    }

    if (category === 'branch') {
        const branch = source?.branch?.id === id
            ? source.branch
            : await api.fetchEntity<Branch>(`v1/branches/${id}`)
        return formatBranchFullName(branch)
    }

    throw new Error(`Unsupported assignment category: ${category}`)
}

function formatPersonName(person: Doctor): string {
    return [person.title, person.first_name, person.last_name]
        .map((part) => part?.trim())
        .filter(Boolean)
        .join(' ')
}

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
        :allow-assignment-editing="canEditAssignments" />

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
        :allow-assignment-editing="canEditAssignments"
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
