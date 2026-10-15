export type DekurzTextSource = 'patient_template' | 'procedure_default' | 'empty' | 'manual'

export interface DekurzPatient {
    id: number
    full_name: string
    dekurz_number: string | number | null
}

export interface DekurzMonth {
    key: string
    start: string
    end: string
}

export interface DekurzProcedure {
    id: number
    code: string
    name: string
    scoring_rows_count: number
    effective_text: string
    effective_text_source: Exclude<DekurzTextSource, 'manual'>
    patient_template_text: string | null
    procedure_default_text: string | null
}

export interface DekurzEntry {
    id: number | null
    patient_point_snapshot_id: number
    patient_point_id: number | null
    procedure_id: number
    procedure_code: string
    procedure_name: string
    quantity: number
    points: number | string | null
    text: string
    source: DekurzTextSource
    edited_at: string | null
}

export interface DekurzDay {
    id: number | null
    date: string
    note: string | null
    entries: DekurzEntry[]
}

export interface DekurzSupportingDocument {
    key: 'finding' | 'proposal' | 'previous_dekurz'
    label: string
    status: 'found' | 'missing'
    document: {
        id: number
        name: string | null
        type: string
        date: string | null
        date_source: string | null
        preview_url: string
        download_url: string
    } | null
    candidates: Array<{
        id: number
        name: string | null
        type: string
        date: string | null
        date_source: string | null
    }>
}

export interface DekurzDraft {
    id: number
    status: 'draft' | 'completed'
    source_period_start: string
    source_period_end: string
    initialized_at: string | null
    completed_at: string | null
    final_document_id: number | null
}

export interface DekurzScoringChanges {
    added: number
    removed: number
    changed: number
    has_changes: boolean
}

export interface DekurzRawDocument {
    id: number
    name: string | null
    type: string
    document_date: string | null
    date_source: string | null
    preview_url: string
    download_url: string
}

export interface DekurzRawBuilderPayload {
    patient: { id: number; name: string; branch_id: number | null }
    month: string
    procedures: Array<{
        procedure_id: number
        code: string
        description: string | null
        total_quantity: number
        scoring_rows_count: number
        template: { text: string; source: 'patient_template' | 'procedure_default' | 'empty' }
    }>
    days: Array<{
        date: string
        entries: Array<{
            patient_point_id: number
            dekurz_entry_id: number | null
            procedure_id: number
            procedure_code: string
            quantity: number
            text: string
            source: 'patient_template' | 'procedure_default' | 'empty' | 'saved_draft'
            saved_source: DekurzTextSource | null
            edited_at: string | null
        }>
    }>
    supporting_documents: {
        finding: { default: DekurzRawDocument | null; candidates: DekurzRawDocument[] }
        proposal: { default: DekurzRawDocument | null; candidates: DekurzRawDocument[] }
        previous_dekurz: DekurzRawDocument | null
    }
    draft: {
        id: number
        status: 'draft' | 'completed'
        period: string
        completed_at: string | null
        final_document_id: number | null
        days: Array<{
            id: number
            date: string
            general_note: string | null
        }>
    } | null
    scoring_changes: {
        added: unknown[]
        removed: unknown[]
        changed: unknown[]
    }
}

export interface DekurzBuilderPayload {
    patient: DekurzPatient
    month: DekurzMonth
    procedures: DekurzProcedure[]
    days: DekurzDay[]
    supporting_documents: DekurzSupportingDocument[]
    draft: DekurzDraft | null
    scoring_changes: DekurzScoringChanges
}

export interface DekurzMacro {
    key: string
    title: string
    body: string
}
