export type ParticipantRole = 'abogado_defensor' | 'juez' | 'fiscal' | string

export interface SimulationTurn {
  configured: boolean
  complete: boolean
  order: number | null
  role: ParticipantRole | null
  allowed_actions: string[]
}

export interface SimulationStage {
  id: number
  code: string
  name: string
  is_final: boolean
  allowed_next_stages: Array<{ id: number; code: string; name: string }>
}

export interface HearingType {
  id: number
  code: string
  name: string
  description: string | null
  stages: Array<{ id: number; code: string; name: string; order: number; is_initial: boolean; is_final: boolean }>
}

export interface SimulationSource {
  id: number
  fragment_id: number
  excerpt: string | null
  source: {
    kind: string
    title: string | null
    category: string | null
    identifier: string | null
    version: string | null
    page: number | null
    locator: string | null
    valid_from: string | null
    valid_until: string | null
  }
}

export interface SimulationIntervention {
  id: number
  order: number
  content: string
  input_type: string
  stage_id: number
  created_at: string | null
  participant: { id: number; role: ParticipantRole; display_name: string } | null
  sources: SimulationSource[]
}

export interface Simulation {
  id: number
  case_id: number
  analysis_id: number
  user_role: ParticipantRole
  status: 'activa' | 'finalizada' | string
  current_turn: SimulationTurn | null
  created_at: string | null
  started_at: string | null
  finished_at: string | null
  hearing_type?: { id: number; code: string; name: string }
  current_stage?: SimulationStage | null
  participants?: Array<{ id: number; role: ParticipantRole; display_name: string; controlled_by: string; status: string }>
  interventions?: SimulationIntervention[]
}

export interface PaginatedSimulations {
  data: Simulation[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface InterventionProposal {
  proposal: {
    speaker_role: ParticipantRole
    content: string
    used_source_ids: number[]
    requires_human_review: true
  }
  sources: Array<{ id: number; kind: string; title: string; excerpt: string; locator: string }>
  meta: { provider: string | null; model: string | null }
}
