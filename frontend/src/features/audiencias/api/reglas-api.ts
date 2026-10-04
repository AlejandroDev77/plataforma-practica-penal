import { httpClient } from '../../../shared/api/http-client'
import { validarBorradorInstrucciones, type BorradorInstrucciones, type DatosSelladoInstrucciones, type DatosHabilitacion, type DatosRevision, type Habilitacion, type Pagina, type RevisionReglas, type VersionReglas } from '../model/reglas'

const base = '/api/v1/reglas-audiencia'
interface Filtros { page: number; id_tipo_audiencia?: number }
async function pagina<T>(ruta: string, filtros: Filtros, signal?: AbortSignal): Promise<Pagina<T>> {
  return (await httpClient.get<Pagina<T>>(base + ruta, { params: { ...filtros, por_pagina: 20 }, signal })).data
}
export const listarVersiones = (filtros: Filtros, signal?: AbortSignal) => pagina<VersionReglas>('/versiones', filtros, signal)
export const listarHabilitaciones = (filtros: Filtros, signal?: AbortSignal) => pagina<Habilitacion>('/habilitaciones', filtros, signal)
export const listarRevisiones = (version: number, page: number, signal?: AbortSignal) => pagina<RevisionReglas>(`/versiones/${version}/revisiones`, { page }, signal)
export async function consultarVersion(id: number, signal?: AbortSignal): Promise<VersionReglas> {
  return (await httpClient.get<{ data: VersionReglas }>(`${base}/versiones/${id}`, { signal })).data.data
}
export async function consultarHabilitacionPropia(tipo: number, usuario: number, signal?: AbortSignal): Promise<Habilitacion | null> {
  // Administración puede recibir otras cuentas; no asumir que el primer registro es propio.
  let page = 1
  while (!signal?.aborted) {
    const resultado = await pagina<Habilitacion>('/habilitaciones', { page, id_tipo_audiencia: tipo }, signal)
    const propia = resultado.data.find(h => h.id_usuario_revisor === usuario && h.estado === 'vigente' && !h.revocacion)
    if (propia) return propia
    if (page >= resultado.meta.last_page) return null
    page++
  }
  return null
}
export async function sellarVersion(tipo: number, motivo: string): Promise<VersionReglas> {
  return (await httpClient.post<{ data: VersionReglas }>(`${base}/tipos/${tipo}/versiones`, { motivo })).data.data
}
export async function consultarBorradorInstrucciones(tipo: number, signal?: AbortSignal): Promise<BorradorInstrucciones> {
  const respuesta = await httpClient.get<{ data: unknown }>(`${base}/tipos/${tipo}/preparacion-instrucciones`, { signal })
  return validarBorradorInstrucciones(respuesta.data.data, tipo)
}
export async function sellarInstrucciones(tipo: number, datos: DatosSelladoInstrucciones): Promise<VersionReglas> {
  return (await httpClient.post<{ data: VersionReglas }>(`${base}/tipos/${tipo}/versiones`, datos)).data.data
}
export async function habilitarRevisor(datos: DatosHabilitacion): Promise<Habilitacion> {
  return (await httpClient.post<{ data: Habilitacion }>(`${base}/habilitaciones`, datos)).data.data
}
export async function registrarRevision(id: number, datos: DatosRevision): Promise<RevisionReglas> {
  return (await httpClient.post<{ data: RevisionReglas }>(`${base}/versiones/${id}/revisiones`, datos)).data.data
}
export async function retirarRegistro(tipo: 'habilitaciones' | 'revisiones', id: number, motivo: string): Promise<void> {
  await httpClient.post(`${base}/${tipo}/${id}/revocaciones`, { motivo })
}
