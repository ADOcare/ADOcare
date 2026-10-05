export type PatientIdentifierSource = {
    personal_number?: string | null
    coverage?: {
        identification_method?: 'slovak_identifier' | 'foreign_triad' | 'incomplete' | null
        foreign_insured_id?: string | null
    } | null
}

export function getPatientIdentifier(patient: PatientIdentifierSource | null | undefined): string {
    if (!patient) {
        return ''
    }

    if (patient.coverage?.identification_method === 'foreign_triad') {
        return String(patient.coverage.foreign_insured_id ?? '').trim()
    }

    return String(patient.personal_number ?? '').trim()
}
