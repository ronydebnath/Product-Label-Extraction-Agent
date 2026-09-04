export type UploadStatus = 'queued' | 'processing' | 'completed' | 'failed'

export interface Upload {
    id: string
    original_name: string
    kind: 'image' | 'pdf'
    size_bytes: number
    page_count: number | null
    status: UploadStatus
    attempts: number
    max_attempts: number
    failure_code: string | null
    /** The mapped, human-readable reason. Never an exception message. */
    message: string | null
    created_at: string
    completed_at: string | null
    failed_at: string | null
}

export interface Allergens {
    contains: string[]
    may_contain: string[]
}

export interface NetWeight {
    value: number
    unit: string
    raw: string
}

/** Mirrors LabelDataSchema. Every field is present; absent ones are null, never omitted. */
export interface LabelData {
    document_type: 'product_label' | 'product_spec_sheet' | 'other'
    product_name: string | null
    brand: string | null
    ingredients: string[] | null
    allergens: Allergens | null
    net_weight: NetWeight | null
    warnings: string[]
}

export interface Extraction {
    data: LabelData
    model: string
    prompt_version: number
    input_tokens: number | null
    output_tokens: number | null
    duration_ms: number | null
}

export interface UploadLimits {
    max_files: number
    max_file_bytes: number
    max_pdf_pages: number
    accepted_extensions: string[]
    accepted_mime_types: string[]
}

/** One file the server refused, reported against the name the user recognises. */
export interface RejectedFile {
    original_name: string
    code: string
    message: string
}
