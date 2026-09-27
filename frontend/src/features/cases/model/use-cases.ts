import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { getCase, listCases } from '../api/cases-api'

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
  })
}
