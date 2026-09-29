import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { getCase, getCaseAnalysis, getCaseAnalysisProcess, listCases, listFilePages } from '../api/cases-api'

export const casesQueryKey = ['expedientes'] as const

export function useCases(search: string, page: number, enabled = true) {
  return useQuery({
    queryKey: [...casesQueryKey, search, page],
    queryFn: () => listCases(search, page),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useCase(id: number) {
  return useQuery({
    queryKey: [...casesQueryKey, id],
    queryFn: () => getCase(id),
    enabled: Number.isSafeInteger(id) && id > 0,
    refetchInterval: (query) => query.state.data?.files?.some((file) => ['pendiente', 'procesando'].includes(file.processing_status)) ? 3000 : false,
  })
}

export function useCaseFilePages(caseId: number, fileId: number | null, page: number, enabled: boolean) {
  return useQuery({
    queryKey: [...casesQueryKey, caseId, 'archivos', fileId, 'paginas', page],
    queryFn: () => listFilePages(caseId, fileId ?? 0, page),
    enabled: enabled && fileId !== null && Number.isSafeInteger(caseId) && caseId > 0,
  })
}

export function useCaseAnalysis(caseId: number) {
  return useQuery({
    queryKey: [...casesQueryKey, caseId, 'analisis'],
    queryFn: () => getCaseAnalysis(caseId),
    enabled: Number.isSafeInteger(caseId) && caseId > 0,
  })
}

export function useCaseAnalysisProcess(caseId: number, processId: number | null) {
  return useQuery({
    queryKey: [...casesQueryKey, caseId, 'analisis', 'procesos', processId],
    queryFn: () => getCaseAnalysisProcess(caseId, processId ?? 0),
    enabled: processId !== null && Number.isSafeInteger(caseId) && caseId > 0,
    refetchInterval: (query) => ['pendiente', 'procesando'].includes(query.state.data?.status ?? '') ? 2000 : false,
  })
}
