import { z } from 'zod'

const id = z.number().int().positive().refine(Number.isSafeInteger)
const rol = z.enum(['juez', 'fiscal', 'abogado_defensor'])
const accion = z.enum(['iniciar', 'avanzar', 'finalizar', 'registrar_defensa', 'proponer_agente'])
const estado = z.enum(['preparando', 'esperando_agente', 'esperando_defensa', 'esperando_avance', 'esperando_destino', 'esperando_cierre', 'finalizada', 'bloqueada'])
const contexto = z.object({
  estado_esperado: z.enum(['preparando', 'activa', 'finalizada']), id_etapa_esperada: id.nullable(),
  id_movimiento_esperado: id.nullable(), id_turno_esperado: id.nullable(), orden_esperado: id,
})
const orquestacion = z.object({
  simulation_id: id, binding_id: id.nullable(), version_id: id.nullable(), activation_id: id.nullable(),
  binding_status: z.string(), status: estado, read_only: z.literal(true), execution_authorized: z.literal(false),
  requires_command_integration: z.literal(false), allowed_actions: z.array(z.never()),
  technical_candidate_actions: z.array(accion).max(1), blocking_reasons: z.array(z.string()).max(100),
  requires_provider_check: z.boolean(), expected_context: contexto.nullable(),
  simulation_state: z.enum(['preparando', 'activa', 'finalizada']).optional(),
  type: z.object({ id_tipo_audiencia: id, nombre: z.string() }).optional(),
  stage: z.object({ id_etapa: id, nombre: z.string() }).nullable().optional(),
  current_turn: z.object({ id_propuesta_turno: id, rol, instruccion: z.string().min(1).max(5000) }).nullable(),
  current_participant: z.object({ id, rol, controlado_por: z.enum(['ia', 'usuario']) }).nullable().optional(),
  candidate_next_stages: z.array(z.object({ id_etapa: id, nombre: z.string() })).max(100).optional(),
  completed_turns: z.number().int().nonnegative().optional(), stage_turn_count: z.number().int().nonnegative().optional(),
})
export type Orquestacion = z.output<typeof orquestacion>
export function validarOrquestacion(raw: unknown, practica: number): Orquestacion {
  const d = orquestacion.parse(raw)
  if (d.simulation_id !== practica) throw new Error('Consulta de otra práctica.')
  if (d.status === 'bloqueada') {
    if (d.technical_candidate_actions.length || d.requires_provider_check) throw new Error('Consulta bloqueada incompatible.')
    if (d.binding_status === 'sin_vinculacion' && (d.binding_id || d.version_id || d.activation_id || d.expected_context || d.current_turn)) throw new Error('Consulta heredada incompatible.')
    return d
  }
  const p = d.expected_context
  if (!d.binding_id || !d.version_id || !d.activation_id || d.binding_status !== 'vigente' || !p
      || p.estado_esperado !== d.simulation_state || !d.type
      || d.requires_provider_check !== d.technical_candidate_actions.includes('proponer_agente')) throw new Error('Consulta incompleta.')
  const a = d.technical_candidate_actions[0]
  const correspondientes: Partial<Record<Orquestacion['status'], string>> = {
    preparando: 'iniciar', esperando_agente: 'proponer_agente', esperando_defensa: 'registrar_defensa',
    esperando_avance: 'avanzar', esperando_destino: 'avanzar', esperando_cierre: 'finalizar',
  }
  if (a !== correspondientes[d.status]) throw new Error('Acción incompatible.')
  if (d.status === 'preparando') {
    if (p.estado_esperado !== 'preparando' || p.id_etapa_esperada || p.id_movimiento_esperado || p.id_turno_esperado || d.current_turn) throw new Error('Inicio incompatible.')
  } else {
    if (!d.stage || p.id_etapa_esperada !== d.stage.id_etapa || !p.id_movimiento_esperado) throw new Error('Etapa incompatible.')
    if (d.status !== 'finalizada' && p.estado_esperado !== 'activa') throw new Error('Estado incompatible.')
    if (d.status === 'finalizada' && p.estado_esperado !== 'finalizada') throw new Error('Cierre incompatible.')
  }
  if (a === 'registrar_defensa' || a === 'proponer_agente') {
    const t = d.current_turn, participante = d.current_participant
    if (!t || !participante || p.id_turno_esperado !== t.id_propuesta_turno || participante.rol !== t.rol
        || participante.controlado_por !== (t.rol === 'abogado_defensor' ? 'usuario' : 'ia')
        || (a === 'registrar_defensa') !== (t.rol === 'abogado_defensor')) throw new Error('Turno incompatible.')
  } else if (d.status !== 'preparando' && (d.current_turn || p.id_turno_esperado)) throw new Error('Etapa completa incompatible.')
  return d
}
export const claveContexto = (d: Orquestacion) => JSON.stringify([d.simulation_id, d.binding_id, d.expected_context, d.status])
export function contextoTurno(d: Orquestacion) {
  const p = validarOrquestacion(d, d.simulation_id).expected_context
  if (!p?.id_movimiento_esperado || !p.id_etapa_esperada || !p.id_turno_esperado) throw new Error('Consulte de nuevo el turno.')
  return { id_movimiento_esperado: p.id_movimiento_esperado, id_etapa_esperada: p.id_etapa_esperada,
    id_turno_esperado: p.id_turno_esperado, orden_esperado: p.orden_esperado }
}
export function prepararMovimiento(d: Orquestacion, accionSolicitada: 'iniciar' | 'avanzar' | 'finalizar', destino?: number) {
  validarOrquestacion(d, d.simulation_id)
  if (!d.expected_context || !d.technical_candidate_actions.includes(accionSolicitada)) throw new Error('Consulte de nuevo la práctica.')
  if (destino !== undefined && (accionSolicitada !== 'avanzar' || !d.candidate_next_stages?.some(e => e.id_etapa === destino))) throw new Error('Destino no disponible.')
  if (accionSolicitada === 'avanzar' && d.candidate_next_stages?.length !== 1 && destino === undefined) throw new Error('Seleccione una etapa.')
  return { accion: accionSolicitada, estado_esperado: d.expected_context.estado_esperado,
    id_etapa_esperada: d.expected_context.id_etapa_esperada, ...(destino === undefined ? {} : { id_destino: destino }) }
}
export const proveedorSchema = z.object({ provider: z.enum(['ollama', 'openai']), model: z.string().trim().min(1).max(150), requires_external_authorization: z.boolean() })
  .refine(p => p.requires_external_authorization === (p.provider === 'openai'))
export type Proveedor = z.output<typeof proveedorSchema>
function seleccion(ids: number[], max: number) {
  return z.array(id).max(max).refine(v => new Set(v).size === v.length).parse(ids)
}
export function prepararGeneracion(d: Orquestacion, ids: number[], proveedor: Proveedor, consentimiento: boolean) {
  if (!d.technical_candidate_actions.includes('proponer_agente')) throw new Error('No corresponde generar.')
  const p = proveedorSchema.parse(proveedor)
  if (p.requires_external_authorization && consentimiento !== true) throw new Error('Autorice el envío seleccionado.')
  return { ...contextoTurno(d), ids_fragmento: seleccion(ids, 8), autorizar_externo: p.requires_external_authorization && consentimiento === true }
}
export function prepararDefensa(d: Orquestacion, ids: number[], contenido: string) {
  if (!d.technical_candidate_actions.includes('registrar_defensa')) throw new Error('No corresponde intervenir.')
  const texto = z.string().max(2000).refine(v => /[^\s\p{Z}\u0085]/u.test(v)).parse(contenido)
  return { ...contextoTurno(d), contenido: texto, ids_fragmento: seleccion(ids, 10) }
}
const fuenteEnviada = z.object({ id, kind: z.enum(['expediente', 'juridica']), title: z.string(), locator: z.string(), excerpt: z.string().refine(v => Array.from(v).length <= 1500) })
const fecha = z.string().refine(v => Number.isFinite(Date.parse(v)))
export const borradorSchema = z.object({
  id_borrador: id, id_simulacion: id, id_movimiento: id, id_etapa: id, id_propuesta_turno: id, orden: id,
  respuesta: z.object({ speaker_role: rol, content: z.string().min(1).max(2000), used_source_ids: z.array(id).max(8), requires_human_review: z.literal(true) }),
  proveedor: z.enum(['ollama', 'openai']), modelo_ia: z.string().min(1).max(150), autorizar_externo: z.boolean(),
  fuentes_enviadas: z.array(fuenteEnviada).max(8), fecha_creacion: fecha, fecha_vencimiento: fecha,
  vencido: z.boolean(), id_intervencion_confirmada: id.nullable(), requires_human_review: z.literal(true), confirmation_authorized: z.literal(false),
}).refine(b => new Set(b.respuesta.used_source_ids).size === b.respuesta.used_source_ids.length
  && new Set(b.fuentes_enviadas.map(f => f.id)).size === b.fuentes_enviadas.length
  && b.respuesta.used_source_ids.every(id => b.fuentes_enviadas.some(f => f.id === id))
  && (b.proveedor !== 'openai' || b.autorizar_externo))
export type Borrador = z.output<typeof borradorSchema>
export function validarBorrador(raw: unknown, practica: number) {
  const b = borradorSchema.parse(raw)
  if (b.id_simulacion !== practica) throw new Error('Borrador de otra práctica.')
  return b
}
export function coincideTurno(b: Pick<Borrador, 'id_movimiento' | 'id_etapa' | 'id_propuesta_turno' | 'orden'>, d: Orquestacion) {
  const p = d.expected_context
  return Boolean(p && b.id_movimiento === p.id_movimiento_esperado && b.id_etapa === p.id_etapa_esperada
    && b.id_propuesta_turno === p.id_turno_esperado && b.orden === p.orden_esperado)
}
export const paginaBorradores = z.object({ data: z.array(z.object({
  id_borrador: id, id_movimiento: id, id_etapa: id, id_propuesta_turno: id, orden: id, rol,
  vencido: z.boolean(), id_intervencion_confirmada: id.nullable(),
})).max(10), next_cursor: id.nullable() })
export const actaSchema = z.object({ id, case_id: id, interventions: z.array(z.object({
  id, order: id, content: z.string(), stage_id: id, participant: z.object({ role: rol }).nullable(),
  sources: z.array(z.object({ id, excerpt: z.string().nullable(), source: z.object({ title: z.string().nullable(), locator: z.string().nullable() }) })),
})) })
export const fuenteBusqueda = z.object({ id, extracto: z.string().max(10000), relevancia: z.number(),
  fuente: z.object({ archivo: z.string().optional(), pagina: z.number().nullable().optional(), localizador: z.string().nullable().optional(),
    titulo: z.string().optional(), numero_norma: z.string().nullable().optional(), version: z.string().nullable().optional(),
    tipo: z.string().optional(), fecha_vigencia: z.string().nullable().optional(), fecha_fin_vigencia: z.string().nullable().optional() }),
})
export type FuenteSeleccionable = z.output<typeof fuenteBusqueda> & { clase: 'expediente' | 'juridica' }
export const nombresRol = { juez: 'Juzgado', fiscal: 'Fiscalía', abogado_defensor: 'Defensa' } as const
export const nombresEstado: Record<Orquestacion['status'], string> = {
  preparando: 'Lista para iniciar', esperando_agente: 'Preparar intervención', esperando_defensa: 'Tu turno: defensa',
  esperando_avance: 'Etapa completada', esperando_destino: 'Elige la siguiente etapa', esperando_cierre: 'Lista para cerrar',
  finalizada: 'Práctica finalizada', bloqueada: 'Práctica no habilitada',
}
export function explicarBloqueo(codigo: string) {
  const nombres: Record<string, string> = {
    sin_vinculacion: 'Esta sesión pertenece al circuito anterior.', sin_activacion: 'Falta una activación revisada.',
    retirada: 'La versión fue retirada. Consulta con la administración.', revision_vencida: 'La revisión jurídica venció.',
    habilitacion_vencida: 'La acreditación de revisión venció.', analisis_no_aprobado: 'El análisis necesita aprobación humana.',
    participante_o_instruccion_incompatible: 'Revisa con la administración el participante y la instrucción de este turno.',
  }
  return nombres[codigo] ?? 'La práctica necesita una revisión de vigencia o configuración. Consulta con la administración.'
}
