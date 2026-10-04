import { z } from 'zod'

export type EstadoRevision = 'no_acreditada' | 'aprobado' | 'requiere_cambios' | 'revision_revocada' | 'habilitacion_revocada' | 'habilitacion_vencida' | 'revision_vencida'
export interface PrecondicionRevision {
  huella_version_esperada: string
  id_ultima_revision_esperada: number | null
  estado_revision_esperado: EstadoRevision
}
export interface VersionReglas {
  id: number; id_tipo_audiencia: number; id_usuario_autor: number; numero_version: number
  motivo: string; huella_contenido: string; fecha_creacion: string
  precondicion_revision: PrecondicionRevision
  revision_instrucciones?: { estado: 'ausente' | 'disponible' | 'incompatible'; requiere_confirmacion: boolean }
  revision_juridica: { legal_review_status: EstadoRevision; review_approved: boolean; execution_authorized: false; requires_separate_activation: true }
  contenido?: {
    formato: number
    tipo: { nombre: string; descripcion: string | null; activo: boolean }
    etapas: { id_etapa: number; nombre: string; orden: number; activo: boolean }[]
    transiciones: { id_etapa_origen: number; id_etapa_destino: number; activo: boolean }[]
    propuestas: { id_propuesta_turno: number; id_etapa: number; id_usuario_creador: number | null; orden: number; rol: string; acto_propuesto: string; descripcion_propuesta: string; referencia_normativa_propuesta: string | null; observaciones: string | null }[]
    turnos_catalogo_referencia: { id_turno_etapa: number; id_etapa: number; orden: number; rol: string; instruccion: string | null; activo: boolean }[]
    contrato_ejecucion?: unknown
  }
}
export interface Retiro { id: number; id_usuario_responsable: number; motivo: string; fecha_creacion: string }
export interface Habilitacion {
  id: number; id_usuario_revisor: number; id_usuario_habilitador: number; id_tipo_audiencia: number
  alcance: string; referencia_acreditacion: string; huella_acreditacion: string; acreditacion_verificada: boolean
  fecha_creacion: string; fecha_vencimiento: string; estado: 'vigente' | 'vencida' | 'revocada'; revocacion: Retiro | null
}
export interface FuenteVerificada { referencia: string; huella_contenido: string }
export interface RevisionReglas {
  id: number; id_version_reglas: number; id_usuario_revisor: number; id_habilitacion: number
  decision: 'aprobado' | 'requiere_cambios'; alcance_revisado: string; observacion: string
  fuentes_verificadas: FuenteVerificada[]; vigencia_comprobada_en: string; fecha_creacion: string
  fecha_vencimiento: string | null; revocacion: Retiro | null; execution_authorized: false
  huella_ejecucion_revisada?: string | null
}
export interface Pagina<T> { data: T[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }

export const nombresEstado: Record<EstadoRevision | Habilitacion['estado'], string> = {
  no_acreditada: 'Sin revisión', aprobado: 'Revisión aprobada', requiere_cambios: 'Requiere cambios',
  revision_revocada: 'Revisión retirada', habilitacion_revocada: 'Habilitación retirada',
  habilitacion_vencida: 'Habilitación vencida', revision_vencida: 'Revisión vencida',
  vigente: 'Vigente', vencida: 'Vencida', revocada: 'Retirada',
}
export const nombreRol = (rol: string) => ({ juez: 'Juez', fiscal: 'Fiscal', abogado_defensor: 'Defensa' }[rol] ?? 'Rol no reconocido')
export const fechaLegible = (fecha: string | null) => fecha ? new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(fecha)) : 'Sin vencimiento'
export function hoyBolivia(): string {
  const partes = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/La_Paz', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date())
  const parte = (tipo: string) => partes.find(p => p.type === tipo)?.value
  return `${parte('year')}-${parte('month')}-${parte('day')}`
}
const texto = (max: number) => z.string().trim().min(1, 'Complete este campo.').max(max, `Máximo ${max} caracteres.`)
const huella = z.string().regex(/^[0-9a-f]{64}$/, 'Use una huella SHA-256 de 64 caracteres en minúsculas.')
const vencimiento = z.string().refine(valor => Number.isFinite(new Date(valor).getTime()) && new Date(valor).getTime() > Date.now(), 'Indique una fecha futura.').transform(valor => new Date(valor).toISOString())
const confirmacion = z.literal(true, { error: 'Confirme expresamente la comprobación.' })
const contratoRevision = z.strictObject({
  formato: z.literal(1), perfil: z.literal('mvp-defensa-aciclico-ejecucion-v1'), rol_usuario: z.literal('abogado_defensor'),
  instrucciones: z.array(z.strictObject({
    id_propuesta_turno: z.number().int().positive().refine(Number.isSafeInteger),
    // No recortar el texto: debe verse exactamente como fue sellado.
    texto: z.string().min(1).refine(v => Array.from(v).length <= 5000 && /[^\s\p{Z}\u0085]/u.test(v)),
  })).min(1).max(10000),
})
export type ContratoRevision = z.output<typeof contratoRevision>
export type LecturaInstrucciones = { estado: 'ausente' | 'incompatible'; contrato: null } | { estado: 'disponible'; contrato: ContratoRevision }
export function consultarInstrucciones(version: VersionReglas): LecturaInstrucciones {
  const contenido = version.contenido
  const presente = Boolean(contenido && Object.hasOwn(contenido, 'contrato_ejecucion'))
  const estado = version.revision_instrucciones
  if (!presente) return { estado: !estado || estado.estado === 'ausente' && estado.requiere_confirmacion === false ? 'ausente' : 'incompatible', contrato: null }
  const datos = contratoRevision.safeParse(contenido?.contrato_ejecucion)
  const propuestas = contenido?.propuestas
  const etapas = contenido?.etapas
  if (!datos.success || estado?.estado !== 'disponible' || estado.requiere_confirmacion !== true
    || contenido?.formato !== 1 || !Array.isArray(etapas) || !etapas.length
    || !huella.safeParse(version.huella_contenido).success || version.huella_contenido !== version.precondicion_revision?.huella_version_esperada
    || !Array.isArray(propuestas) || propuestas.length !== datos.data.instrucciones.length
    || new Set(datos.data.instrucciones.map(i => i.id_propuesta_turno)).size !== propuestas.length
    || !propuestas.every((p, indice) => p.id_propuesta_turno === datos.data.instrucciones[indice].id_propuesta_turno
      && ['juez', 'fiscal', 'abogado_defensor'].includes(p.rol) && Number.isSafeInteger(p.orden) && p.orden >= 1 && p.orden <= 100
      && etapas.some(e => e.id_etapa === p.id_etapa) && typeof p.acto_propuesto === 'string' && typeof p.descripcion_propuesta === 'string')) {
    return { estado: 'incompatible', contrato: null }
  }
  return { estado: 'disponible', contrato: datos.data }
}
export const datosSellado = z.object({ id_tipo_audiencia: z.coerce.number().int().positive('Seleccione una audiencia.'), motivo: texto(2000), confirmado: confirmacion })
export const datosHabilitacion = z.object({
  id_usuario_revisor: z.coerce.number().int().positive('Indique el identificador de una cuenta real.'),
  id_tipo_audiencia: z.coerce.number().int().positive('Seleccione una audiencia.'), alcance: texto(2000),
  referencia_acreditacion: texto(500), huella_acreditacion: huella, acreditacion_verificada: confirmacion, fecha_vencimiento: vencimiento,
})
const precondicion = z.object({
  huella_version_esperada: huella, id_ultima_revision_esperada: z.number().int().positive().nullable(),
  estado_revision_esperado: z.enum(['no_acreditada', 'aprobado', 'requiere_cambios', 'revision_revocada', 'habilitacion_revocada', 'habilitacion_vencida', 'revision_vencida']),
})
const decision = z.object({
  decision: z.enum(['aprobado', 'requiere_cambios']), observacion: texto(5000),
  fuentes_verificadas: z.array(z.object({ referencia: texto(500), huella_contenido: huella })).min(1, 'Añada al menos una fuente.').max(20),
  vigencia_comprobada_en: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Indique una fecha válida.')
    .refine(v => !Number.isNaN(Date.parse(v)) && new Date(v).toISOString().slice(0, 10) === v && v <= hoyBolivia(), 'La fecha no puede ser futura ni inexistente.'),
  confirmacion_humana: confirmacion, fecha_vencimiento: z.string(),
})
export function prepararRevision(entrada: unknown, version: VersionReglas, habilitacion: Habilitacion, usuario: number) {
  if (!puedeRevisar(version, habilitacion, usuario)) throw new Error('No tiene una habilitación vigente e independiente para esta versión.')
  const datos = decision.parse(entrada)
  const instrucciones = consultarInstrucciones(version)
  const cobertura = instrucciones.estado === 'disponible' ? z.object({ confirmacion_instrucciones: confirmacion }).parse(entrada) : null
  const fecha = datos.decision === 'aprobado' ? vencimiento.parse(datos.fecha_vencimiento) : null
  if (fecha && new Date(fecha) > new Date(habilitacion.fecha_vencimiento)) throw new Error('La revisión no puede vencer después de la habilitación.')
  return { ...datos, fecha_vencimiento: fecha, ...precondicion.parse(version.precondicion_revision),
    ...(cobertura ? { confirmacion_instrucciones: cobertura.confirmacion_instrucciones, huella_ejecucion_revisada: version.huella_contenido } : {}) }
}
export function puedeRevisar(version: VersionReglas, habilitacion: Habilitacion | null | undefined, usuario: number): boolean {
  const propuestas = version.contenido?.propuestas
  return Boolean(habilitacion && habilitacion.id_usuario_revisor === usuario && habilitacion.id_tipo_audiencia === version.id_tipo_audiencia
    && consultarInstrucciones(version).estado !== 'incompatible'
    && habilitacion.estado === 'vigente' && !habilitacion.revocacion && habilitacion.acreditacion_verificada === true
    && new Date(habilitacion.fecha_vencimiento).getTime() > Date.now()
    && version.revision_juridica.execution_authorized === false && version.id_usuario_autor !== usuario
    && Array.isArray(propuestas) && propuestas.length && propuestas.every(p => Number.isSafeInteger(p.id_usuario_creador) && (p.id_usuario_creador ?? 0) > 0 && p.id_usuario_creador !== usuario))
}
export const datosRetiro = z.object({ motivo: texto(2000), confirmado: confirmacion })
export type DatosHabilitacion = z.output<typeof datosHabilitacion>
export type DatosRevision = ReturnType<typeof prepararRevision>

export async function huellaArchivo(archivo: File): Promise<string> {
  if (archivo.size > 20 * 1024 * 1024) throw new Error('Use un documento de hasta 20 MB para calcular su huella.')
  const bytes = await archivo.arrayBuffer()
  return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), b => b.toString(16).padStart(2, '0')).join('')
}
