import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCurrentUser } from '../../auth/model/use-current-user'

export const reglasKey = ['gobierno-reglas'] as const
// Sin persistencia ni reintentos: evidencia privada solo en memoria y por sesión.
export function useReglasQuery<T>(clave: unknown[], consultar: (signal: AbortSignal) => Promise<T>, enabled = true) {
  const { data: usuario } = useCurrentUser()
  return useQuery({ queryKey: [...reglasKey, usuario?.id, ...clave], queryFn: ({ signal }) => consultar(signal), enabled: Boolean(usuario) && enabled,
    retry: false, staleTime: 0, gcTime: 0, refetchOnWindowFocus: false, refetchOnReconnect: false })
}
export function useActualizarReglas() {
  const client = useQueryClient()
  return () => client.invalidateQueries({ queryKey: reglasKey })
}
