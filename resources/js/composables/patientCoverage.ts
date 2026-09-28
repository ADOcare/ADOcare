import type { Patient } from '@/types/models'

export type PatientCoverageRegime = 'domestic' | 'eu' | 'special' | 'unclassified'
export type PatientCoverageCategory = 'domestic' | 'eu' | 'non_eu' | 'homeless' | 'other'
export type PatientIdentificationMethod = 'slovak_identifier' | 'foreign_triad' | 'incomplete'

export type PatientCoverageForm = {
    id?: number
    regime: PatientCoverageRegime | null
    category: PatientCoverageCategory | null
    identification_method: PatientIdentificationMethod | null
    insurance_company_id: number | null
    member_state_code: string | null
    foreign_insured_id: string | null
    special_category: string | null
    other_subtype: string | null
    legal_basis: string | null
    entitlement_confirmed: boolean
    document_registered: boolean
    entitlement_document_type: string | null
    entitlement_document_number: string | null
    valid_from: string | null
    valid_to: string | null
    is_verified: boolean
}

export type PatientCoverageSource = Patient & {
    coverage?: PatientCoverageForm | null
    insurance_company_id?: number | null
    country_id?: number | null
}

export type PatientWithCoverage = Patient & {
    coverage: PatientCoverageForm
}

export function createEmptyPatientCoverage(): PatientCoverageForm {
    return {
        regime: null,
        category: null,
        identification_method: null,
        insurance_company_id: null,
        member_state_code: null,
        foreign_insured_id: null,
        special_category: null,
        other_subtype: null,
        legal_basis: null,
        entitlement_confirmed: false,
        document_registered: false,
        entitlement_document_type: null,
        entitlement_document_number: null,
        valid_from: null,
        valid_to: null,
        is_verified: false,
    }
}

export function normalizePatientCoverage(patient?: PatientCoverageSource): PatientWithCoverage {
    const source = patient ?? ({} as PatientCoverageSource)
    const {
        country_id: _countryId,
        insurance_company_id: legacyInsuranceCompanyId,
        ...patientWithoutLegacyFields
    } = source

    return {
        ...patientWithoutLegacyFields,
        coverage: {
            ...createEmptyPatientCoverage(),
            ...(source.coverage ?? {}),
            insurance_company_id:
                source.coverage?.insurance_company_id
                ?? legacyInsuranceCompanyId
                ?? null,
        },
    } as PatientWithCoverage
}
