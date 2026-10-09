import type { Patient } from '@/types/models'

export type PatientCoverageCategory = 'domestic' | 'eu' | 'special'
export type PatientSpecialCategory = 'homeless' | 'non_eu_foreigner' | 'statutory_entitlement_9_3'
export type PatientIdentificationMethod = 'slovak_identifier' | 'foreign_triad'

export type PatientCoverageForm = {
    id?: number
    category: PatientCoverageCategory | null
    identification_method: PatientIdentificationMethod | null
    insurance_company_id: number | null
    member_state_code: string | null
    foreign_insured_id: string | null
    special_category: PatientSpecialCategory | null
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
        category: null,
        identification_method: null,
        insurance_company_id: null,
        member_state_code: null,
        foreign_insured_id: null,
        special_category: null,
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
