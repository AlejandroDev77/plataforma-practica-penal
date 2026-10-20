import { z } from 'zod'

const id = z.number().int().positive().refine(Number.isSafeInteger)
const huella = z.string().regex(/^[0-9a-f]{64}$/)
const estados = z.enum(['sin_activacion', 'vigente', 'retirada', 'revision_sustituida', 'revision_retirada',
  'habilitacion_retirada', 'habilitacion_vencida', 'revision_vencida', 'tipo_inactivo', 'integridad_incompatible'])
const consulta = z.object({
  id_version_reglas: id, id_tipo_audiencia: id, puede_solicitar_activacion: z.boolean(), bloqueos: z.array(z.string()).max(100),
  precondicion: z.object({ huella_version_esperada: huella, id_revision_esperada: id.nullable(), id_activacion_previa: id.nullable() }),
  activacion_actual: z.object({
    activation_id: id.nullable(), version_id: id.nullable(), review_id: id.nullable(), huella_version: huella.nullable(),
    activation_status: estados, activation_current: z.boolean(), read_only: z.literal(true),
    execution_authorized: z.literal(false), requires_simulation_binding: z.literal(true),
  }),
  read_only: z.literal(true), execution_authorized: z.literal(false), requires_simulation_binding: z.literal(true),
})
export type PreparacionActivacion = z.output<typeof consulta>
export function validarPreparacionActivacion(entrada: unknown, version: number, tipo: number, hash: string) {
  const datos = consulta.parse(entrada)
  const actual = datos.activacion_actual
  const sinActivacion = actual.activation_status === 'sin_activacion'
  if (datos.id_version_reglas !== version || datos.id_tipo_audiencia !== tipo || datos.precondicion.huella_version_esperada !== hash
    || datos.precondicion.id_activacion_previa !== actual.activation_id
    || actual.activation_current !== (actual.activation_status === 'vigente')
    || sinActivacion !== (actual.activation_id === null)
    || (sinActivacion ? actual.version_id !== null || actual.review_id !== null || actual.huella_version !== null
      : actual.version_id === null || actual.review_id === null || actual.huella_version === null)
    || datos.puede_solicitar_activacion !== (datos.bloqueos.length === 0 && datos.precondicion.id_revision_esperada !== null)) {
    throw new Error('No recibimos una consulta completa de esta versión. Vuelva a consultar.')
  }
  return datos
}
const fecha = z.string().refine(v => Number.isFinite(Date.parse(v)))
export const registroActivacion = z.object({
  id, id_tipo_audiencia: id, id_version_reglas: id, id_revision_reglas: id, id_usuario_responsable: id,
  huella_version: huella, id_activacion_previa: id.nullable(), motivo: z.string(), fecha_creacion: fecha,
  revocacion: z.object({ id, id_usuario_responsable: id, motivo: z.string(), fecha_creacion: fecha }).nullable(),
  execution_authorized: z.literal(false), requires_simulation_binding: z.literal(true),
})
export type ActivacionReglas = z.output<typeof registroActivacion>
const confirmacion = z.object({
  motivo: z.string().trim().min(1, 'Indique el motivo.').max(2000, 'Máximo 2000 caracteres.')
    .refine(v => /[^\s\p{Z}\u0085]/u.test(v), 'Indique el motivo.'),
  confirmacion_humana: z.literal(true, { error: 'Confirme expresamente esta operación.' }),
})
export const prepararRetiroActivacion = (entrada: unknown) => confirmacion.parse(entrada)
export function prepararSolicitudActivacion(entrada: unknown, preparacion: PreparacionActivacion) {
  const p = validarPreparacionActivacion(preparacion, preparacion.id_version_reglas, preparacion.id_tipo_audiencia, preparacion.precondicion.huella_version_esperada)
  if (!p.puede_solicitar_activacion || p.precondicion.id_revision_esperada === null) throw new Error('Esta versión todavía no reúne las condiciones de activación.')
  return { ...confirmacion.parse(entrada), ...p.precondicion }
}
export type SolicitudActivacion = ReturnType<typeof prepararSolicitudActivacion>
export const nombresActivacion: Record<z.output<typeof estados>, string> = {
  sin_activacion: 'Sin activación administrativa', vigente: 'Activación administrativa vigente', retirada: 'Activación retirada',
  revision_sustituida: 'La revisión fue sustituida', revision_retirada: 'La revisión fue retirada',
  habilitacion_retirada: 'La habilitación fue retirada', habilitacion_vencida: 'La habilitación venció',
  revision_vencida: 'La revisión venció', tipo_inactivo: 'Tipo de audiencia inactivo', integridad_incompatible: 'Integridad no comprobada',
}
const motivosBloqueo: Record<string, string> = {
  revision_juridica_no_vigente: 'Se necesita una revisión jurídica vigente.',
  instrucciones_no_compatibles: 'Selle una copia con instrucciones completas y compatibles.',
  instrucciones_no_revisadas: 'La revisión debe cubrir las instrucciones de esta copia.',
  revision_ya_utilizada: 'Esta revisión ya se utilizó. Una nueva activación requiere otra revisión.',
  tipo_inactivo: 'El tipo de audiencia debe estar activo.',
  huella_version_incompatible: 'No se pudo comprobar la integridad de esta copia.',
}
export const explicarBloqueoActivacion = (codigo: string) => motivosBloqueo[codigo] ?? 'La estructura de esta copia necesita ajustes antes de la activación.'
