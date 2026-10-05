// Datos exclusivamente sintéticos. No describen un procedimiento jurídico real.
export const fecha = nueva => new Date(Date.now() + nueva * 86400000).toISOString()
export const tipo = { id: 1, code: 'sintetico', name: 'Medidas cautelares · práctica sintética', stages: [] }
export const activacion = { id: 5, id_tipo_audiencia: 1, id_version_reglas: 3, id_revision_reglas: 4, id_usuario_responsable: 2, huella_version: 'b'.repeat(64), id_activacion_previa: null, motivo: 'Activación exclusivamente sintética.', fecha_creacion: fecha(-1), revocacion: null, execution_authorized: false, requires_simulation_binding: true }
export const version = { id: 3, id_tipo_audiencia: 1, numero_version: 2, huella_contenido: 'b'.repeat(64) }
export const preparacion = { id_version_reglas: 3, id_tipo_audiencia: 1, puede_solicitar_activacion: false, bloqueos: ['revision_ya_utilizada'], precondicion: { huella_version_esperada: 'b'.repeat(64), id_revision_esperada: 4, id_activacion_previa: 5 }, activacion_actual: { activation_id: 5, version_id: 3, review_id: 4, huella_version: 'b'.repeat(64), activation_status: 'vigente', activation_current: true, read_only: true, execution_authorized: false, requires_simulation_binding: true }, read_only: true, execution_authorized: false, requires_simulation_binding: true }
export const caso = { id: 7, title: 'Caso de prueba · sin datos personales', description: 'Ejercicio sintético.', case_number: null, status: 'borrador', processing_status: 'procesado', file_count: 0, files: [], created_at: fecha(-1), updated_at: null }
export const analisis = { id: 9, version: 1, status: 'procesado', review_state: 'aprobado', summary: null, procedural_stage: null, participants: [], offenses: [], facts: [], evidence: [], chronology: [], missing_information: [], uncertainties: [], reviews: [] }
export const fuentes = [{ id: 51, extracto: 'Hecho documental exclusivamente sintético. <script>window.__inyectado=true</script>', relevancia: .8, fuente: { archivo: 'Documento de práctica.pdf', pagina: 1, localizador: 'Página 1' } }, { id: 52, extracto: 'Segundo extracto sintético.', relevancia: .7, fuente: { archivo: 'Referencia de práctica.pdf', pagina: 2, localizador: 'Página 2' } }]
export function orq(paso = 0) {
  const estados = ['preparando', 'esperando_agente', 'esperando_avance', 'esperando_agente', 'esperando_avance', 'esperando_defensa', 'esperando_cierre', 'finalizada']
  const acciones = ['iniciar', 'proponer_agente', 'avanzar', 'proponer_agente', 'avanzar', 'registrar_defensa', 'finalizar', null]
  const etapa = paso === 0 ? null : paso <= 2 ? 11 : paso <= 4 ? 12 : 13
  const turno = paso === 1 ? 101 : paso === 3 ? 102 : paso === 5 ? 103 : null
  const rol = paso === 1 ? 'juez' : paso === 3 ? 'fiscal' : 'abogado_defensor'
  return { simulation_id: 21, binding_id: 1, version_id: 3, activation_id: 5, binding_status: 'vigente', status: estados[paso], simulation_state: paso === 0 ? 'preparando' : paso === 7 ? 'finalizada' : 'activa',
    read_only: true, execution_authorized: false, requires_command_integration: false, allowed_actions: [], technical_candidate_actions: acciones[paso] ? [acciones[paso]] : [], blocking_reasons: [], requires_provider_check: acciones[paso] === 'proponer_agente',
    type: { id_tipo_audiencia: 1, nombre: tipo.name }, stage: etapa ? { id_etapa: etapa, nombre: etapa === 11 ? 'Apertura sintética' : etapa === 12 ? 'Exposición sintética' : 'Defensa sintética' } : null,
    current_participant: turno ? { id: rol === 'juez' ? 31 : rol === 'fiscal' ? 32 : 33, rol, controlado_por: rol === 'abogado_defensor' ? 'usuario' : 'ia' } : null,
    current_turn: turno ? { id_propuesta_turno: turno, rol, instruccion: 'Instrucción exclusivamente sintética. No representa una regla boliviana.\n<script>window.__inyectado=true</script>' } : null,
    expected_context: { estado_esperado: paso === 0 ? 'preparando' : paso === 7 ? 'finalizada' : 'activa', id_etapa_esperada: etapa, id_movimiento_esperado: etapa ? etapa + 190 : null, id_turno_esperado: turno, orden_esperado: paso <= 1 ? 1 : paso <= 3 ? 2 : paso <= 5 ? 3 : 4 },
    candidate_next_stages: paso === 2 ? [{ id_etapa: 12, nombre: 'Exposición sintética' }] : paso === 4 ? [{ id_etapa: 13, nombre: 'Defensa sintética' }] : [], completed_turns: [2, 4, 6, 7].includes(paso) ? 1 : 0, stage_turn_count: 1 }
}
export function borrador(paso = 1, ids = [], numero = 61) {
  const d = orq(paso), c = d.expected_context
  return { id_borrador: numero, id_simulacion: 21, id_movimiento: c.id_movimiento_esperado, id_etapa: c.id_etapa_esperada, id_propuesta_turno: c.id_turno_esperado, orden: c.orden_esperado,
    respuesta: { speaker_role: d.current_turn.rol, content: 'Intervención exclusivamente sintética. <script>window.__inyectado=true</script>', used_source_ids: ids, requires_human_review: true },
    proveedor: 'openai', modelo_ia: 'modelo-sintetico', autorizar_externo: true, fuentes_enviadas: ids.map(id => ({ id, kind: 'expediente', title: fuentes.find(f => f.id === id).fuente.archivo, locator: 'Página sintética', excerpt: fuentes.find(f => f.id === id).extracto })),
    fecha_creacion: fecha(0), fecha_vencimiento: new Date(Date.now() + 600000).toISOString(), vencido: false, id_intervencion_confirmada: null, requires_human_review: true, confirmation_authorized: false }
}
export function simulacion(paso = 0, intervenciones = []) {
  return { id: 21, case_id: 7, analysis_id: 9, user_role: 'abogado_defensor', status: orq(paso).simulation_state, current_turn: { configured: false, complete: false, order: null, role: null, allowed_actions: [] }, created_at: fecha(-1), started_at: fecha(-1), finished_at: null, hearing_type: tipo, current_stage: null, participants: [], interventions: intervenciones }
}
export const pagina = data => ({ data, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length } })
