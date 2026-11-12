<script setup lang="ts">
import { computed } from 'vue'

import type { PatientCoverageSource } from '@/composables/patientCoverage'
import PatientForm from './PatientForm.vue'

type HistoryStatus = 'known' | 'unknown' | 'missing' | 'conflict'

type HistoricalValue = {
    status: HistoryStatus
    values: Record<string, unknown> | null
    display_values?: Record<string, unknown> | null
    valid_from: string | null
    valid_to: string | null
}

const props = withDefaults(defineProps<{
    state: Record<string, HistoricalValue | number | string> | null
    changedCategories?: string[]
    changedFields?: Record<string, string[]>
    allowAssignmentEditing?: boolean
}>(), {
    changedCategories: () => [],
    changedFields: () => ({}),
})

const formFieldsByCategory: Record<string, string[]> = {
    name: ['first_name', 'last_name', 'title'],
    identity: ['personal_number', 'sex'],
    insurance: [
        'category',
        'identification_method',
        'insurance_company_id',
        'member_state_code',
        'foreign_insured_id',
        'special_category',
        'is_verified',
    ],
    doctor: ['doctor_id'],
    nurse: ['nurse_id'],
    branch: ['branch_id'],
    address: ['address', 'city', 'zip', 'country_id', 'latitude', 'longitude'],
    contact: ['contact', 'country_code_phone', 'phone'],
}

const patient = computed<PatientCoverageSource | null>(() => {
    if (!props.state) return null

    const values: Record<string, unknown> = {}
    for (const category of ['name', 'identity', 'contact', 'address', 'doctor', 'nurse', 'branch']) {
        Object.assign(values, historyValues(category))
    }

    return {
        ...values,
        id: Number(props.state.patient_id ?? props.state.id ?? 0),
        coverage: historyValues('insurance'),
    } as unknown as PatientCoverageSource
})

const highlightedFields = computed<Record<string, string[]>>(() => {
    const categories = new Set([
        ...props.changedCategories,
        ...Object.keys(props.changedFields),
    ])

    return Object.fromEntries([...categories].map((category) => {
        const formFields = formFieldsByCategory[category] ?? []
        const changed = (props.changedFields[category] ?? [])
            .filter((field) => formFields.includes(field))

        return [category, changed.length > 0 ? changed : formFields]
    }))
})

const assignmentDisplayValues = computed(() => ({
    branch: historyDisplayValue('branch', 'branch_id'),
    nurse: historyDisplayValue('nurse', 'nurse_id'),
}))

function historyValues(category: string): Record<string, unknown> {
    const entry = props.state?.[category]
    if (!entry || typeof entry !== 'object' || !('status' in entry)) return {}
    if (entry.status === 'missing' || entry.status === 'conflict') return {}
    return entry.values ?? {}
}

function historyDisplayValue(category: string, field: string): string | undefined {
    const entry = props.state?.[category]
    if (!entry || typeof entry !== 'object' || !('status' in entry)) return undefined
    const value = entry.display_values?.[field]
    return value === null || value === undefined || value === '' ? undefined : String(value)
}
</script>

<template>
    <div v-if="patient" inert>
        <PatientForm
            :patient="patient"
            :highlighted-fields="highlightedFields"
            :allow-assignment-editing="allowAssignmentEditing"
            :assignment-display-values="assignmentDisplayValues"
            show-assignments
        />
    </div>
</template>
