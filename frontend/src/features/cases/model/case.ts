export type CaseStatus = 'draft' | 'in_review' | 'ready' | 'archived'

export interface LegalCase {
  id: string
  reference: string
  title: string
  status: CaseStatus
  updatedAt: string
}
