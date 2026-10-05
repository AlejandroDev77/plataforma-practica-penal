import test from 'node:test'
import assert from 'node:assert/strict'
import { orq, borrador } from './fixtures/practicas.mjs'
import { validarOrquestacion, prepararMovimiento, prepararGeneracion, prepararDefensa, validarBorrador, proveedorSchema, claveContexto, coincideTurno, paginaBorradores } from '../src/features/practicas/model/practica-reglada.ts'
const externo = { provider: 'openai', model: 'sintetico', requires_external_authorization: true }
test('acepta cada estado observado; nunca declara autorización', () => {
  for (let n = 0; n < 8; n++) {
    const d = validarOrquestacion(orq(n), 21)
    assert.equal(d.execution_authorized, false); assert.deepEqual(d.allowed_actions, [])
  }
})
test('rechaza consultas ajenas, estados inconsistentes y permisos inventados', () => {
  assert.throws(() => validarOrquestacion(orq(), 22))
  for (const extra of [{ execution_authorized: true }, { allowed_actions: ['iniciar'] }, { technical_candidate_actions: ['finalizar'] }, { requires_provider_check: true }, { binding_status: 'sin_vinculacion' }]) assert.throws(() => validarOrquestacion({ ...orq(), ...extra }, 21))
})
test('bloqueo conserva diagnóstico pero no acciones; herencia solo sin vinculación real', () => {
  const d = { ...orq(1), status: 'bloqueada', technical_candidate_actions: [], requires_provider_check: false }
  assert.equal(validarOrquestacion(d, 21).status, 'bloqueada')
  assert.throws(() => prepararGeneracion(d, [], externo, true))
  const legacy = { ...d, binding_id: null, version_id: null, activation_id: null, binding_status: 'sin_vinculacion', expected_context: null, current_turn: null }
  assert.equal(validarOrquestacion(legacy, 21).binding_status, 'sin_vinculacion')
  assert.throws(() => validarOrquestacion({ ...legacy, binding_id: 1 }, 21))
})
test('inicio y cierre llevan exclusivamente precondiciones del servidor', () => {
  assert.deepEqual(prepararMovimiento(orq(), 'iniciar'), { accion: 'iniciar', estado_esperado: 'preparando', id_etapa_esperada: null })
  assert.deepEqual(prepararMovimiento(orq(6), 'finalizar'), { accion: 'finalizar', estado_esperado: 'activa', id_etapa_esperada: 13 })
  assert.throws(() => prepararMovimiento(orq(7), 'iniciar'))
})
test('no acepta destinos fuera de la copia ni bifurcación implícita', () => {
  assert.equal(prepararMovimiento(orq(2), 'avanzar', 12).id_destino, 12)
  assert.throws(() => prepararMovimiento(orq(2), 'avanzar', 99))
  const d = { ...orq(2), status: 'esperando_destino', candidate_next_stages: [...orq(2).candidate_next_stages, { id_etapa: 14, nombre: 'Otro destino sintético' }] }
  assert.throws(() => prepararMovimiento(d, 'avanzar'))
  assert.equal(prepararMovimiento(d, 'avanzar', 14).id_destino, 14)
})
test('consentimiento externo debe ser booleano true para este envío', () => {
  for (const valor of [false, undefined, 'true', 1]) assert.throws(() => prepararGeneracion(orq(1), [51], externo, valor))
  assert.deepEqual(prepararGeneracion(orq(1), [51], externo, true), { id_movimiento_esperado: 201, id_etapa_esperada: 11, id_turno_esperado: 101, orden_esperado: 1, ids_fragmento: [51], autorizar_externo: true })
  assert.equal(prepararGeneracion(orq(1), [], { provider: 'ollama', model: 'sintetico', requires_external_authorization: false }, true).autorizar_externo, false)
})
test('sin proveedor verificado ni turno compatible no hay generación', () => {
  assert.throws(() => proveedorSchema.parse({ ...externo, requires_external_authorization: false }))
  assert.throws(() => prepararGeneracion(orq(5), [], externo, true))
  assert.throws(() => prepararGeneracion({ ...orq(1), current_participant: { id: 31, rol: 'juez', controlado_por: 'usuario' } }, [], externo, true))
})
test('selección explícita, acotada, distinta y JSON numérica', () => {
  for (const ids of [[51,51], ['51'], [0], Array.from({ length: 9 }, (_, n) => n + 1)]) assert.throws(() => prepararGeneracion(orq(1), ids, externo, true))
  assert.deepEqual(prepararGeneracion(orq(1), [], externo, true).ids_fragmento, [])
})
test('defensa conserva texto; no copia hechos ni propietario', () => {
  const p = prepararDefensa(orq(5), [51], 'Texto sintético\ncon referencias.')
  assert.deepEqual(Object.keys(p), ['id_movimiento_esperado', 'id_etapa_esperada', 'id_turno_esperado', 'orden_esperado', 'contenido', 'ids_fragmento'])
  assert.equal(p.contenido, 'Texto sintético\ncon referencias.')
  for (const texto of ['', ' \n\u0085', 'x'.repeat(2001)]) assert.throws(() => prepararDefensa(orq(5), [], texto))
  assert.throws(() => prepararDefensa(orq(1), [], 'Texto'))
})
test('borrador privado, no ejecutable y citas solo enviadas', () => {
  const b = validarBorrador({ ...borrador(1, [51]), preparacion: { secreto: true } }, 21)
  assert.equal('preparacion' in b, false)
  assert.throws(() => validarBorrador(b, 22))
  assert.throws(() => validarBorrador({ ...b, confirmation_authorized: true }, 21))
  assert.throws(() => validarBorrador({ ...b, respuesta: { ...b.respuesta, used_source_ids: [999] } }, 21))
  assert.throws(() => validarBorrador({ ...b, autorizar_externo: false }, 21))
})
test('cambio de turno invalida contexto y recuperación', () => {
  assert.equal(coincideTurno(borrador(), orq(1)), true)
  assert.equal(coincideTurno(borrador(), orq(3)), false)
  assert.notEqual(claveContexto(orq(1)), claveContexto(orq(3)))
})
test('extracto enviado conserva el límite Unicode del servidor', () => {
  const b = borrador(1, [51])
  b.fuentes_enviadas[0].excerpt = '𐐀'.repeat(1500)
  assert.equal(validarBorrador(b, 21).fuentes_enviadas[0].excerpt, b.fuentes_enviadas[0].excerpt)
  assert.throws(() => validarBorrador({ ...b, fuentes_enviadas: [b.fuentes_enviadas[0], b.fuentes_enviadas[0]] }, 21))
  b.fuentes_enviadas[0].excerpt += 'a'
  assert.throws(() => validarBorrador(b, 21))
})
test('historial nunca contiene textos y limita la página a diez', () => {
  const b = borrador(), fila = { ...b, rol: 'juez' }
  const p = paginaBorradores.parse({ data: [fila], next_cursor: null })
  assert.equal('respuesta' in p.data[0], false)
  assert.throws(() => paginaBorradores.parse({ data: Array(11).fill(fila), next_cursor: null }))
})
