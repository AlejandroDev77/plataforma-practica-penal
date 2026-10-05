import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Modal, PageHeading } from '../../../components/ui/AdminUi'
import { useCurrentUser } from '../../auth/model/use-current-user'
import { simulationsQueryKey } from '../../simulations/model/use-simulations'
import { consultarActa, consultarBorrador, consultarProveedor, confirmarBorrador, errorPractica, generarBorrador, listarBorradores, moverPractica, registrarDefensa } from '../api/practicas-api'
import { claveContexto, coincideTurno, explicarBloqueo, nombresEstado, nombresRol, prepararDefensa, prepararGeneracion, prepararMovimiento, type Borrador, type FuenteSeleccionable, type Orquestacion } from '../model/practica-reglada'
import { useOrquestacion } from '../model/use-practicas'
import { SelectorFuentes } from './SelectorFuentes'
import '../practicas.css'

export function PracticaRegladaPage({ id, consulta }: { id: number; consulta: Orquestacion }) {
  const usuario = useCurrentUser().data?.id
  const orq = useOrquestacion(id)
  const d = orq.data ?? consulta
  const detalle = useQuery({ queryKey: ['practicas-regladas', usuario, id, 'acta'], queryFn: ({ signal }) => consultarActa(id, signal), retry: false, gcTime: 0 })
  return <div className="practica-reglada">
    <Link className="back-link" to={detalle.data ? `/expedientes/${detalle.data.case_id}#simulaciones` : '/audiencias'}>Volver al expediente</Link>
    <PageHeading eyebrow="PRÁCTICA PROCESAL" title={d.type?.nombre ?? 'Práctica de audiencia'} description="Ejercicio académico privado · No representa una actuación judicial real.">
      <span className="practica-estado">{nombresEstado[d.status]}</span>
    </PageHeading>
    <div className="practica-resumen"><div><span>Etapa</span><strong>{d.stage?.nombre ?? 'Sin etapa iniciada'}</strong></div><div><span>Tu papel</span><strong>Defensa</strong></div><div><span>Versión revisada</span><strong>{d.version_id ? 'Conservada para esta práctica' : 'No disponible'}</strong></div></div>
    {(orq.isError || d.status === 'bloqueada') && <div className="practica-aviso" role="alert">
      <strong>No se puede continuar por ahora</strong><p>{orq.isError ? errorPractica(orq.error) : d.blocking_reasons.map(explicarBloqueo).join(' ')}</p>
      <button className="btn btn-secondary" disabled={orq.isFetching} onClick={() => void orq.refetch()}>Actualizar práctica</button>
    </div>}
    {!orq.isError && d.status !== 'bloqueada' && <TurnoReglado key={claveContexto(d)} id={id} d={d} consultando={orq.isFetching} />}
    <section className="panel practica-acta" aria-labelledby="practica-acta-titulo">
      <div className="practica-subtitulo"><h2 id="practica-acta-titulo">Acta de práctica</h2><span>{detalle.data?.interventions?.length ?? 0} intervenciones</span></div>
      {detalle.isPending && <p role="status">Consultando el acta…</p>}
      {detalle.isError && <div className="practica-error" role="alert"><p>No se pudo actualizar el acta. Esto no revierte las intervenciones guardadas.</p><button className="btn btn-secondary" onClick={() => void detalle.refetch()}>Actualizar acta</button></div>}
      {detalle.isSuccess && !detalle.data.interventions?.length && <div className="practica-acta-vacia"><strong>Aún no hay intervenciones</strong><p>El acta se completará cuando registres la defensa o confirmes un borrador revisado.</p></div>}
      {detalle.data?.interventions?.map(i => <article className="simulation-message" key={i.id}>
        <div className="simulation-message-heading"><strong>{nombresRol[i.participant?.role as keyof typeof nombresRol] ?? 'Intervención'}</strong><span>Turno {i.order}</span></div>
        <p className="simulation-message-content">{i.content}</p>
        {i.sources.length > 0 && <details className="simulation-citations"><summary>Consultar referencias ({i.sources.length})</summary>{i.sources.map(f => <div key={f.id}><strong>{f.source.title ?? 'Referencia registrada'}</strong><p>{f.source.locator}</p><blockquote>{f.excerpt}</blockquote></div>)}</details>}
      </article>)}
    </section>
  </div>
}
function TurnoReglado({ id, d, consultando }: { id: number; d: Orquestacion; consultando: boolean }) {
  const client = useQueryClient(), usuario = useCurrentUser().data?.id
  const [fuentes, setFuentes] = useState<FuenteSeleccionable[]>([])
  const [autorizado, setAutorizado] = useState('')
  const [contenido, setContenido] = useState('')
  const [destino, setDestino] = useState('')
  const [borradorId, setBorradorId] = useState<number | null>(null)
  const [ocultos, setOcultos] = useState<number[]>([])
  const [cerrar, setCerrar] = useState(false)
  const [cierreRevisado, setCierreRevisado] = useState(false)
  const [incierto, setIncierto] = useState(false)
  const [aviso, setAviso] = useState('')
  const envio = useRef(false)
  const heading = useRef<HTMLHeadingElement>(null)
  const cierreTrigger = useRef<HTMLButtonElement>(null)
  function seguirRevisando() {
    setCerrar(false)
    window.requestAnimationFrame(() => cierreTrigger.current?.focus())
  }
  useEffect(() => { heading.current?.focus() }, [])
  const agente = d.technical_candidate_actions.includes('proponer_agente')
  const defensa = d.technical_candidate_actions.includes('registrar_defensa')
  const prefijo = ['practicas-regladas', usuario, id] as const
  const proveedor = useQuery({ queryKey: [...prefijo, 'proveedor'], queryFn: ({ signal }) => consultarProveedor(id, signal), enabled: agente, retry: false, gcTime: 0, staleTime: 0 })
  const historial = useQuery({ queryKey: [...prefijo, 'borradores'], queryFn: ({ signal }) => listarBorradores(id, signal), enabled: agente, retry: false, gcTime: 0, staleTime: 0 })
  const recuperado = historial.data?.data.find(b => !b.id_intervencion_confirmada && coincideTurno(b, d) && !ocultos.includes(b.id_borrador))
  const actualId = borradorId ?? recuperado?.id_borrador ?? null
  const lectura = useQuery({ queryKey: [...prefijo, 'borrador', actualId], queryFn: ({ signal }) => consultarBorrador(id, actualId!, signal), enabled: actualId !== null, retry: false, gcTime: 0, staleTime: 0 })
  const huellaConsentimiento = JSON.stringify([claveContexto(d), proveedor.data, fuentes.map(f => f.id)])
  async function actualizar() {
    await client.invalidateQueries({ queryKey: prefijo })
    await client.invalidateQueries({ queryKey: simulationsQueryKey })
  }
  const mover = useMutation({ gcTime: 0, mutationFn: (a: 'iniciar' | 'avanzar' | 'finalizar') => moverPractica(id, prepararMovimiento(d, a, destino ? Number(destino) : undefined)), retry: false, onSettled: () => { envio.current = false },
    onSuccess: async () => { await actualizar(); heading.current?.focus() }, onError: () => { setIncierto(true) } })
  const guardar = useMutation({ gcTime: 0, mutationFn: () => registrarDefensa(id, prepararDefensa(d, fuentes.map(f => f.id), contenido)), retry: false, onSettled: () => { envio.current = false },
    onSuccess: async () => { setContenido(''); await actualizar(); heading.current?.focus() }, onError: () => setIncierto(true) })
  const generar = useMutation({ gcTime: 0, mutationFn: () => {
    if (!proveedor.data) throw new Error('Proveedor no disponible.')
    return generarBorrador(id, prepararGeneracion(d, fuentes.map(f => f.id), proveedor.data, autorizado === huellaConsentimiento))
  }, retry: false, onSettled: () => { envio.current = false }, onSuccess: async b => {
    setBorradorId(b.id_borrador); setAutorizado('')
    await client.invalidateQueries({ queryKey: [...prefijo, 'borradores'] })
    setAviso('Borrador guardado. Revisa el texto y sus referencias antes de confirmarlo.')
  }, onError: () => { setIncierto(true); setAutorizado('') } })
  const confirmar = useMutation({ gcTime: 0, mutationFn: (b: Borrador) => confirmarBorrador(id, b.id_borrador), retry: false, onSettled: () => { envio.current = false },
    onSuccess: async () => { setAviso('Intervención incorporada al acta.'); await actualizar(); heading.current?.focus() }, onError: () => setIncierto(true) })
  const busy = mover.isPending || guardar.isPending || generar.isPending || confirmar.isPending
  const error = mover.error ?? guardar.error ?? generar.error ?? confirmar.error
  const bloqueo = busy || consultando || incierto
  function enviar(accion: () => void) {
    if (bloqueo || envio.current) return
    envio.current = true
    accion()
  }
  return <section className="panel practica-turno" aria-labelledby="practica-turno-titulo" aria-busy={busy}>
    <div className="practica-subtitulo"><div><p className="eyebrow">SIGUIENTE ACCIÓN</p><h2 ref={heading} tabIndex={-1} id="practica-turno-titulo">{d.current_turn ? nombresRol[d.current_turn.rol] : nombresEstado[d.status]}</h2></div><button className="btn btn-secondary" disabled={busy || consultando} onClick={async () => { await actualizar(); setIncierto(false); mover.reset(); guardar.reset(); generar.reset(); confirmar.reset(); setAutorizado('') }}>Actualizar práctica</button></div>
    {aviso && <p className="practica-aviso" role="status">{aviso}</p>}
    {error && <div className="practica-error" role="alert"><p>{errorPractica(error)}</p><p>Actualiza el acta y los borradores antes de volver a enviar. No se repetirá ninguna solicitud automáticamente.</p></div>}
    {d.current_turn && <div className="practica-instruccion"><p className="eyebrow">INSTRUCCIÓN DEL TURNO</p><p>{d.current_turn.instruccion}</p></div>}
    {d.status === 'preparando' && <><p>La versión revisada está vinculada. Iniciar abrirá su primera etapa; todavía no se generará ningún texto.</p><button className="btn btn-primary" disabled={bloqueo} onClick={() => enviar(() => mover.mutate('iniciar'))}>Iniciar práctica</button></>}
    {(agente || defensa) && !actualId && <SelectorFuentes id={id} seleccionadas={fuentes} limite={agente ? 8 : 10} busy={bloqueo} onChange={f => { setFuentes(f); setAutorizado('') }} />}
    {agente && <div className="practica-agente">
      {historial.isPending && <p role="status">Consultando borradores guardados…</p>}
      {historial.isError && <div className="practica-error" role="alert"><p>No pudimos consultar los borradores. Actualiza antes de generar otro para evitar duplicados.</p></div>}
      {actualId && lectura.isPending && <p role="status">Recuperando el borrador guardado…</p>}
      {actualId && lectura.isError && <div className="practica-error" role="alert"><p>No se pudo leer el borrador. No se generará otro automáticamente.</p><button className="btn btn-secondary" onClick={() => void lectura.refetch()}>Consultar borrador</button></div>}
      {lectura.data && <RevisionBorrador key={JSON.stringify(lectura.data)} b={lectura.data} d={d} busy={bloqueo || lectura.isFetching} confirmar={() => enviar(() => confirmar.mutate(lectura.data!))} ocultar={() => { if (actualId) setOcultos(v => [...v, actualId]); setBorradorId(null); setAutorizado(''); setAviso('El borrador permanece guardado; no se incorporó al acta.') }} />}
      {!actualId && <div className="practica-generacion">
        {proveedor.isPending && <p role="status">Consultando el proveedor…</p>}
        {proveedor.isError && <div className="practica-error" role="alert"><p>No se pudo comprobar el proveedor. No se enviará contenido.</p><button className="btn btn-secondary" onClick={() => void proveedor.refetch()}>Consultar proveedor</button></div>}
        {proveedor.data && <><p>{proveedor.data.provider === 'openai' ? 'Se enviarán a OpenAI la instrucción del turno, el rol, el nombre de la etapa y solo los extractos seleccionados (hasta 1.500 caracteres por fuente). No se enviarán el PDF, el análisis completo ni el acta. Puede consumir saldo de tu API.' : 'El borrador se preparará con Ollama en este equipo, usando la instrucción y los extractos seleccionados.'}</p>
          <details><summary>Revisar el contexto que se enviará</summary><p>{d.stage?.nombre} · {d.current_turn && nombresRol[d.current_turn.rol]}</p><p className="practica-texto">{d.current_turn?.instruccion}</p>{fuentes.map(f => <blockquote key={f.id}>{Array.from(f.extracto).slice(0, 1500).join('')}</blockquote>)}</details>
          {proveedor.data.requires_external_authorization && <label className="practica-check"><input type="checkbox" checked={autorizado === huellaConsentimiento} disabled={bloqueo} onChange={e => setAutorizado(e.target.checked ? huellaConsentimiento : '')} /><span>Autorizo enviar este contexto seleccionado a OpenAI para un solo borrador.</span></label>}
          <button className="btn btn-primary" disabled={bloqueo || historial.isPending || historial.isError || historial.isFetching || proveedor.isFetching || (proveedor.data.requires_external_authorization && autorizado !== huellaConsentimiento)} onClick={() => { setAviso(''); enviar(() => generar.mutate()) }}>{generar.isPending ? 'Preparando borrador…' : 'Preparar borrador'}</button>
        </>}
      </div>}
    </div>}
    {defensa && <form className="practica-defensa" onSubmit={e => { e.preventDefault(); enviar(() => guardar.mutate()) }}>
      <label className="practica-campo" htmlFor="practica-defensa-texto"><span>Tu intervención como defensa</span><textarea id="practica-defensa-texto" value={contenido} onChange={e => setContenido(e.target.value)} rows={7} maxLength={2000} disabled={bloqueo} required aria-describedby="practica-defensa-limite" /></label>
      <div className="practica-pie"><span id="practica-defensa-limite">{contenido.length.toLocaleString('es-BO')} / 2.000 caracteres</span><button className="btn btn-primary" disabled={bloqueo || !contenido.trim()} type="submit">{guardar.isPending ? 'Registrando…' : 'Registrar defensa'}</button></div>
    </form>}
    {d.technical_candidate_actions.includes('avanzar') && <div className="practica-transicion"><p>Los turnos de esta etapa se completaron. Elige un destino de la versión revisada.</p><div className="practica-campo"><label htmlFor="practica-destino">Siguiente etapa</label><select id="practica-destino" value={destino} onChange={e => setDestino(e.target.value)} disabled={bloqueo}><option value="">{d.candidate_next_stages?.length === 1 ? d.candidate_next_stages[0].nombre : 'Selecciona una etapa'}</option>{d.candidate_next_stages?.map(e => <option key={e.id_etapa} value={e.id_etapa}>{e.nombre}</option>)}</select></div><button className="btn btn-primary" disabled={bloqueo || ((d.candidate_next_stages?.length ?? 0) > 1 && !destino)} onClick={() => enviar(() => mover.mutate('avanzar'))}>Continuar a la siguiente etapa</button></div>}
    {d.technical_candidate_actions.includes('finalizar') && <div className="practica-transicion"><p>Completaste los turnos de la última etapa. Cerrar conservará el acta y no permitirá nuevas intervenciones.</p><button className="btn btn-primary" ref={cierreTrigger} disabled={bloqueo} onClick={() => { setCierreRevisado(false); setCerrar(true) }}>Finalizar práctica</button></div>}
    {cerrar && <Modal title="Finalizar esta práctica" description="El acta se conservará. No podrás añadir intervenciones ni reabrir la sesión." onClose={seguirRevisando} busy={busy}><div className="practica-modal"><label className="practica-check"><input type="checkbox" checked={cierreRevisado} disabled={busy} onChange={e => setCierreRevisado(e.target.checked)} /><span>He terminado mis intervenciones y quiero cerrar la práctica.</span></label><div className="practica-pie"><button className="btn btn-secondary" disabled={busy} onClick={seguirRevisando}>Seguir revisando</button><button className="btn btn-primary" disabled={bloqueo || !cierreRevisado} onClick={() => enviar(() => mover.mutate('finalizar'))}>Confirmar cierre</button></div>{mover.isError && <p role="alert">{errorPractica(mover.error)}</p>}</div></Modal>}
    {d.status === 'finalizada' && <p role="status">El acta queda disponible para consulta. No se admite continuar ni reabrir esta práctica.</p>}
  </section>
}
function RevisionBorrador({ b, d, busy, confirmar, ocultar }: { b: Borrador; d: Orquestacion; busy: boolean; confirmar: () => void; ocultar: () => void }) {
  const [revisado, setRevisado] = useState(false)
  const [ahora, setAhora] = useState(0)
  useEffect(() => {
    const actualizarReloj = () => setAhora(Date.now())
    actualizarReloj()
    const reloj = window.setInterval(actualizarReloj, 1000)
    return () => window.clearInterval(reloj)
  }, [])
  const vigente = ahora > 0 && !b.vencido && Date.parse(b.fecha_vencimiento) > ahora && !b.id_intervencion_confirmada
    && coincideTurno(b, d) && b.respuesta.speaker_role === d.current_turn?.rol
  return <div className="practica-borrador">
    <div className="practica-subtitulo"><div><p className="eyebrow">PENDIENTE DE REVISIÓN HUMANA</p><h3>Borrador de {nombresRol[b.respuesta.speaker_role]}</h3></div><span>{b.proveedor === 'openai' ? 'OpenAI' : 'Ollama'}</span></div>
    <p className="practica-texto">{b.respuesta.content}</p>
    <p className="practica-nota">Guardado para revisión, todavía no incorporado al acta. Modelo: {b.modelo_ia}.</p>
    <details><summary>Revisar fuentes enviadas y utilizadas ({b.fuentes_enviadas.length})</summary>{b.fuentes_enviadas.map(f => <div className="practica-referencia" key={f.id}><strong>{f.title}</strong><p>{f.locator} · {b.respuesta.used_source_ids.includes(f.id) ? 'Declarada utilizada' : 'Enviada, no utilizada'}</p><blockquote>{f.excerpt}</blockquote></div>)}{!b.fuentes_enviadas.length && <p>No se enviaron fuentes seleccionadas. El texto necesita revisión profesional.</p>}</details>
    {!vigente && <p className="practica-aviso" role="status">Este borrador venció, ya fue confirmado o pertenece a un turno anterior. Se conserva para consulta; no se puede confirmar aquí.</p>}
    <label className="practica-check"><input type="checkbox" checked={revisado} disabled={busy || !vigente} onChange={e => setRevisado(e.target.checked)} /><span>He revisado el texto y sus referencias; confirmo esta intervención para el acta de práctica.</span></label>
    <div className="practica-pie"><button className="btn btn-secondary" disabled={busy} onClick={ocultar}>Cerrar lectura sin confirmar</button><button className="btn btn-primary" disabled={!vigente || !revisado || busy} onClick={() => { setRevisado(false); confirmar() }}>Confirmar e incorporar al acta</button></div>
    <p className="practica-nota">La confirmación no avanza la etapa. El servidor comprobará de nuevo el turno, la vigencia y las fuentes.</p>
  </div>
}
