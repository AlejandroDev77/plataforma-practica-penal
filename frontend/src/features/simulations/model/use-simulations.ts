import { useQuery } from '@tanstack/react-query'
import { getLatestSimulationEvaluation, getSimulation, getSimulationProvider, listCaseSimulations, listHearingTypes } from '../api/simulations-api'

export const simulationsQueryKey = ['simulaciones'] as const
export const hearingTypesQueryKey = ['tipos-audiencia'] as const

export function useHearingTypes() {
  return useQuery({ queryKey: hearingTypesQueryKey, queryFn: listHearingTypes })
}

export function useCaseSimulations(caseId: number) {
  return useQuery({
    queryKey: [...simulationsQueryKey, 'expediente', caseId],
    queryFn: () => listCaseSimulations(caseId),
    enabled: Number.isSafeInteger(caseId) && caseId > 0,
  })
}

export function useSimulation(id: number) {
  return useQuery({
    queryKey: [...simulationsQueryKey, id],
    queryFn: () => getSimulation(id),
    enabled: Number.isSafeInteger(id) && id > 0,
  })
}

export function useSimulationProvider(id: number, enabled: boolean) {
  return useQuery({
    queryKey: [...simulationsQueryKey, id, 'proveedor'],
    queryFn: () => getSimulationProvider(id),
    enabled: enabled && Number.isSafeInteger(id) && id > 0,
    staleTime: 0,
    retry: false,
  })
}

export function useLatestSimulationEvaluation(id: number) {
  return useQuery({
    queryKey: [...simulationsQueryKey, id, 'evaluacion'],
    queryFn: () => getLatestSimulationEvaluation(id),
    enabled: Number.isSafeInteger(id) && id > 0,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status === 'pendiente' || status === 'procesando' ? 3_000 : false
    },
    refetchOnWindowFocus: true,
  })
}
