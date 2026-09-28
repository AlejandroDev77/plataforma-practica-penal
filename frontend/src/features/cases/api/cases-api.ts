import { httpClient } from '../../../shared/api/http-client'
import type { CaseFile, FileExtractionPage, LegalCase, Paginated } from '../model/case'
import type { AnalysisReviewDecision, CaseAnalysis } from '../model/case-analysis'

export async function getCaseAnalysis(id: number): Promise<CaseAnalysis | null> {
  const response = await httpClient.get<{ data: CaseAnalysis | null }>(`/api/v1/expedientes/${id}/analisis`)
  return response.data.data
}

export async function reviewCaseAnalysis(caseId: number, analysisId: number, decision: AnalysisReviewDecision, observation: string): Promise<CaseAnalysis> {
  const response = await httpClient.post<{ data: CaseAnalysis }>(
    `/api/v1/expedientes/${caseId}/analisis/${analysisId}/revisiones`,
    { decision, observacion: observation || null },
  )
  return response.data.data
}

export async function listCases(search: string, page = 1): Promise<Paginated<LegalCase>> {
  const response = await httpClient.get<Paginated<LegalCase>>('/api/v1/expedientes', {
    params: { search: search || undefined, page, per_page: 10 },
  })
  return response.data
}

export async function getCase(id: number): Promise<LegalCase> {
  const response = await httpClient.get<{ data: LegalCase }>(`/api/v1/expedientes/${id}`)
  return response.data.data
}

export async function createCase(input: { title: string; case_number?: string; description?: string }): Promise<LegalCase> {
  const response = await httpClient.post<{ data: LegalCase }>('/api/v1/expedientes', {
    titulo: input.title,
    numero_caso: input.case_number || null,
    descripcion: input.description || null,
  })
  return response.data.data
}

export async function updateCase(id: number, input: { title: string; case_number?: string; description?: string }): Promise<LegalCase> {
  const response = await httpClient.patch<{ data: LegalCase }>(`/api/v1/expedientes/${id}`, {
    titulo: input.title,
    numero_caso: input.case_number || null,
    descripcion: input.description || null,
  })
  return response.data.data
}

export async function deleteCase(id: number): Promise<void> {
  await httpClient.delete(`/api/v1/expedientes/${id}`)
}

export async function uploadCaseFiles(id: number, files: File[]): Promise<CaseFile[]> {
  const formData = new FormData()
  files.forEach((file) => formData.append('archivos[]', file))
  const response = await httpClient.post<{ data: CaseFile[] }>(`/api/v1/expedientes/${id}/archivos`, formData)
  return response.data.data
}

export async function downloadCaseFile(caseId: number, fileId: number, fileName: string): Promise<void> {
  const response = await httpClient.get<Blob>(`/api/v1/expedientes/${caseId}/archivos/${fileId}/descarga`, {
    responseType: 'blob',
  })
  const objectUrl = URL.createObjectURL(response.data)
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = fileName
  document.body.append(anchor)
  anchor.click()
  anchor.remove()
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
}

export async function deleteCaseFile(caseId: number, fileId: number): Promise<void> {
  await httpClient.delete(`/api/v1/expedientes/${caseId}/archivos/${fileId}`)
}

export async function listFilePages(caseId: number, fileId: number, page = 1): Promise<Paginated<FileExtractionPage>> {
  const response = await httpClient.get<Paginated<FileExtractionPage>>(
    `/api/v1/expedientes/${caseId}/archivos/${fileId}/paginas`,
    { params: { page } },
  )
  return response.data
}
