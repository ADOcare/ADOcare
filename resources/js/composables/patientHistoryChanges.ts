import type { PatientWithCoverage } from '@/composables/patientCoverage'

export type PatientHistoryCategory =
    | 'name'
    | 'identity'
    | 'contact'
    | 'address'
    | 'doctor'
    | 'nurse'
    | 'branch'
    | 'insurance'

export type PatientHistoryChangeGroup = {
    category: PatientHistoryCategory
    label: string
    previousValues: Record<string, unknown>
    newValues: Record<string, unknown>
    previousSummary: string
    newSummary: string
}

const groups: Array<{
    category: PatientHistoryCategory
    label: string
    fields: string[]
}> = [
    { category: 'name', label: 'Meno a titul', fields: ['title', 'first_name', 'last_name'] },
    { category: 'identity', label: 'Identifikačné údaje', fields: ['personal_number', 'sex'] },
    { category: 'contact', label: 'Kontaktné údaje', fields: ['contact', 'country_code_phone', 'phone'] },
    { category: 'address', label: 'Adresa', fields: ['address', 'city', 'zip', 'country_id', 'latitude', 'longitude'] },
    { category: 'doctor', label: 'Lekár', fields: ['doctor_id'] },
    { category: 'nurse', label: 'Priradená sestra', fields: ['nurse_id'] },
    { category: 'branch', label: 'Pobočka', fields: ['branch_id'] },
]

const coverageFields = [
    'insurance_company_id',
    'category',
    'identification_method',
    'member_state_code',
    'foreign_insured_id',
    'special_category',
]

function normalized(value: unknown): string {
    if (value === null || value === undefined) return ''
    if (typeof value === 'string') return value.trim()

    return String(value)
}

function valuesFor(source: Record<string, unknown>, fields: string[]) {
    return Object.fromEntries(fields.map((field) => [field, source[field] ?? null]))
}

function changed(previous: Record<string, unknown>, next: Record<string, unknown>) {
    return Object.keys(previous).some((field) => normalized(previous[field]) !== normalized(next[field]))
}

function summary(category: PatientHistoryCategory, values: Record<string, unknown>) {
    const text = (value: unknown) => normalized(value) || '—'

    switch (category) {
        case 'name':
            return [values.title, values.first_name, values.last_name].map(text).filter((value) => value !== '—').join(' ') || '—'
        case 'identity':
            return `${text(values.personal_number)} · ${text(values.sex)}`
        case 'contact':
            return [values.contact, `${text(values.country_code_phone)} ${text(values.phone)}`.trim()]
                .map(text).join(' · ')
        case 'address':
            return [values.address, values.city, values.zip].map(text).join(', ')
        case 'doctor':
            return values.doctor_id ? `ID ${values.doctor_id}` : '—'
        case 'nurse':
            return values.nurse_id ? `ID ${values.nurse_id}` : '—'
        case 'branch':
            return values.branch_id ? `ID ${values.branch_id}` : '—'
        case 'insurance':
            return values.insurance_company_id
                ? `Poisťovňa ID ${values.insurance_company_id}`
                : '—'
    }
}

export function detectPatientHistoryChanges(
    original: PatientWithCoverage,
    edited: PatientWithCoverage,
): PatientHistoryChangeGroup[] {
    const changes = groups.flatMap(({ category, label, fields }) => {
        const previousValues = valuesFor(original as unknown as Record<string, unknown>, fields)
        const newValues = valuesFor(edited as unknown as Record<string, unknown>, fields)

        return changed(previousValues, newValues)
            ? [{
                category,
                label,
                previousValues,
                newValues,
                previousSummary: summary(category, previousValues),
                newSummary: summary(category, newValues),
            }]
            : []
    })
    const previousCoverage = valuesFor(
        (original.coverage ?? {}) as unknown as Record<string, unknown>,
        coverageFields,
    )
    const newCoverage = valuesFor(
        (edited.coverage ?? {}) as unknown as Record<string, unknown>,
        coverageFields,
    )

    if (changed(previousCoverage, newCoverage)) {
        changes.push({
            category: 'insurance',
            label: 'Zdravotné poistenie',
            previousValues: previousCoverage,
            newValues: newCoverage,
            previousSummary: summary('insurance', previousCoverage),
            newSummary: summary('insurance', newCoverage),
        })
    }

    return changes
}
