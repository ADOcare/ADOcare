export interface PatientPointApi {
    id: number
    date: string | null
    patient_personal_number: string | null
    patient_name: string | null
    patient_id: number
    diagnosis_code: string | null
    diagnosis_id: number | null
    procedure_code: string | null
    procedure_id: number | null
    reference_date: string | null
    user_id: number
    branch_id: number
    quantity: number | null
    doctor_id: number | null
    doctor_pzs: string | null
    doctor_zpr: string | null
}

const sharedFields: [keyof PatientPointApi, string][] = [
    ['patient_id', 'pacient'], ['branch_id', 'prevádzka'], ['user_id', 'sestra'],
    ['diagnosis_id', 'diagnóza'], ['diagnosis_code', 'diagnóza'],
    ['procedure_id', 'výkon'], ['procedure_code', 'výkon'],
    ['quantity', 'počet'], ['reference_date', 'dátum odporučenia'],
    ['doctor_id', 'lekár'], ['doctor_pzs', 'kód PZS lekára'], ['doctor_zpr', 'kód lekára'],
]

export function batchDifferences(rows: PatientPointApi[]): string[] {
    const first = rows[0]
    if (!first) return []
    return [...new Set(sharedFields
        .filter(([field]) => rows.some((row) => row[field] !== first[field]))
        .map(([, label]) => label))]
}

export function canBatchEdit(rows: PatientPointApi[]): boolean {
    return rows.length >= 1 && batchDifferences(rows).length === 0
}
