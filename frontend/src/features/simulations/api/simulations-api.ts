import { httpClient } from '../../../shared/api/http-client'
import type { HearingType, InterventionProposal, PaginatedSimulations, Simulation, SimulationEvaluation } from '../model/simulation'

export async function listHearingTypes(): Promise<HearingType[]> {
  const response = await httpClient.get<{ data: HearingType[] }>('/api/v1/tipos-audiencia')
  return response.data.data
}

export async function listCaseSimulations(caseId: number): Promise<PaginatedSimulations> {
  const response = await httpClient.get<PaginatedSimulations>(`/api/v1/expedientes/${caseId}/simulaciones`)
  return response.data
}

export async function createSimulation(caseId: number, analysisId: number, hearingTypeId: number): Promise<Simulation> {
  const response = await httpClient.post<{ data: Simulation }>(`/api/v1/expedientes/${caseId}/simulaciones`, {
    id_analisis: analysisId,
    id_tipo_audiencia: hearingTypeId,
  })
  return response.data.data
}

export async function getSimulation(id: number): Promise<Simulation> {
  const response = await httpClient.get<{ data: Simulation }>(`/api/v1/simulaciones/${id}`)
  return response.data.data
}

export async function addIntervention(id: number, content: string): Promise<Simulation> {
  const response = await httpClient.post<{ data: Simulation }>(`/api/v1/simulaciones/${id}/intervenciones`, { contenido: content })
  return response.data.data
}

export async function requestInterventionProposal(id: number): Promise<InterventionProposal> {
  const response = await httpClient.post<{ data: InterventionProposal }>(`/api/v1/simulaciones/${id}/propuesta-intervencion`)
  return response.data.data
}

export async function getLatestSimulationEvaluation(id: number): Promise<SimulationEvaluation | null> {
  const response = await httpClient.get<{ data: SimulationEvaluation | null }>(`/api/v1/simulaciones/${id}/evaluacion`)
  return response.data.data
}

export async function requestSimulationEvaluation(id: number): Promise<SimulationEvaluation> {
  const response = await httpClient.post<{ data: SimulationEvaluation }>(`/api/v1/simulaciones/${id}/evaluacion`)
  return response.data.data
}

export async function advanceSimulation(id: number, nextStageId?: number): Promise<Simulation> {
  const response = await httpClient.post<{ data: Simulation }>(`/api/v1/simulaciones/${id}/avanzar`, {
    id_etapa_destino: nextStageId,
  })
  return response.data.data
}
