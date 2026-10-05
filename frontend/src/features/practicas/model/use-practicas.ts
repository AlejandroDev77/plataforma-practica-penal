import { useQuery } from '@tanstack/react-query'
import { useCurrentUser } from '../../auth/model/use-current-user'
import { consultarOrquestacion } from '../api/practicas-api'
export function useOrquestacion(id: number) {
  const usuario = useCurrentUser().data?.id
  return useQuery({ queryKey: ['practicas-regladas', usuario, id, 'orquestacion'],
    queryFn: ({ signal }) => consultarOrquestacion(id, signal),
    enabled: Boolean(usuario) && Number.isSafeInteger(id) && id > 0,
    retry: false, staleTime: 0, gcTime: 0, refetchOnWindowFocus: true })
}
