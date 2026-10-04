import test from 'node:test'
import assert from 'node:assert/strict'
import { validarPreparacionActivacion, prepararSolicitudActivacion, prepararRetiroActivacion, registroActivacion, explicarBloqueoActivacion } from '../src/features/audiencias/model/activacion-reglas.ts'

const consulta = () => ({
  id_version_reglas: 3, id_tipo_audiencia: 2, puede_solicitar_activacion: true, bloqueos: [],
  precondicion: { huella_version_esperada: 'a'.repeat(64), id_revision_esperada: 4, id_activacion_previa: null },
  activacion_actual: { activation_id: null, version_id: null, review_id: null, huella_version: null,
    activation_status: 'sin_activacion', activation_current: false, read_only: true, execution_authorized: false, requires_simulation_binding: true },
  read_only: true, execution_authorized: false, requires_simulation_binding: true,
})
const validar = p => validarPreparacionActivacion(p, 3, 2, 'a'.repeat(64))
test('solicitud conserva precondiciones consultadas sin ids de responsable ni permisos', () => {
  const p = validar(consulta())
  assert.deepEqual(prepararSolicitudActivacion({ motivo: ' Prueba sintética. ', confirmacion_humana: true, id_usuario_responsable: 7, execution_authorized: true }, p),
    { motivo: 'Prueba sintética.', confirmacion_humana: true, ...p.precondicion })
})
test('consulta rechaza otra versión, tipo o huella y respuestas parciales', () => {
  for (const cambio of [{ id_version_reglas: 5 }, { id_tipo_audiencia: 1 }, { read_only: false }, { execution_authorized: true }, { requires_simulation_binding: false }, { precondicion: {} }]) {
    assert.throws(() => validar({ ...consulta(), ...cambio }))
  }
  const p = consulta(); p.precondicion.huella_version_esperada = 'b'.repeat(64)
  assert.throws(() => validar(p))
})
test('estado actual y activación previa deben coincidir; nunca restaurar un histórico', () => {
  const p = consulta()
  p.precondicion.id_activacion_previa = 8
  p.activacion_actual = { ...p.activacion_actual, activation_id: 8, version_id: 1, review_id: 7, huella_version: 'b'.repeat(64), activation_status: 'retirada' }
  assert.equal(validar(p).activacion_actual.activation_current, false)
  for (const cambio of [{ activation_id: 9 }, { activation_current: true }, { activation_status: 'sin_activacion' }, { version_id: null }, { execution_authorized: true }]) {
    assert.throws(() => validar({ ...p, activacion_actual: { ...p.activacion_actual, ...cambio } }))
  }
})
test('bloqueos y revisión ausente impiden solicitud aunque se fuerce la interfaz', () => {
  const p = consulta(); p.bloqueos = ['revision_ya_utilizada']; p.puede_solicitar_activacion = false
  assert.throws(() => prepararSolicitudActivacion({ motivo: 'Prueba.', confirmacion_humana: true }, validar(p)))
  p.puede_solicitar_activacion = true
  assert.throws(() => validar(p))
  const incompleta = consulta(); incompleta.precondicion.id_revision_esperada = null
  assert.throws(() => validar(incompleta))
})
test('motivo y confirmación son estrictos en activación y retiro', () => {
  for (const valor of [false, 1, 'true', undefined]) {
    assert.throws(() => prepararSolicitudActivacion({ motivo: 'Prueba.', confirmacion_humana: valor }, consulta()))
    assert.throws(() => prepararRetiroActivacion({ motivo: 'Prueba.', confirmacion_humana: valor }))
  }
  for (const motivo of ['', '  ', '\u0085', '\u00a0', 'a'.repeat(2001)]) assert.throws(() => prepararRetiroActivacion({ motivo, confirmacion_humana: true }))
})
test('historial es evidencia sin permiso, fechas/ids inválidos fallan y estados desconocidos no se muestran crudos', () => {
  const r = { id: 1, id_tipo_audiencia: 2, id_version_reglas: 3, id_revision_reglas: 4, id_usuario_responsable: 5, huella_version: 'a'.repeat(64),
    id_activacion_previa: null, motivo: 'Prueba.', fecha_creacion: '2026-10-04T10:00:00-04:00', revocacion: null, execution_authorized: false, requires_simulation_binding: true }
  assert.equal(registroActivacion.parse(r).execution_authorized, false)
  for (const cambio of [{ execution_authorized: true }, { id: '1' }, { fecha_creacion: 'desconocida' }, { revocacion: {} }]) assert.throws(() => registroActivacion.parse({ ...r, ...cambio }))
  assert.equal(explicarBloqueoActivacion('SQL privado'), 'La estructura de esta copia necesita ajustes antes de la activación.')
})
