// Navegador aislado: TODO tráfico API interceptado, ninguna clave, proveedor ni BD real.
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'
import { activacion, version, preparacion, tipo, caso, analisis, fuentes, orq, borrador, simulacion, pagina } from './fixtures/practicas.mjs'
const require = createRequire(import.meta.url)
const { chromium } = require(process.env.JURISSIM_PLAYWRIGHT_PATH || 'playwright')
const url = process.env.JURISSIM_UI_URL || 'http://127.0.0.1:5177'
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(url).hostname))
const salida = resolve(process.env.JURISSIM_UI_ARTIFACT_DIR || '../artifacts/practicas-regladas')
await mkdir(salida, { recursive: true })
const browser = await chromium.launch({ headless: true, executablePath: process.env.JURISSIM_BROWSER_PATH || chromium.executablePath() })
const errores = [], comprobaciones = []
let ultimaPagina
async function escenario(op = {}) {
  const context = await browser.newContext({ viewport: { width: 1365, height: 950 }, reducedMotion: 'reduce' })
  const page = await context.newPage()
  ultimaPagina = page
  const s = { paso: op.paso ?? 0, drafts: op.recuperar ? [borrador()] : [], acta: [], posts: [], gets: [], versionVigente: op.versionVigente !== false, conflicto: op.conflicto ?? false, errorOrq: op.errorOrq ?? false, ...op }
  if (op.vencido) s.drafts[0] = { ...borrador(), vencido: true }
  if (op.vencePronto) s.drafts[0] = { ...borrador(), fecha_vencimiento: new Date(Date.now() + 3000).toISOString() }
  page.on('pageerror', e => errores.push(e.message))
  await context.route('**/*', async route => {
    const req = route.request(), u = new URL(req.url()), p = u.pathname, method = req.method()
    if (!p.startsWith('/api/') && !p.startsWith('/sanctum/')) {
      if (u.origin !== new URL(url).origin) { errores.push('Conexión externa bloqueada'); return route.abort() }
      return route.continue()
    }
    const headers = { 'Access-Control-Allow-Origin': url, 'Access-Control-Allow-Credentials': 'true', 'Access-Control-Allow-Headers': 'content-type,x-xsrf-token,accept', 'Access-Control-Allow-Methods': 'GET,POST,OPTIONS' }
    const responder = (data, status = 200) => route.fulfill({ status, headers, contentType: 'application/json', body: JSON.stringify(data) })
    if (method === 'OPTIONS') return route.fulfill({ status: 204, headers })
    if (method === 'POST') {
      const body = req.postDataJSON(); s.posts.push({ p, body })
      await new Promise(r => setTimeout(r, 120))
      if (s.conflicto) return responder({ message: 'Conflicto sintético' }, 409)
      if (p === '/api/v1/practicas-regladas/expedientes/7') return responder({ data: { id_simulacion: 21, estado: 'preparando' } }, 201)
      if (p === '/api/v1/practicas-regladas/21/fuentes') return responder({ data: { expediente: fuentes, juridica: [] } })
      if (p.endsWith('/movimientos')) {
        const antes = orq(s.paso)
        assert.equal(body.estado_esperado, antes.simulation_state)
        assert.equal(body.id_etapa_esperada, antes.expected_context.id_etapa_esperada)
        assert.deepEqual(Object.keys(body).sort(), ['accion', 'estado_esperado', 'id_etapa_esperada', ...(body.id_destino ? ['id_destino'] : [])].sort())
        assert.equal(body.accion, s.paso === 0 ? 'iniciar' : s.paso === 6 ? 'finalizar' : 'avanzar')
        s.paso++; return responder({ data: { id_simulacion: 21, estado: orq(s.paso).simulation_state } })
      }
      if (p === '/api/v1/practicas-regladas/21/borradores') {
        const b = borrador(s.paso, body.ids_fragmento, 61 + s.drafts.length)
        const esperado = orq(s.paso).expected_context
        assert.deepEqual(body, { id_movimiento_esperado: esperado.id_movimiento_esperado, id_etapa_esperada: esperado.id_etapa_esperada, id_turno_esperado: esperado.id_turno_esperado, orden_esperado: esperado.orden_esperado, ids_fragmento: body.ids_fragmento, autorizar_externo: true })
        s.drafts.unshift(b); return responder({ data: b }, 201)
      }
      if (p.endsWith('/confirmacion')) {
        assert.deepEqual(body, { confirmacion_humana: true })
        const id = Number(p.split('/').at(-2)), b = s.drafts.find(b => b.id_borrador === id)
        assert.ok(b); assert.equal(b.vencido, false)
        b.id_intervencion_confirmada = s.acta.length + 1
        s.acta.push({ id: b.id_intervencion_confirmada, order: b.orden, stage_id: b.id_etapa, participant: { role: b.respuesta.speaker_role }, content: b.respuesta.content, sources: [] })
        s.paso++; return responder({ data: { id_intervencion: b.id_intervencion_confirmada } })
      }
      if (p.endsWith('/defensa')) {
        const e = orq(s.paso).expected_context
        assert.deepEqual(body, { id_movimiento_esperado: e.id_movimiento_esperado, id_etapa_esperada: e.id_etapa_esperada, id_turno_esperado: e.id_turno_esperado, orden_esperado: e.orden_esperado, contenido: body.contenido, ids_fragmento: body.ids_fragmento })
        s.acta.push({ id: 3, order: 3, stage_id: 13, participant: { role: 'abogado_defensor' }, content: body.contenido, sources: [] })
        s.paso++; return responder({ data: { id_intervencion: 3 } }, 201)
      }
      errores.push('POST inesperado bloqueado: ' + p); return route.abort()
    }
    s.gets.push(p)
    if (p === '/api/v1/me') return responder({ data: { id: 2, name: 'Persona sintética', email: 'prueba@example.test', roles: ['administrador_plataforma'] } })
    if (p === '/api/v1/expedientes') return responder(pagina([caso]))
    if (p === '/api/v1/expedientes/7') return responder({ data: caso })
    if (p === '/api/v1/expedientes/7/analisis') return responder({ data: analisis })
    if (p === '/api/v1/expedientes/7/simulaciones') return responder(pagina([]))
    if (p === '/api/v1/tipos-audiencia') return responder({ data: [tipo] })
    if (p === '/api/v1/reglas-audiencia/tipos/1/activaciones') return responder(pagina(s.versionVigente ? [activacion] : []))
    if (p === '/api/v1/reglas-audiencia/versiones/3') return responder({ data: version })
    if (p.endsWith('/versiones/3/preparacion-activacion')) return responder({ data: preparacion })
    if (p === '/api/v1/practicas-regladas/21/orquestacion') {
      if (s.errorOrq) return responder({ message: 'Error sintético' }, 503)
      let d = orq(s.paso)
      if (s.largo && d.current_turn) d.current_turn.instruccion = 'Instrucción sintética prolongada. '.repeat(100) + '\nhttps://ejemplo.test/' + 'localizador'.repeat(80)
      if (s.invalido) d.execution_authorized = true
      if (s.bloqueado || s.legacy) d = { simulation_id: 21, binding_id: s.legacy ? null : 1, version_id: s.legacy ? null : 3, activation_id: s.legacy ? null : 5, binding_status: s.legacy ? 'sin_vinculacion' : 'retirada', status: 'bloqueada', read_only: true, execution_authorized: false, requires_command_integration: false, allowed_actions: [], technical_candidate_actions: [], blocking_reasons: ['retirada'], requires_provider_check: false, expected_context: null, current_turn: null }
      return responder({ data: d })
    }
    if (p === '/api/v1/practicas-regladas/21/proveedor') return responder(s.falloProveedor ? { message: 'Fallo sintético' } : { data: { provider: 'openai', model: 'modelo-sintetico', requires_external_authorization: true } }, s.falloProveedor ? 503 : 200)
    if (p === '/api/v1/practicas-regladas/21/borradores') return responder({ data: s.drafts.map(b => ({ id_borrador: b.id_borrador, id_movimiento: b.id_movimiento, id_etapa: b.id_etapa, id_propuesta_turno: b.id_propuesta_turno, orden: b.orden, rol: b.respuesta.speaker_role, vencido: b.vencido, id_intervencion_confirmada: b.id_intervencion_confirmada })), next_cursor: null })
    if (/\/practicas-regladas\/21\/borradores\/\d+$/.test(p)) return responder({ data: s.drafts.find(b => b.id_borrador === Number(p.split('/').at(-1))) })
    if (p === '/api/v1/simulaciones/21') return responder({ data: simulacion(s.paso, s.acta) })
    if (p === '/api/v1/practicas-regladas/21/acta') return responder({ data: { id: 21, case_id: 7, interventions: s.acta } })
    errores.push('GET inesperado bloqueado: ' + p); return route.abort()
  })
  return { context, page, s }
}
async function visible(page, nombre) { await page.getByRole('button', { name: nombre, exact: true }).waitFor() }
async function buscar(page) {
  await page.getByLabel('Buscar en el expediente y las normas').fill('hecho sintético')
  await page.getByRole('button', { name: 'Buscar fuentes', exact: true }).click()
  await page.getByRole('checkbox', { name: /Documento de práctica/ }).waitFor()
}
async function generar(page) {
  const autorizacion = page.getByRole('checkbox', { name: /Autorizo enviar/ })
  await autorizacion.check()
  await page.getByRole('button', { name: 'Preparar borrador', exact: true }).evaluate(b => { b.click(); b.click() })
  await page.getByRole('heading', { name: /Borrador de/ }).waitFor()
}
async function confirmar(page) {
  const btn = page.getByRole('button', { name: 'Confirmar e incorporar al acta', exact: true })
  assert.equal(await btn.isDisabled(), true)
  await page.getByRole('checkbox', { name: /He revisado el texto/ }).check()
  await btn.evaluate(b => { b.click(); b.click() })
  await visible(page, 'Continuar a la siguiente etapa')
}
async function sinDesbordamiento(page) {
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, 'La vista no debe desbordar horizontalmente')
  assert.equal(await page.evaluate(() => window.__inyectado), undefined)
}
try {
  {
    const { context, page, s } = await escenario()
    await page.goto(url + '/expedientes/7#simulaciones')
    await page.getByLabel('Tipo de audiencia', { exact: true }).selectOption('1')
    await visible(page, 'Crear práctica')
    assert.equal(await page.getByRole('button', { name: 'Crear práctica', exact: true }).isDisabled(), true)
    assert.equal(s.posts.length, 0)
    await page.getByRole('checkbox', { name: 'Usar esta versión revisada para mi práctica.' }).check()
    await page.getByRole('button', { name: 'Crear práctica', exact: true }).evaluate(b => { b.click(); b.click() })
    await visible(page, 'Iniciar práctica')
    assert.deepEqual(s.posts[0], { p: '/api/v1/practicas-regladas/expedientes/7', body: { id_analisis: 9, id_tipo_audiencia: 1, id_activacion: 5 } })
    assert.equal(s.posts.length, 1)
    await page.getByRole('button', { name: 'Iniciar práctica', exact: true }).click()
    await visible(page, 'Preparar borrador')
    assert.equal(await page.getByRole('button', { name: 'Preparar borrador', exact: true }).isDisabled(), true)
    assert.equal(s.posts.length, 2)
    await buscar(page)
    const consent = page.getByRole('checkbox', { name: /Autorizo enviar/ })
    await consent.check()
    await page.getByRole('checkbox', { name: /Documento de práctica/ }).check()
    assert.equal(await consent.isChecked(), false, 'Cambiar fuentes debe retirar consentimiento')
    await generar(page)
    assert.equal(s.acta.length, 0); assert.equal(s.paso, 1)
    await page.reload()
    await page.getByRole('heading', { name: 'Borrador de Juzgado' }).waitFor()
    assert.equal(s.posts.filter(p => p.p.endsWith('/borradores')).length, 1)
    await page.screenshot({ path: resolve(salida, 'borrador-desktop.png'), fullPage: true })
    await confirmar(page)
    assert.equal(s.paso, 2); assert.equal(s.acta.length, 1)
    assert.equal(s.posts.filter(p => p.p.endsWith('/movimientos')).length, 1, 'Confirmar no debe avanzar')
    await page.getByRole('button', { name: 'Continuar a la siguiente etapa', exact: true }).click()
    await visible(page, 'Preparar borrador')
    assert.equal(await consent.isChecked(), false, 'Nuevo turno requiere nueva autorización')
    await generar(page)
    await confirmar(page)
    await page.getByRole('button', { name: 'Continuar a la siguiente etapa', exact: true }).click()
    await page.getByLabel('Tu intervención como defensa').fill('Intervención sintética de defensa.')
    await page.getByRole('button', { name: 'Registrar defensa', exact: true }).evaluate(b => { b.click(); b.click() })
    await visible(page, 'Finalizar práctica')
    await page.getByRole('button', { name: 'Finalizar práctica', exact: true }).click()
    await page.getByRole('dialog').waitFor()
    assert.equal(await page.getByRole('button', { name: 'Confirmar cierre', exact: true }).isDisabled(), true)
    await page.keyboard.press('Escape')
    assert.equal(await page.getByRole('dialog').count(), 0)
    assert.equal(s.paso, 6)
    await page.waitForFunction(() => document.activeElement?.textContent === 'Finalizar práctica')
    await page.getByRole('button', { name: 'Finalizar práctica', exact: true }).click()
    await page.getByRole('checkbox', { name: /He terminado mis intervenciones/ }).check()
    await page.getByRole('button', { name: 'Confirmar cierre', exact: true }).click()
    await page.getByText('El acta queda disponible para consulta.', { exact: false }).waitFor()
    assert.equal(s.acta.length, 3); assert.equal(s.paso, 7)
    assert.equal(s.posts.some(p => /evaluacion|intervenciones|avanzar|propuesta/.test(p.p)), false)
    await sinDesbordamiento(page)
    comprobaciones.push('Circuito completo: versión explícita, inicio, fuentes, consentimiento, recuperación sin generación, confirmación sin avance, defensa y cierre accesible.')
    await context.close()
  }
  for (const op of [{ bloqueado: true }, { invalido: true }, { errorOrq: true }, { legacy: true }]) {
    const { context, page, s } = await escenario({ ...op, paso: op.legacy ? 1 : 0 })
    await page.goto(url + '/simulaciones/21')
    await page.getByRole('heading', { level: 1 }).waitFor()
    await page.waitForTimeout(150)
    assert.equal(s.posts.length, 0)
    if (op.legacy) {
      await page.getByText('Los turnos de esta audiencia aún no están configurados').waitFor()
    } else {
      assert.equal(await page.getByRole('button', { name: /Iniciar práctica|Preparar borrador|Registrar defensa/ }).count(), 0)
      if (op.invalido || op.errorOrq) assert.equal(s.gets.includes('/api/v1/simulaciones/21'), false, 'No caer al circuito anterior por errores')
    }
    comprobaciones.push('Bloqueo seguro: ' + Object.keys(op)[0])
    await context.close()
  }
  {
    const { context, page, s } = await escenario({ versionVigente: false })
    await page.goto(url + '/expedientes/7#simulaciones')
    await page.getByLabel('Tipo de audiencia', { exact: true }).selectOption('1')
    await page.getByText('No hay una versión revisada vigente').waitFor()
    assert.equal(await page.getByRole('button', { name: 'Crear práctica', exact: true }).count(), 0)
    assert.equal(s.posts.length, 0)
    comprobaciones.push('Sin revisión vigente no se ofrece creación ni se inventan aprobaciones.')
    await context.close()
  }
  {
    const { context, page, s } = await escenario({ paso: 1, falloProveedor: true })
    await page.goto(url + '/simulaciones/21')
    await page.getByText('No se pudo comprobar el proveedor.', { exact: false }).waitFor()
    assert.equal(await page.getByRole('button', { name: 'Preparar borrador', exact: true }).count(), 0)
    assert.equal(s.posts.length, 0)
    comprobaciones.push('Fallo del proveedor: sin envío ni reintento automático.')
    await context.close()
  }
  for (const op of [{ vencido: true }, { vencePronto: true }]) {
    const { context, page, s } = await escenario({ paso: 1, recuperar: true, ...op })
    await page.goto(url + '/simulaciones/21')
    await page.getByRole('heading', { name: 'Borrador de Juzgado' }).waitFor()
    if (op.vencePronto) {
      await page.getByRole('checkbox', { name: /He revisado el texto/ }).check()
      await page.getByText('Este borrador venció', { exact: false }).waitFor()
    }
    assert.equal(await page.getByRole('button', { name: 'Confirmar e incorporar al acta', exact: true }).isDisabled(), true)
    assert.equal(s.posts.length, 0)
    comprobaciones.push('Vigencia del borrador: ' + Object.keys(op)[0])
    await context.close()
  }
  {
    const { context, page, s } = await escenario({ paso: 5, conflicto: true })
    await page.goto(url + '/simulaciones/21')
    await page.getByLabel('Tu intervención como defensa').fill('Defensa conservada para revisar.')
    await page.getByRole('button', { name: 'Registrar defensa', exact: true }).click()
    await page.getByText('El turno, las fuentes o la vigencia cambiaron;', { exact: false }).waitFor()
    assert.equal(await page.getByLabel('Tu intervención como defensa').inputValue(), 'Defensa conservada para revisar.')
    assert.equal(await page.getByRole('button', { name: 'Registrar defensa', exact: true }).isDisabled(), true)
    assert.equal(s.posts.length, 1)
    s.conflicto = false
    await page.getByRole('button', { name: 'Actualizar práctica', exact: true }).click()
    await page.waitForFunction(() => !document.querySelector('#practica-defensa-texto').disabled)
    assert.equal(s.posts.length, 1)
    comprobaciones.push('409 conserva texto y bloquea la repetición hasta una consulta explícita.')
    await context.close()
  }
  {
    const { context, page, s } = await escenario({ paso: 6, conflicto: true })
    await page.goto(url + '/simulaciones/21')
    await page.getByRole('button', { name: 'Finalizar práctica', exact: true }).click()
    await page.getByRole('checkbox', { name: /He terminado mis intervenciones/ }).check()
    await page.getByRole('button', { name: 'Confirmar cierre', exact: true }).click()
    await page.getByRole('dialog').getByRole('alert').waitFor()
    assert.equal(await page.getByRole('button', { name: 'Confirmar cierre', exact: true }).isDisabled(), true)
    assert.equal(s.posts.length, 1)
    await page.getByRole('button', { name: 'Seguir revisando', exact: true }).click()
    comprobaciones.push('Cierre incierto no se repite desde el diálogo.')
    await context.close()
  }
  for (const width of [320,390,768,1365]) {
    const { context, page, s } = await escenario({ paso: 1, largo: true })
    await page.setViewportSize({ width, height: 950 })
    await page.goto(url + '/simulaciones/21')
    await visible(page, 'Preparar borrador')
    await buscar(page)
    await page.getByRole('checkbox', { name: /Documento de práctica/ }).check()
    await page.getByText('Revisar el contexto que se enviará').click()
    await sinDesbordamiento(page)
    await page.screenshot({ path: resolve(salida, 'turno-' + width + '.png'), fullPage: true })
    if (width === 390) {
      await page.addStyleTag({ content: 'html { font-size: 200% !important }' })
      await sinDesbordamiento(page)
      await page.screenshot({ path: resolve(salida, 'turno-texto-200.png'), fullPage: true })
    }
    assert.equal(s.posts.length, 1, 'Solo la búsqueda sintética')
    comprobaciones.push('Reflujo con texto largo, modo movimiento reducido e inyección literal: ' + width + 'px' + (width === 390 ? ' y texto 200%' : ''))
    await context.close()
  }
  {
    const { context, page, s } = await escenario({ paso: 1, recuperar: true })
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(url + '/simulaciones/21')
    await page.getByRole('heading', { name: 'Borrador de Juzgado' }).waitFor()
    await sinDesbordamiento(page)
    await page.screenshot({ path: resolve(salida, 'borrador-mobile.png'), fullPage: true })
    assert.equal(s.posts.length, 0)
    comprobaciones.push('Revisión de borrador recuperado en móvil, sin envío.')
    await context.close()
  }
  assert.deepEqual(errores, [])
  console.log(JSON.stringify({ comprobaciones: comprobaciones.length, detalle: comprobaciones, salida, errores }, null, 2))
} catch (e) {
  console.error({ errores, comprobaciones, url: ultimaPagina?.url() })
  if (ultimaPagina && !ultimaPagina.isClosed()) {
    console.error(await ultimaPagina.locator('body').innerText())
    await ultimaPagina.screenshot({ path: resolve(salida, 'fallo-sintetico.png'), fullPage: true })
  }
  throw e
} finally { await browser.close() }
