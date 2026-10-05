import axios from 'axios'
import { z } from 'zod'
import { httpClient } from '../../../shared/api/http-client'
import { consultarPreparacionActivacion, consultarVersion, listarActivaciones } from '../../audiencias/api/reglas-api'
import { actaSchema, fuenteBusqueda, paginaBorradores, proveedorSchema, validarBorrador, validarOrquestacion, type FuenteSeleccionable } from '../model/practica-reglada'
const base = '/api/v1/practicas-regladas'
export async function consultarActa(id: number, signal?: AbortSignal) {
  const d = actaSchema.parse((await httpClient.get(`${base}/${id}/acta`, { signal })).data.data)
  if (d.id !== id) throw new Error('Acta de otra práctica.')
  return d
}
export async function consultarOrquestacion(id: number, signal?: AbortSignal) {
  const r = await httpClient.get<{ data: unknown }>(`${base}/${id}/orquestacion`, { signal })
  return validarOrquestacion(r.data.data, id)
}
export async function consultarProveedor(id: number, signal?: AbortSignal) {
  return proveedorSchema.parse((await httpClient.get<{ data: unknown }>(`${base}/${id}/proveedor`, { signal })).data.data)
}
export async function listarBorradores(id: number, signal?: AbortSignal) {
  return paginaBorradores.parse((await httpClient.get(`${base}/${id}/borradores`, { signal })).data)
}
export async function consultarBorrador(id: number, borrador: number, signal?: AbortSignal) {
  return validarBorrador((await httpClient.get(`${base}/${id}/borradores/${borrador}`, { signal })).data.data, id)
}
export async function generarBorrador(id: number, datos: object) {
  return validarBorrador((await httpClient.post(`${base}/${id}/borradores`, datos, { timeout: 150_000 })).data.data, id)
}
export const confirmarBorrador = async (id: number, borrador: number) =>
  (await httpClient.post(`${base}/${id}/borradores/${borrador}/confirmacion`, { confirmacion_humana: true })).data.data
export const moverPractica = async (id: number, datos: object) => (await httpClient.post(`${base}/${id}/movimientos`, datos)).data.data
export const registrarDefensa = async (id: number, datos: object) => (await httpClient.post(`${base}/${id}/defensa`, datos)).data.data
export async function buscarFuentes(id: number, consulta: string): Promise<FuenteSeleccionable[]> {
  const r = await httpClient.post(`${base}/${id}/fuentes`, { consulta, limite: 5 })
  const d = z.object({ expediente: z.array(fuenteBusqueda).max(10), juridica: z.array(fuenteBusqueda).max(10) }).parse(r.data.data)
  return [...d.expediente.map(f => ({ ...f, clase: 'expediente' as const })), ...d.juridica.map(f => ({ ...f, clase: 'juridica' as const }))]
}
export async function consultarCopiaActual(tipo: number, signal?: AbortSignal) {
  const historial = await listarActivaciones(tipo, 1, signal)
  const ultima = historial.data[0]
  if (!ultima || ultima.revocacion) return null
  const version = await consultarVersion(ultima.id_version_reglas, signal)
  const consulta = await consultarPreparacionActivacion(version, signal)
  const actual = consulta.activacion_actual
  if (!actual.activation_current || actual.activation_id !== ultima.id || actual.version_id !== version.id
    || actual.review_id !== ultima.id_revision_reglas || actual.huella_version !== ultima.huella_version) return null
  return { activacion: ultima.id, version: version.numero_version, idVersion: version.id }
}
export async function crearPractica(caso: number, analisis: number, tipo: number, activacion: number) {
  const r = await httpClient.post(`${base}/expedientes/${caso}`, { id_analisis: analisis, id_tipo_audiencia: tipo, id_activacion: activacion })
  return z.object({ id_simulacion: z.number().int().positive().refine(Number.isSafeInteger), estado: z.literal('preparando') }).parse(r.data.data)
}
export function errorPractica(error: unknown) {
  if (axios.isAxiosError(error)) {
    const s = error.response?.status
    if (s === 401 || s === 419) return 'La sesión venció. Vuelve a iniciar sesión antes de continuar.'
    if (s === 403 || s === 404) return 'No puedes consultar este recurso. Revisa tu acceso y vuelve al expediente.'
    if (s === 409) return 'El turno, las fuentes o la vigencia cambiaron; también puede faltar la autorización del proveedor. Actualiza la práctica y revisa la selección.'
    if (s === 422) return 'La solicitud no reúne las condiciones requeridas. Revisa la versión, el turno y los campos.'
    if (s === 429) return 'Espera un minuto antes de solicitar otra acción.'
  }
  return 'No se pudo confirmar el resultado. Actualiza la práctica y los borradores antes de repetir una acción; puede haberse guardado.'
}
