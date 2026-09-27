export type CaseStatus = 'borrador' | 'activo' | 'archivado'
export type ProcessingStatus = 'pendiente' | 'procesando' | 'procesado' | 'error'

export interface CaseFile {
  id: number
  name: string
  mime_type: string
  extension: string
  size_bytes: number
  page_count: number | null
  requires_ocr: boolean
  processing_status: ProcessingStatus
  processing_message: string | null
  created_at: string
}

export interface FileExtractionPage {
  id: number
  page_number: number
  locator: string
  text: string | null
  used_ocr: boolean
  confidence: number | null
  is_readable: boolean
}

export interface LegalCase {
  id: number
  title: string
  description: string | null
  case_number: string | null
  status: CaseStatus
  processing_status: ProcessingStatus
  file_count: number
  created_at: string
  updated_at: string | null
  files?: CaseFile[]
}

export interface Paginated<T> {
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}
