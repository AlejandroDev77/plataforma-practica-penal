import { useQuery } from '@tanstack/react-query'
import { currentUserQueryKey, getCurrentUser } from '../api/auth-api'

export function useCurrentUser() {
  return useQuery({
    queryKey: currentUserQueryKey,
    queryFn: getCurrentUser,
    retry: false,
    staleTime: 30_000,
  })
}
