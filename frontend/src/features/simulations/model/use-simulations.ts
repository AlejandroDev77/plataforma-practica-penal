import { useQuery } from '@tanstack/react-query'
import { getSimulation, listCaseSimulations, listHearingTypes } from '../api/simulations-api'

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
