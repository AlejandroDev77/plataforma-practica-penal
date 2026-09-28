export type AnalysisCertainty = 'textual' | 'inferido' | 'incierto'
export type AnalysisReviewDecision = 'aprobado' | 'requiere_cambios'

export interface AnalysisCitation {
  file_name: string | null
  page_number: number | null
  locator: string | null
  excerpt: string | null
  available: boolean
}

export interface AnalysisFinding {
  text?: string
  certainty?: AnalysisCertainty
  sources?: AnalysisCitation[]
  context_sources?: AnalysisCitation[]
  name_as_written?: string
  role_as_written?: string | null
  label_as_written?: string
  article_as_written?: string | null
  description?: string | null
  kind?: string
  date_as_written?: string | null
  kind_as_written?: string | null
  status_as_written?: string | null
  title?: string
  question?: string
  relevance?: string
  issue?: string
  explanation?: string
}

export interface AnalysisReview {
  decision: AnalysisReviewDecision
  observation: string | null
  reviewed_at: string | null
  reviewer_name: string
}

export interface CaseAnalysis {
  id: number
  version: number
  status: string
  created_at: string | null
  review_state: 'pendiente' | AnalysisReviewDecision
  summary: AnalysisFinding | null
  procedural_stage: AnalysisFinding | null
  participants: AnalysisFinding[]
  offenses: AnalysisFinding[]
  facts: AnalysisFinding[]
  evidence: AnalysisFinding[]
  chronology: AnalysisFinding[]
  missing_information: AnalysisFinding[]
  uncertainties: AnalysisFinding[]
  reviews: AnalysisReview[]
}
