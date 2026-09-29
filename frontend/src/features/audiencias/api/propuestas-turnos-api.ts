import { httpClient } from '../../../shared/api/http-client'

export interface EtapaAudienciaDisponible {
  id: number
  code: string
  name: string
  order: number
  is_initial: boolean
  is_final: boolean
}

export interface TipoAudienciaDisponible {
  id: number
  code: string
  name: string
  description: string | null
  stages: EtapaAudienciaDisponible[]
}

export interface PropuestaTurno {
  id: number
  id_tipo_audiencia: number
  tipo_audiencia: string
  id_etapa: number
  etapa: string
  orden: number
  rol: string
  acto_propuesto: string
  descripcion_propuesta: string
  referencia_normativa_propuesta: string | null
  observaciones: string | null
  estado: 'borrador'
  creador: string
  fecha_actualizacion: string | null
}

export interface PaginaPropuestas {
  data: PropuestaTurno[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface DatosPropuestaTurno {
  id_tipo_audiencia: number
  id_etapa: number
  orden: number
  rol: string
  acto_propuesto: string
  descripcion_propuesta: string
  referencia_normativa_propuesta: string | null
  observaciones: string | null
}

export async function listarTiposAudiencia(): Promise<TipoAudienciaDisponible[]> {
  const respuesta = await httpClient.get<{ data: TipoAudienciaDisponible[] }>('/api/v1/tipos-audiencia')
  return respuesta.data.data
}

export async function listarPropuestas(filtros: { buscar: string; id_tipo_audiencia?: number; id_etapa?: number; page: number }): Promise<PaginaPropuestas> {
  const respuesta = await httpClient.get<PaginaPropuestas>('/api/v1/administracion/propuestas-turnos', {
    params: {
      buscar: filtros.buscar || undefined,
      id_tipo_audiencia: filtros.id_tipo_audiencia,
      id_etapa: filtros.id_etapa,
      page: filtros.page,
    },
  })
  return respuesta.data
}

export async function guardarPropuesta(datos: DatosPropuestaTurno, id?: number): Promise<PropuestaTurno> {
  const respuesta = id
    ? await httpClient.put<{ data: PropuestaTurno }>(`/api/v1/administracion/propuestas-turnos/${id}`, datos)
    : await httpClient.post<{ data: PropuestaTurno }>('/api/v1/administracion/propuestas-turnos', datos)
  return respuesta.data.data
}

export async function eliminarPropuesta(id: number): Promise<void> {
  await httpClient.delete(`/api/v1/administracion/propuestas-turnos/${id}`)
}
