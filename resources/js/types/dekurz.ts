export type DekurzTextSource =
    | 'patient_template'
    | 'procedure_default'
    | 'custom_template'
    | 'previous_entry'
    | 'empty'
    | 'manual'

export type DekurzTrackType = 'procedure' | 'custom'
export type DekurzPropagationMode = 'current' | 'following' | 'selected'
export type DekurzCustomTrackDistribution = 'visit_days' | 'selected_days'

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
    dates: string[]
    effective_text: string
    effective_text_source: 'patient_template' | 'procedure_default' | 'empty'
    patient_template_text: string | null
    procedure_default_text: string | null
}

export interface DekurzCustomTrack {
    id: number
    name: string
    current_text: string
    distribution: DekurzCustomTrackDistribution
    selected_dates: string[]
}

export interface DekurzEntry {
    id: number | null
    track_type: DekurzTrackType
    track_key: string
    track_name: string
    procedure_id: number | null
    procedure_code: string | null
    procedure_name: string | null
    custom_track_id: number | null
    patient_point_ids: number[]
    patient_point_snapshot_ids: number[]
    quantity: number | null
    is_excluded: boolean
    text: string
    source: DekurzTextSource
    source_entry_id: number | null
    edited_at: string | null
}

export interface DekurzDay {
    id: number | null
    date: string
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


export interface DekurzExistingDocument {
    id: number
    name: string | null
    created_at: string | null
    preview_url: string
    download_url: string
}

export interface DekurzDraft {
    id: number
    status: 'draft' | 'completed'
    source_period_start: string
    source_period_end: string
    dekurz_number: number | null
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
    patient: {
        id: number
        name: string
        branch_id: number | null
        dekurz_number: number | string | null
    }
    month: string
    procedures: Array<{
        procedure_id: number
        code: string
        description: string | null
        total_quantity: number
        scoring_rows_count: number
        dates: string[]
        template: {
            text: string
            source: 'patient_template' | 'procedure_default' | 'empty'
            patient_template_text: string | null
            procedure_default_text: string | null
        }
    }>
    custom_tracks: Array<{
        id: number
        name: string
        current_text: string | null
        distribution: DekurzCustomTrackDistribution
        selected_dates: string[]
    }>
    days: Array<{
        id: number | null
        date: string
        general_note?: string | null
        entries: Array<{
            id: number | null
            track_type: DekurzTrackType
            track_key: string
            track_name: string
            procedure_id: number | null
            procedure_code: string | null
            procedure_name: string | null
            custom_track_id: number | null
            patient_point_ids: number[]
            patient_point_snapshot_ids: number[]
            quantity: number | null
            is_excluded: boolean
            text: string
            source: DekurzTextSource
            source_entry_id: number | null
            edited_at: string | null
        }>
    }>
    existing_documents: Array<{
        id: number
        name: string | null
        created_at: string | null
        preview_url: string
        download_url: string
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
        dekurz_number: number | null
        completed_at: string | null
        final_document_id: number | null
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
    custom_tracks: DekurzCustomTrack[]
    days: DekurzDay[]
    supporting_documents: DekurzSupportingDocument[]
    existing_documents: DekurzExistingDocument[]
    draft: DekurzDraft | null
    scoring_changes: DekurzScoringChanges
}

export interface DekurzMacro {
    key: string
    title: string
    body: string
}

export interface DekurzPropagationRequest {
    entry: DekurzEntry
    mode: DekurzPropagationMode
    target_entry_ids: number[]
    target_dates: string[]
    save_as_patient_template: boolean
}
