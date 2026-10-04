import { useEffect, useRef, useState } from 'react'
import { Modal } from '../../../components/ui/AdminUi'
import { consultarBorradorInstrucciones, sellarInstrucciones } from '../api/reglas-api'
import type { TipoAudienciaDisponible } from '../api/propuestas-turnos-api'
import { nombreRol, prepararSelladoInstrucciones, type BorradorInstrucciones, type VersionReglas } from '../model/reglas'
import { AccionesFormulario, AvisoFormulario } from './ReglasForms'
import { useRegistro } from '../model/use-registro-reglas'
import { CampoRegla, ErrorReglas } from './ReglasUi'

export function PrepararInstruccionesForm({ tipos, cerrar, terminado }: {
  tipos: TipoAudienciaDisponible[]; cerrar: () => void; terminado: (version: VersionReglas) => void
}) {
  const [tipo, setTipo] = useState('')
  const [borrador, setBorrador] = useState<BorradorInstrucciones | null>(null)
  const [consultando, setConsultando] = useState(false)
  const [errorConsulta, setErrorConsulta] = useState<unknown>(null)
  const [instrucciones, setInstrucciones] = useState<{ id_propuesta_turno: number; texto: string }[]>([])
  const [indice, setIndice] = useState(0)
  const [motivo, setMotivo] = useState('')
  const [confirmado, setConfirmado] = useState(false)
  const solicitud = useRef<AbortController | null>(null)
  const turnoRef = useRef<HTMLHeadingElement>(null)
  useEffect(() => () => solicitud.current?.abort(), [])
  const registro = useRegistro(async () => {
    if (!borrador) throw new Error('Consulte primero las propuestas de la audiencia.')
    const datos = prepararSelladoInstrucciones({ motivo, instrucciones, confirmacion_instrucciones: confirmado }, borrador)
    terminado(await sellarInstrucciones(borrador.id_tipo_audiencia, datos))
  }, () => {})
  async function consultar() {
    if (!tipo || solicitud.current) return
    const control = new AbortController()
    solicitud.current = control; setConsultando(true); setErrorConsulta(null)
    try {
      const copia = await consultarBorradorInstrucciones(Number(tipo), control.signal)
      if (control.signal.aborted) return
      setBorrador(copia); setInstrucciones(copia.contenido.propuestas.map(p => ({ id_propuesta_turno: p.id_propuesta_turno, texto: '' })))
      setIndice(0); setConfirmado(false)
    } catch (error) { if (!control.signal.aborted) setErrorConsulta(error) }
    finally { if (!control.signal.aborted) { solicitud.current = null; setConsultando(false) } }
  }
  function cambiarTurno(nuevo: number) {
    setIndice(nuevo)
    requestAnimationFrame(() => turnoRef.current?.focus())
  }
  const propuesta = borrador?.contenido.propuestas[indice]
  const incompleta = (texto: string) => !/[^\s\p{Z}\u0085]/u.test(texto) || Array.from(texto).length > 5000
  const pendientes = instrucciones.filter(i => incompleta(i.texto)).length
  return <Modal title="Preparar instrucciones" description="Escriba una instrucción explícita por turno. El sellado conserva una copia para revisión; no activa audiencias." busy={registro.ocupado} onClose={cerrar}>
    <div className="modal-body reglas-form">
      {!borrador ? <fieldset disabled={consultando}><legend>Elegir audiencia</legend>
        <CampoRegla nombre="tipo" etiqueta="Audiencia para preparar" errores={{}}>{a => <select {...a} value={tipo} onChange={e => setTipo(e.target.value)}><option value="">Seleccione una audiencia</option>{tipos.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}</select>}</CampoRegla>
        <p className="reglas-note">Se consultará el borrador actual. Las descripciones y los textos del catálogo no se convertirán en instrucciones.</p>
        {Boolean(errorConsulta) && <ErrorReglas error={errorConsulta} reintentar={() => void consultar()} />}
        {consultando && <p role="status">Consultando propuestas…</p>}
        <button className="btn btn-primary" type="button" disabled={!tipo || consultando} onClick={() => void consultar()}>Consultar propuestas</button>
      </fieldset> : !borrador.estructura_valida || !propuesta ? <div className="reglas-empty"><h3>El borrador necesita ajustes</h3><p role="alert">Revise las etapas, el recorrido, los roles y la autoría de las propuestas antes de escribir instrucciones. Cierre este formulario y corrija las propuestas de turnos.</p><button className="btn btn-secondary" type="button" onClick={cerrar}>Volver al listado</button></div> :
        <form noValidate onSubmit={registro.enviar} aria-busy={registro.ocupado}>
          <AvisoFormulario registro={registro} />
          <fieldset disabled={registro.ocupado || registro.bloqueado}><legend>{borrador.contenido.tipo.nombre}</legend>
            <p className="reglas-note" role="status">{instrucciones.length - pendientes} de {instrucciones.length} instrucciones completas</p>
            <div className="reglas-editor-turno">
              <p className="reglas-kicker">Propuesta {indice + 1} de {instrucciones.length} · {borrador.contenido.etapas.find(e => e.id_etapa === propuesta.id_etapa)?.nombre} · Turno {propuesta.orden} · {nombreRol(propuesta.rol)}</p>
              <h3 tabIndex={-1} ref={turnoRef}>{propuesta.acto_propuesto}</h3>
              <p>{propuesta.descripcion_propuesta}</p>
              <dl><dt>Referencia propuesta</dt><dd>{propuesta.referencia_normativa_propuesta || 'Sin referencia propuesta'}</dd></dl>
              {propuesta.observaciones && <p className="reglas-note">{propuesta.observaciones}</p>}
              <CampoRegla nombre={`instrucciones.${indice}.texto`} etiqueta="Instrucción de este turno" errores={registro.errores} hint="Máximo 5.000 caracteres. Escriba qué debe hacer este rol; no añada hechos ausentes del expediente.">{a => <textarea {...a} required rows={6} value={instrucciones[indice].texto} onChange={e => {
                const texto = e.target.value
                setInstrucciones(prev => prev.map((i, n) => n === indice ? { ...i, texto } : i)); setConfirmado(false)
              }} />}</CampoRegla>
              <p className="reglas-meta">{Array.from(instrucciones[indice].texto).length} de 5.000 caracteres</p>
              <div className="reglas-editor-pasos"><button type="button" className="btn btn-secondary" disabled={indice === 0} onClick={() => cambiarTurno(indice - 1)}>Propuesta anterior</button><button type="button" className="btn btn-secondary" disabled={indice >= instrucciones.length - 1} onClick={() => cambiarTurno(indice + 1)}>Propuesta siguiente</button></div>
            </div>
          </fieldset>
          <fieldset disabled={registro.ocupado || registro.bloqueado}><legend>Conservar la copia completa</legend>
            <CampoRegla nombre="motivo" etiqueta="Motivo de esta versión" errores={registro.errores}>{a => <textarea {...a} required rows={3} maxLength={2000} value={motivo} onChange={e => { setMotivo(e.target.value); setConfirmado(false) }} />}</CampoRegla>
            {pendientes > 0 && <p className="reglas-note">{pendientes} {pendientes === 1 ? 'instrucción necesita' : 'instrucciones necesitan'} texto o ajustes de longitud. <button type="button" className="btn btn-secondary" onClick={() => cambiarTurno(instrucciones.findIndex(i => incompleta(i.texto)))}>Ir a la primera pendiente</button></p>}
            <CampoRegla nombre="confirmacion_instrucciones" etiqueta="Confirmación de las instrucciones" errores={registro.errores}>{a => <span className="reglas-check"><input {...a} type="checkbox" checked={confirmado} disabled={pendientes > 0} onChange={e => setConfirmado(e.target.checked)} /><span>Revisé todas las propuestas y las instrucciones que escribí. Entiendo que esta copia no podrá editarse ni borrarse y que requiere una revisión jurídica independiente.</span></span>}</CampoRegla>
            <p className="reglas-note">Este borrador solo permanece en el formulario. Si lo cierra o recarga la página, perderá las instrucciones sin sellar.</p>
            <details className="reglas-structure"><summary>Etapas y recorrido consultados</summary><ol>{borrador.contenido.etapas.map(e => <li key={e.id_etapa}>{e.orden}. {e.nombre} · {e.es_inicial ? 'Inicio' : e.es_final ? 'Cierre' : 'Etapa intermedia'}</li>)}</ol><ul>{borrador.contenido.transiciones.map((t, n) => <li key={n}>{borrador.contenido.etapas.find(e => e.id_etapa === t.id_etapa_origen)?.nombre} → {borrador.contenido.etapas.find(e => e.id_etapa === t.id_etapa_destino)?.nombre} · {t.activo ? 'Activa' : 'Inactiva'}</li>)}</ul></details>
          </fieldset>
          <AccionesFormulario registro={registro} cancelar={cerrar} texto="Sellar copia con instrucciones" />
        </form>}
    </div>
  </Modal>
}
