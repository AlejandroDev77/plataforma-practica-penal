import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { consultarInstrucciones, datosHabilitacion, datosRetiro, datosSellado, hoyBolivia, huellaArchivo, puedeRevisar, prepararRevision } from '../src/features/audiencias/model/reglas.ts'

const futura = dias => new Date(Date.now() + dias * 86400000).toISOString()
const hab = () => ({ id: 1, id_usuario_revisor: 2, id_usuario_habilitador: 1, id_tipo_audiencia: 1, alcance: 'Ámbito sintético.', estado: 'vigente', revocacion: null, acreditacion_verificada: true, fecha_vencimiento: futura(3) })
const version = () => ({ id: 1, id_tipo_audiencia: 1, id_usuario_autor: 1, revision_juridica: { execution_authorized: false },
  precondicion_revision: { huella_version_esperada: 'a'.repeat(64), id_ultima_revision_esperada: null, estado_revision_esperado: 'no_acreditada' }, contenido: { propuestas: [{ id_usuario_creador: 1 }] } })
const datos = () => ({ decision: 'aprobado', observacion: 'Revisión exclusivamente sintética.', confirmacion_humana: true, fecha_vencimiento: futura(2), vigencia_comprobada_en: hoyBolivia(), fuentes_verificadas: [{ referencia: 'Fuente sintética sin valor jurídico.', huella_contenido: 'b'.repeat(64) }] })

test('sin habilitación no hay autoridad; ni el rol ni una bandera de ejecución la sustituyen', () => {
  assert.equal(puedeRevisar(version(), null, 2), false)
  assert.equal(puedeRevisar({ ...version(), revision_juridica: { execution_authorized: true } }, hab(), 2), false)
})
test('habilitación exacta e independiente permite preparar, no ejecutar', () => {
  assert.equal(puedeRevisar(version(), hab(), 2), true)
  const entrada = datos()
  const resultado = prepararRevision(entrada, version(), hab(), 2)
  assert.equal(resultado.confirmacion_humana, true)
  assert.equal(resultado.fecha_vencimiento, entrada.fecha_vencimiento)
  assert.equal(resultado.execution_authorized, undefined)
})
test('se rechazan ámbito, cuenta, vencimiento, retiro y acreditación incorrectos', () => {
  for (const cambio of [{ id_usuario_revisor: 9 }, { id_tipo_audiencia: 9 }, { estado: 'vencida' }, { revocacion: { id: 1 } }, { fecha_vencimiento: futura(-1) }, { acreditacion_verificada: false }]) {
    assert.equal(puedeRevisar(version(), { ...hab(), ...cambio }, 2), false)
    assert.throws(() => prepararRevision(datos(), version(), { ...hab(), ...cambio }, 2))
  }
})
test('autor propio, propuesta propia, autor desconocido y ausencia de propuestas bloquean', () => {
  for (const cambio of [{ id_usuario_autor: 2 }, { contenido: { propuestas: [{ id_usuario_creador: 2 }] } }, { contenido: { propuestas: [{ id_usuario_creador: null }] } }, { contenido: { propuestas: [] } }]) {
    assert.equal(puedeRevisar({ ...version(), ...cambio }, hab(), 2), false)
  }
})
test('la precondición procede de la copia leída, nunca de campos de entrada', () => {
  const copia = version()
  const resultado = prepararRevision({ ...datos(), id_usuario_revisor: 99, id_habilitacion: 99, huella_version_esperada: 'f'.repeat(64), id_ultima_revision_esperada: 999, estado_revision_esperado: 'aprobado' }, copia, hab(), 2)
  assert.equal(resultado.huella_version_esperada, copia.precondicion_revision.huella_version_esperada)
  assert.equal(resultado.id_ultima_revision_esperada, null)
  assert.equal(resultado.estado_revision_esperado, 'no_acreditada')
  assert.equal(resultado.id_usuario_revisor, undefined)
  assert.equal(resultado.id_habilitacion, undefined)
})
test('aprobación exige vencimiento futuro dentro de la habilitación', () => {
  for (const fecha_vencimiento of ['', futura(-1), futura(4), 'inexistente']) assert.throws(() => prepararRevision({ ...datos(), fecha_vencimiento }, version(), hab(), 2))
})
test('requiere cambios no transmite un vencimiento residual', () => {
  assert.equal(prepararRevision({ ...datos(), decision: 'requiere_cambios', fecha_vencimiento: futura(8) }, version(), hab(), 2).fecha_vencimiento, null)
})
test('fecha de vigencia debe existir y no ser futura en Bolivia', () => {
  for (const vigencia_comprobada_en of ['2037-02-30', '2000-02-30', '2099-01-01', 'ayer', '']) assert.throws(() => prepararRevision({ ...datos(), vigencia_comprobada_en }, version(), hab(), 2))
})
test('confirmación humana debe ser true, no texto o número', () => {
  for (const confirmacion_humana of [false, 'true', 1, undefined]) assert.throws(() => prepararRevision({ ...datos(), confirmacion_humana }, version(), hab(), 2))
})
test('fuentes acotadas, completas y con huellas reales de formato válido', () => {
  for (const fuentes_verificadas of [[], Array(21).fill(datos().fuentes_verificadas[0]), [{ referencia: ' ', huella_contenido: 'b'.repeat(64) }], [{ referencia: 'Sintética', huella_contenido: 'inventada' }]]) {
    assert.throws(() => prepararRevision({ ...datos(), fuentes_verificadas }, version(), hab(), 2))
  }
  const resultado = prepararRevision({ ...datos(), fuentes_verificadas: [{ ...datos().fuentes_verificadas[0], clave: 'solo-ui', documento_privado: 'no-enviar' }] }, version(), hab(), 2)
  assert.equal(resultado.fuentes_verificadas[0].clave, undefined)
  assert.equal(resultado.fuentes_verificadas[0].documento_privado, undefined)
})
test('sellado y retiro exigen motivo y confirmación, sin flags de activación', () => {
  assert.throws(() => datosSellado.parse({ id_tipo_audiencia: 1, motivo: '', confirmado: true }))
  assert.throws(() => datosRetiro.parse({ motivo: 'Sintético', confirmado: false }))
  assert.equal(datosSellado.parse({ id_tipo_audiencia: '1', motivo: 'Sintético', confirmado: true, activo: true }).activo, undefined)
})
test('habilitación requiere evidencia, ámbito, cuenta y fecha; no serializa privilegios', () => {
  const evidencia = { id_usuario_revisor: '2', id_tipo_audiencia: '1', alcance: 'Sintético', referencia_acreditacion: 'Sintética', huella_acreditacion: 'a'.repeat(64), fecha_vencimiento: futura(3), acreditacion_verificada: true }
  assert.equal(datosHabilitacion.parse({ ...evidencia, rol: 'administrador_plataforma' }).rol, undefined)
  for (const cambio of [{ acreditacion_verificada: 1 }, { alcance: ' ' }, { huella_acreditacion: 'falsa' }, { id_usuario_revisor: '' }, { fecha_vencimiento: futura(-1) }]) assert.throws(() => datosHabilitacion.parse({ ...evidencia, ...cambio }))
})
test('huella local corresponde exactamente a los bytes; ningún upload ni API', async () => {
  const contenido = 'Documento sintético, sin valor profesional.'
  const resultado = await huellaArchivo(new File([contenido], 'sintetico.txt'))
  assert.equal(resultado, createHash('sha256').update(contenido).digest('hex'))
})
test('huella rechaza archivos superiores a 20 MB antes de leer bytes', async () => {
  await assert.rejects(() => huellaArchivo({ size: 20 * 1024 * 1024 + 1, arrayBuffer() { throw new Error('No debe leer') } }), /20 MB/)
})

const versionInstrucciones = () => ({ ...version(), huella_contenido: 'a'.repeat(64),
  revision_instrucciones: { estado: 'disponible', requiere_confirmacion: true },
  contenido: { formato: 1, etapas: [{ id_etapa: 1 }],
    propuestas: [{ id_propuesta_turno: 1, id_usuario_creador: 1, id_etapa: 1, orden: 1, rol: 'juez', acto_propuesto: 'Acto sintético.', descripcion_propuesta: 'Solo pruebas.' }],
    contrato_ejecucion: { formato: 1, perfil: 'mvp-defensa-aciclico-ejecucion-v1', rol_usuario: 'abogado_defensor',
      instrucciones: [{ id_propuesta_turno: 1, texto: '  Instrucción sintética.\nTexto exacto.  ' }] } } })

test('contrato conserva espacios, saltos y Unicode completos, sin transformar instrucciones', () => {
  const v = versionInstrucciones()
  const antes = structuredClone(v)
  assert.equal(consultarInstrucciones(v).contrato.instrucciones[0].texto, v.contenido.contrato_ejecucion.instrucciones[0].texto)
  assert.deepEqual(v, antes)
  v.contenido.contrato_ejecucion.instrucciones[0].texto = '🧑'.repeat(5000)
  assert.equal(consultarInstrucciones(v).estado, 'disponible')
  v.contenido.contrato_ejecucion.instrucciones[0].texto += 'a'
  assert.equal(consultarInstrucciones(v).estado, 'incompatible')
})

test('lectura incompleta, perfil desconocido, huella o asociación incorrecta bloquean revisión', () => {
  const mutar = [
    v => { delete v.contenido.contrato_ejecucion },
    v => { delete v.revision_instrucciones },
    v => { v.revision_instrucciones.estado = 'incompatible' },
    v => { v.contenido.contrato_ejecucion.perfil = 'desconocido' },
    v => { v.contenido.contrato_ejecucion.extra = true },
    v => { v.contenido.contrato_ejecucion.instrucciones = [] },
    v => { v.contenido.contrato_ejecucion.instrucciones[0].id_propuesta_turno = '1' },
    v => { v.contenido.contrato_ejecucion.instrucciones[0].id_propuesta_turno = 2 },
    v => { v.contenido.contrato_ejecucion.instrucciones[0].texto = '\u00a0\u0085' },
    v => { v.contenido.contrato_ejecucion.instrucciones.push(v.contenido.contrato_ejecucion.instrucciones[0]) },
    v => { v.precondicion_revision.huella_version_esperada = 'f'.repeat(64) },
    v => { v.contenido.propuestas[0].rol = 'imputado' },
    v => { v.contenido.etapas = [] },
  ]
  for (const cambiar of mutar) {
    const v = versionInstrucciones()
    cambiar(v)
    assert.equal(consultarInstrucciones(v).estado, 'incompatible')
    assert.equal(puedeRevisar(v, hab(), 2), false)
    assert.throws(() => prepararRevision({ ...datos(), confirmacion_instrucciones: true }, v, hab(), 2))
  }
})

test('las dos confirmaciones son independientes y estrictas; la huella procede de la versión', () => {
  const v = versionInstrucciones()
  for (const confirmacion_instrucciones of [undefined, false, 1, 'true']) assert.throws(() => prepararRevision({ ...datos(), confirmacion_instrucciones }, v, hab(), 2))
  assert.throws(() => prepararRevision({ ...datos(), confirmacion_humana: false, confirmacion_instrucciones: true }, v, hab(), 2))
  const r = prepararRevision({ ...datos(), confirmacion_instrucciones: true, huella_ejecucion_revisada: 'f'.repeat(64), contrato_ejecucion: { falsificado: true } }, v, hab(), 2)
  assert.equal(r.huella_ejecucion_revisada, v.huella_contenido)
  assert.equal(r.confirmacion_instrucciones, true)
  assert.equal(r.contrato_ejecucion, undefined)
  assert.equal(r.execution_authorized, undefined)
})

test('una copia histórica no transmite cobertura inventada ni confirma instrucciones nuevas', () => {
  const v = version()
  assert.equal(consultarInstrucciones(v).estado, 'ausente')
  const r = prepararRevision({ ...datos(), confirmacion_instrucciones: true, huella_ejecucion_revisada: 'a'.repeat(64) }, v, hab(), 2)
  assert.equal(r.confirmacion_instrucciones, undefined)
  assert.equal(r.huella_ejecucion_revisada, undefined)
})

test('requiere cambios sobre instrucciones conserva cobertura, sin vencimiento ni activación', () => {
  const v = versionInstrucciones()
  const r = prepararRevision({ ...datos(), decision: 'requiere_cambios', confirmacion_instrucciones: true }, v, hab(), 2)
  assert.equal(r.fecha_vencimiento, null)
  assert.equal(r.huella_ejecucion_revisada, v.huella_contenido)
  assert.equal(r.confirmacion_instrucciones, true)
})
