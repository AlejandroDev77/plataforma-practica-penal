// Regresión aislada: TODO tráfico API interceptado; ninguna cuenta ni regla real.
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

const cargar = createRequire(import.meta.url)
const { chromium } = cargar(process.env.JURISSIM_PLAYWRIGHT_PATH || 'playwright')
const url = process.env.JURISSIM_UI_URL || 'http://127.0.0.1:5177'
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(url).hostname), 'Solo un frontend local aislado.')
const salida = resolve('..', 'artifacts', 'revision-reglas')
await mkdir(salida, { recursive: true })
const navegador = await chromium.launch({ headless: true, executablePath: process.env.JURISSIM_BROWSER_PATH || chromium.executablePath() })
const errores = []
const comprobadas = []
const futura = dias => new Date(Date.now() + dias * 86400000).toISOString()
const fechaBolivia = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/La_Paz', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
const tipo = { id: 1, name: 'Medidas cautelares · escenario sintético', code: 'sintetico', stages: [] }
const h = { id: 1, id_usuario_revisor: 2, id_usuario_habilitador: 1, id_tipo_audiencia: 1, alcance: 'Ámbito exclusivamente sintético, sin valor profesional.', referencia_acreditacion: 'Evidencia sintética para pruebas.', huella_acreditacion: 'a'.repeat(64), acreditacion_verificada: true, fecha_creacion: futura(-1), fecha_vencimiento: futura(3), estado: 'vigente', revocacion: null }
const v = { id: 1, id_tipo_audiencia: 1, id_usuario_autor: 1, numero_version: 1, motivo: 'Copia sintética para comprobar la lectura y sus estados.', huella_contenido: 'b'.repeat(64), fecha_creacion: futura(-1),
  revision_juridica: { legal_review_status: 'no_acreditada', review_approved: false, execution_authorized: false, requires_separate_activation: true },
  precondicion_revision: { huella_version_esperada: 'b'.repeat(64), id_ultima_revision_esperada: null, estado_revision_esperado: 'no_acreditada' },
  contenido: { formato: 1, tipo: { nombre: tipo.name, activo: true, descripcion: null }, etapas: [{ id_etapa: 1, nombre: 'Etapa sintética', orden: 1, activo: true }], transiciones: [], turnos_catalogo_referencia: [],
    propuestas: [{ id_propuesta_turno: 1, id_etapa: 1, id_usuario_creador: 1, orden: 1, rol: 'juez', acto_propuesto: 'Actuación sintética para revisión', descripcion_propuesta: 'Este contenido no configura ningún procedimiento ni pretende describir una actuación jurídica real. '.repeat(5), referencia_normativa_propuesta: 'Referencia sintética pendiente de revisión.', observaciones: null }] } }
function pagina(data, page = 1, last = 1, total = data.length) { return { data, meta: { current_page: page, last_page: last, total, per_page: 20 } } }
async function escenario({ admin = false, habilitada = true, vacio = false, error = null, conflicto = false, sinSesion = false, estado = 'no_acreditada', largo = false } = {}) {
  const contexto = await navegador.newContext({ viewport: { width: 1365, height: 950 } })
  const paginaUI = await contexto.newPage()
  const enviados = []
  let accesoHabilitacion = true
  let historial = []
  let actual = structuredClone(v)
  actual.revision_juridica.legal_review_status = estado
  actual.precondicion_revision.estado_revision_esperado = estado
  if (estado === 'revision_revocada') {
    actual.precondicion_revision.id_ultima_revision_esperada = 1
    historial = [{ id: 1, id_version_reglas: 1, id_usuario_revisor: 2, id_habilitacion: 1,
      decision: 'aprobado', alcance_revisado: h.alcance, observacion: 'Decisión sintética retirada para comprobar el historial, sin efecto jurídico.',
      fuentes_verificadas: [{ referencia: 'Fuente exclusivamente sintética.', huella_contenido: 'c'.repeat(64) }],
      vigencia_comprobada_en: fechaBolivia, fecha_creacion: futura(-1), fecha_vencimiento: futura(2),
      revocacion: { id: 1, id_usuario_responsable: 2, motivo: 'Retiro sintético para verificar que el historial se conserva.', fecha_creacion: futura(-0.5) },
      execution_authorized: false }]
  }
  if (largo) {
    actual.motivo = 'Motivo sintético prolongado '.repeat(70)
    actual.contenido.propuestas[0].descripcion_propuesta = 'Una afirmación sintética extensa para comprobar la lectura y la adaptación. '.repeat(150)
    actual.contenido.propuestas[0].referencia_normativa_propuesta = 'https://fuente-sintetica.example/' + 'localizador'.repeat(35)
  }
  paginaUI.on('pageerror', e => errores.push(e.message))
  await contexto.route('**/*', async ruta => {
    const solicitud = ruta.request()
    const direccion = new URL(solicitud.url())
    const path = direccion.pathname
    if (!path.startsWith('/api/') && !path.startsWith('/sanctum/')) {
      if (direccion.origin !== new URL(url).origin) { errores.push('Solicitud externa bloqueada'); return ruta.abort() }
      return ruta.continue()
    }
    const headers = { 'Access-Control-Allow-Origin': url, 'Access-Control-Allow-Credentials': 'true', 'Access-Control-Allow-Headers': 'content-type,x-xsrf-token,accept', 'Access-Control-Allow-Methods': 'GET,POST,OPTIONS' }
    const responder = (data, status = 200) => ruta.fulfill({ status, headers, contentType: 'application/json', body: JSON.stringify(data) })
    if (solicitud.method() === 'OPTIONS') return ruta.fulfill({ status: 204, headers })
    if (path === '/api/v1/me') return responder(sinSesion ? { message: 'No autenticado' } : { data: { id: 2, name: 'Persona revisora sintética', email: 'sintetica@example.test', roles: admin ? ['administrador_plataforma'] : [] } }, sinSesion ? 401 : 200)
    if (path === '/api/v1/expedientes') return responder(pagina([]))
    if (path === '/api/v1/tipos-audiencia') return responder({ data: [tipo] })
    if (solicitud.method() === 'POST') {
      const body = solicitud.postDataJSON()
      enviados.push({ path, body })
      await new Promise(r => setTimeout(r, 300))
      if (conflicto) return responder({ code: 'reglas_conflicto', message: 'Conflicto sintético' }, 409)
      if (path.endsWith('/revisiones')) {
        const revision = { id: 1, id_version_reglas: 1, id_usuario_revisor: 2, id_habilitacion: 1, alcance_revisado: h.alcance, ...body, fecha_creacion: nuevaFecha(), revocacion: null, execution_authorized: false }
        historial = [revision]
        actual.precondicion_revision.id_ultima_revision_esperada = 1
        actual.precondicion_revision.estado_revision_esperado = body.decision
        actual.revision_juridica.legal_review_status = body.decision
        return responder({ data: revision }, 201)
      }
      if (path.endsWith('/revocaciones')) return responder({ data: { id: 1, motivo: body.motivo } })
      if (path.endsWith('/habilitaciones')) return responder({ data: { ...h, ...body } }, 201)
      if (path.endsWith('/versiones')) return responder({ data: actual }, 201)
      errores.push('POST no previsto: ' + path); return ruta.abort()
    }
    if (error && path.endsWith('/versiones')) return responder({ message: 'Error sintético' }, error)
    if (path.endsWith('/habilitaciones')) return accesoHabilitacion ? responder(pagina(habilitada && !vacio ? [h] : [])) : responder({ message: 'Permiso retirado en escenario sintético' }, 403)
    if (path.endsWith('/versiones/1/revisiones')) return responder(pagina(historial))
    if (path.endsWith('/versiones/1')) return habilitada || admin ? responder({ data: actual }) : responder({ message: 'No disponible' }, 404)
    if (path.endsWith('/versiones')) {
      const numero = Number(direccion.searchParams.get('page') || 1)
      return responder(pagina(vacio || !habilitada && !admin ? [] : [{ ...actual, numero_version: numero }], numero, vacio ? 1 : 2, vacio ? 0 : 21))
    }
    if (path === '/api/v1/logout') return responder({})
    errores.push('API no prevista: ' + path); return ruta.abort()
  })
  return { contexto, p: paginaUI, enviados, denegarHabilitacion: () => { accesoHabilitacion = false } }
}
function nuevaFecha() { return new Date().toISOString() }
async function prueba(nombre, opciones, realizar) {
  const s = await escenario(opciones)
  try { await realizar(s); comprobadas.push(nombre); console.log('OK: ' + nombre) }
  catch (error) {
    console.error('Escenario fallido:', nombre, s.p.url(), await s.p.locator('body').innerText(), errores)
    throw error
  }
  finally { await s.contexto.close() }
}
async function abrirRevision(p) {
  await p.goto(url + '/revision-reglas/versiones/1')
  await p.getByRole('button', { name: 'Registrar revisión', exact: true }).click()
}
async function completarRevision(p) {
  await p.getByRole('textbox', { name: /Fundamento y observaciones/ }).fill('Observación exclusivamente sintética.')
  await p.getByLabel(/Fecha de comprobación de vigencia/).fill(fechaBolivia)
  await p.getByRole('textbox', { name: /Referencia de la fuente 1/ }).fill('Fuente sintética sin valor normativo.')
  await p.getByRole('textbox', { name: /Huella SHA-256/ }).fill('c'.repeat(64))
  await p.getByRole('checkbox', { name: /Confirmación de la revisión/ }).check()
}
try {
  await prueba('administración: listado, filtro y paginación', { admin: true }, async ({ p }) => {
    await p.goto(url + '/configuracion/reglas')
    await p.getByRole('link', { name: /Versión 1/ }).waitFor()
    await p.getByRole('button', { name: 'Siguiente', exact: true }).click()
    await p.getByRole('link', { name: /Versión 2/ }).waitFor()
    await p.getByLabel('Tipo de audiencia', { exact: false }).selectOption('1')
    await p.getByRole('link', { name: /Versión 1/ }).waitFor()
    await p.screenshot({ path: resolve(salida, 'versiones-escritorio.png'), fullPage: true })
  })
  await prueba('revisor sin rol administrativo puede leer y revisar su ámbito', {}, async ({ p, enviados }) => {
    await abrirRevision(p)
    await completarRevision(p)
    await p.getByRole('button', { name: 'Registrar decisión', exact: true }).click()
    await p.getByRole('status').filter({ hasText: 'La decisión quedó registrada' }).waitFor()
    assert.equal(enviados.length, 1)
    assert.equal(enviados[0].body.huella_version_esperada, v.huella_contenido)
    assert.equal(enviados[0].body.id_ultima_revision_esperada, null)
    assert.equal(enviados[0].body.estado_revision_esperado, 'no_acreditada')
    assert.equal(enviados[0].body.id_usuario_revisor, undefined)
    assert.equal(enviados[0].body.fecha_vencimiento, null)
    assert.equal(enviados[0].body.confirmacion_humana, true)
    assert.ok(!JSON.stringify(await p.evaluate(() => ({ ...localStorage }))).includes('Fuente sintética'))
  })
  await prueba('conflicto conserva entrada y bloquea reenvío; Escape devuelve el foco', { conflicto: true }, async ({ p, enviados }) => {
    await abrirRevision(p)
    for (let i = 0; i < 18; i++) {
      await p.keyboard.press('Tab')
      // El dialogo nativo puede llevar el foco a la interfaz del navegador
      // (activeElement body); nunca a controles de la pagina situada detras.
      assert.equal(await p.getByRole('dialog').evaluate(d => d.contains(document.activeElement) || document.activeElement === document.body), true, 'Sin foco en controles de fondo')
    }
    await completarRevision(p)
    await p.getByRole('button', { name: 'Registrar decisión', exact: true }).click()
    await p.getByRole('alert').filter({ hasText: 'El registro cambió' }).waitFor()
    assert.equal(await p.getByRole('textbox', { name: /Fundamento y observaciones/ }).inputValue(), 'Observación exclusivamente sintética.')
    assert.equal(await p.getByRole('button', { name: 'Registrar decisión', exact: true }).isDisabled(), true)
    assert.equal(enviados.length, 1)
    await p.keyboard.press('Escape')
    await p.getByRole('dialog').waitFor({ state: 'hidden' })
    await p.waitForFunction(() => document.activeElement?.classList.contains('reglas-back'))
  })
  await prueba('confirmación explícita, reset tras edición y doble envío bloqueado', {}, async ({ p, enviados }) => {
    await abrirRevision(p)
    await p.getByRole('button', { name: 'Registrar decisión', exact: true }).click()
    await p.getByRole('alert').filter({ hasText: 'Revise los campos' }).waitFor()
    assert.equal(enviados.length, 0)
    await completarRevision(p)
    await p.getByRole('textbox', { name: /Fundamento y observaciones/ }).fill('Edición sintética posterior.')
    assert.equal(await p.getByRole('checkbox', { name: /Confirmación de la revisión/ }).isChecked(), false)
    await p.getByRole('checkbox', { name: /Confirmación de la revisión/ }).check()
    await p.getByRole('button', { name: 'Registrar decisión', exact: true }).click()
    await p.keyboard.press('Escape')
    assert.equal(await p.getByRole('dialog').count(), 1)
    await p.locator('dialog form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })))
    await p.getByRole('status').filter({ hasText: 'La decisión quedó registrada' }).waitFor()
    assert.equal(enviados.length, 1)
  })
  await prueba('registro de habilitación con huella local y permiso humano', { admin: true }, async ({ p, enviados }) => {
    await p.goto(url + '/configuracion/reglas')
    await p.getByRole('button', { name: 'Habilitaciones', exact: true }).click()
    await p.getByRole('button', { name: 'Habilitar revisor', exact: true }).click()
    await p.getByLabel(/Identificador de la cuenta/).fill('2')
    await p.getByRole('dialog').getByLabel(/Tipo de audiencia/).selectOption('1')
    await p.getByLabel(/Alcance de la revisión/).fill('Ámbito sintético de prueba.')
    await p.getByLabel(/Vencimiento de la habilitación/).fill(futura(3).slice(0, 16))
    await p.getByLabel(/Referencia de la acreditación/).fill('Documento sintético, sin valor profesional.')
    const bytes = Buffer.from('Acreditación exclusivamente sintética.')
    await p.getByLabel(/Documento de acreditación/).setInputFiles({ name: 'sintetico.txt', mimeType: 'text/plain', buffer: bytes })
    await p.waitForFunction(huella => document.querySelector('input.reglas-mono')?.value === huella, createHash('sha256').update(bytes).digest('hex'))
    await p.getByRole('checkbox', { name: /Comprobación humana/ }).check()
    await p.getByRole('button', { name: 'Registrar habilitación', exact: true }).click()
    await p.getByRole('status').filter({ hasText: 'La habilitación quedó registrada' }).waitFor()
    assert.equal(enviados.length, 1)
    assert.equal(enviados[0].body.huella_acreditacion, createHash('sha256').update(bytes).digest('hex'))
    assert.equal(enviados[0].body.acreditacion_verificada, true)
    assert.equal(enviados[0].body.rol, undefined)
    assert.equal(enviados[0].body.archivo, undefined)
  })
  await prueba('retiro requiere motivo y confirmación, sin borrar', {}, async ({ p, enviados }) => {
    await p.goto(url + '/revision-reglas')
    await p.getByRole('button', { name: 'Mis habilitaciones', exact: true }).click()
    await p.getByRole('button', { name: 'Retirar habilitación', exact: true }).click()
    await p.getByLabel(/Motivo del retiro/).fill('Retiro exclusivamente sintético.')
    await p.getByRole('button', { name: 'Confirmar retiro', exact: true }).click()
    assert.equal(enviados.length, 0)
    await p.getByRole('checkbox', { name: /Confirmación del retiro/ }).check()
    await p.getByRole('button', { name: 'Confirmar retiro', exact: true }).click()
    await p.getByRole('status').filter({ hasText: 'La habilitación fue retirada' }).waitFor()
    assert.equal(enviados[0].path, '/api/v1/reglas-audiencia/habilitaciones/1/revocaciones')
  })
  await prueba('sin habilitación: vacío y detalle denegado; no hay botón de aprobar', { habilitada: false }, async ({ p, enviados }) => {
    await p.goto(url + '/revision-reglas')
    await p.getByRole('heading', { name: 'Sin versiones para revisar' }).waitFor()
    await p.goto(url + '/revision-reglas/versiones/1')
    await p.getByRole('alert').filter({ hasText: 'no está disponible' }).waitFor()
    assert.equal(await p.getByRole('button', { name: 'Registrar revisión', exact: true }).count(), 0)
    assert.equal(enviados.length, 0)
  })
  await prueba('sesión ausente redirige al login, sin escrituras', { sinSesion: true }, async ({ p, enviados }) => {
    await p.goto(url + '/revision-reglas')
    await p.waitForURL('**/login')
    assert.equal(enviados.length, 0)
  })
  await prueba('permiso retirado al consultar: no se reutiliza autoridad en caché', {}, async ({ p, enviados, denegarHabilitacion }) => {
    await p.goto(url + '/revision-reglas/versiones/1')
    await p.getByRole('button', { name: 'Registrar revisión', exact: true }).waitFor()
    denegarHabilitacion()
    await p.getByRole('button', { name: 'Actualizar', exact: true }).click()
    await p.getByRole('alert').filter({ hasText: 'no está disponible' }).waitFor()
    assert.equal(await p.getByRole('button', { name: 'Registrar revisión', exact: true }).count(), 0)
    assert.equal(enviados.length, 0)
  })
  await prueba('error de servicio permite consultar de nuevo, no muestra éxito', { error: 503 }, async ({ p }) => {
    await p.goto(url + '/revision-reglas')
    await p.getByRole('alert').filter({ hasText: 'No pudimos consultar' }).waitFor()
    await p.getByRole('button', { name: 'Volver a consultar', exact: true }).waitFor()
    await p.screenshot({ path: resolve(salida, 'error-escritorio.png'), fullPage: true })
  })
  await prueba('reflujo: móvil, tablet, texto largo y tamaño ampliado', { largo: true }, async ({ p }) => {
    await p.goto(url + '/revision-reglas/versiones/1')
    await p.getByRole('heading', { name: 'Versión 1', exact: true }).waitFor()
    for (const ancho of [390, 768, 1365]) {
      await p.setViewportSize({ width: ancho, height: 900 })
      assert.equal(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `Desborde a ${ancho}`)
    }
    await p.setViewportSize({ width: 390, height: 844 })
    await p.screenshot({ path: resolve(salida, 'version-movil.png'), fullPage: true })
    await p.getByRole('button', { name: 'Registrar revisión', exact: true }).click()
    assert.equal(await p.locator('dialog').evaluate(e => e.scrollWidth <= e.clientWidth + 1), true)
    await p.screenshot({ path: resolve(salida, 'revision-movil.png') })
    await p.keyboard.press('Escape')
    await p.setViewportSize({ width: 1365, height: 950 })
    await p.addStyleTag({ content: ':root { font-size: 28px !important; }' })
    assert.equal(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Texto 200%')
    await p.emulateMedia({ reducedMotion: 'reduce' })
  })
  await prueba('estado retirado visible sin recuperar aprobación histórica', { estado: 'revision_revocada' }, async ({ p }) => {
    await p.goto(url + '/revision-reglas/versiones/1')
    await p.locator('.reglas-summary').getByText('Revisión retirada', { exact: true }).waitFor()
    await p.getByRole('region', { name: 'Historial de decisiones' }).getByText('Revisión retirada', { exact: true }).waitFor()
    await p.screenshot({ path: resolve(salida, 'version-escritorio.png'), fullPage: true })
  })
  assert.deepEqual(errores, [], 'Sin errores de página ni solicitudes externas o API no previstas.')
  console.log(`${comprobadas.length} escenarios verificados. Capturas: ${salida}`)
} finally { await navegador.close() }
