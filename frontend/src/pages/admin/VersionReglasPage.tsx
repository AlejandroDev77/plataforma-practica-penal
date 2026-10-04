import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, RefreshCw, ClipboardCheck } from 'lucide-react'
import { PageHeading } from '../../components/ui/AdminUi'
import { useCurrentUser } from '../../features/auth/model/use-current-user'
import { consultarHabilitacionPropia, consultarVersion, listarRevisiones } from '../../features/audiencias/api/reglas-api'
import { consultarInstrucciones, fechaLegible, nombreRol, puedeRevisar, type RevisionReglas, type VersionReglas } from '../../features/audiencias/model/reglas'
import { useActualizarReglas, useReglasQuery } from '../../features/audiencias/model/use-reglas'
import { EstadoRegla, ErrorReglas, PaginacionReglas } from '../../features/audiencias/components/ReglasUi'
import { RetiroReglaForm, RevisionReglasForm } from '../../features/audiencias/components/ReglasForms'
import './reglas-audiencia.css'
import { ActivacionReglasPanel } from '../../features/audiencias/components/ActivacionReglasPanel'

export function VersionReglasPage({ revision = false }: { revision?: boolean }) {
  const id = Number(useParams().id)
  const { data: usuario } = useCurrentUser()
  const admin = usuario?.roles.includes('administrador_plataforma') ?? false
  const permitido = (revision || admin) && Number.isSafeInteger(id) && id > 0
  const raiz = revision ? '/revision-reglas' : '/configuracion/reglas'
  const [pagina, setPagina] = useState(1)
  const [paraRevisar, setParaRevisar] = useState<VersionReglas | null>(null)
  const [retirar, setRetirar] = useState<RevisionReglas | null>(null)
  const [aviso, setAviso] = useState('')
  const volverRef = useRef<HTMLAnchorElement>(null)
  const actualizar = useActualizarReglas()
  const version = useReglasQuery(['version', id], signal => consultarVersion(id, signal), permitido)
  const habilitacion = useReglasQuery(['propia', version.data?.id_tipo_audiencia], signal => consultarHabilitacionPropia(version.data!.id_tipo_audiencia, usuario!.id, signal), permitido && Boolean(version.data))
  const historial = useReglasQuery(['historial', id, pagina], signal => listarRevisiones(id, pagina, signal), permitido && Boolean(version.data))
  const v = version.data
  const contenido = v?.contenido
  const instrucciones = v ? consultarInstrucciones(v) : null
  const textoInstrucciones = new Map(instrucciones?.contrato?.instrucciones.map(i => [i.id_propuesta_turno, i.texto]))
  const listaValida = Boolean(v && contenido && Array.isArray(contenido.propuestas) && Array.isArray(contenido.etapas))
  function cerrar() {
    setParaRevisar(null); setRetirar(null)
    // Punto estable: el boton que abrio el dialogo puede perder su permiso.
    void actualizar().finally(() => volverRef.current?.focus())
  }
  if (!permitido) return <section className="reglas-page"><PageHeading eyebrow="Reglas de audiencia" title="Versión no disponible" description="Compruebe la dirección o su acceso al espacio de revisión." /><Link to="/revision-reglas" className="btn btn-secondary">Volver a revisión</Link></section>
  return <section className="reglas-page"><Link ref={volverRef} to={raiz} className="reglas-back"><ArrowLeft size={16} aria-hidden="true" />Volver a reglas de audiencia</Link>
    {version.isPending ? <p role="status" className="reglas-empty">Consultando la versión…</p> : version.isError ? <ErrorReglas error={version.error} reintentar={() => void version.refetch()} /> : !listaValida || !v || !contenido ? <p role="alert" className="reglas-error">No recibimos una versión completa. Vuelva a consultar antes de revisarla.</p> : <>
      <PageHeading eyebrow={contenido.tipo.nombre} title={`Versión ${v.numero_version}`} description={v.motivo}><button className="btn btn-secondary" type="button" disabled={version.isFetching} onClick={() => { setAviso(''); void actualizar() }}><RefreshCw size={16} aria-hidden="true" />Actualizar</button>
      </PageHeading>
      {aviso && <p role="status" className="reglas-success">{aviso}</p>}
      <div className="reglas-summary"><EstadoRegla estado={v.revision_juridica.legal_review_status} /><p>Sellada {fechaLegible(v.fecha_creacion)} <span>· Autor #{v.id_usuario_autor}</span></p><strong>Sin activación de turnos</strong></div>
      <div className="reglas-workspace"><div className="reglas-reading"><header><p className="reglas-kicker">Copia conservada para revisión</p><h2>{instrucciones?.estado === 'disponible' ? 'Propuestas e instrucciones' : 'Propuestas de actuación'}</h2><p>{instrucciones?.estado === 'disponible' ? 'Lea cada propuesta y el texto completo de su instrucción antes de decidir.' : 'Lea el orden, el rol y las referencias antes de registrar su decisión.'}</p></header>
        {instrucciones?.estado === 'incompatible' && <p role="alert" className="reglas-error">No podemos mostrar un conjunto completo de instrucciones para esta copia. Vuelva a consultar; si el problema continúa, solicite una versión compatible antes de revisarla.</p>}
        {contenido.propuestas.map(p => <article className="reglas-propuesta" key={p.id_propuesta_turno}><p className="reglas-kicker">{contenido.etapas.find(e => e.id_etapa === p.id_etapa)?.nombre ?? 'Etapa sin nombre'} · Turno {p.orden} · {nombreRol(p.rol)}</p><h3>{p.acto_propuesto}</h3><p>{p.descripcion_propuesta}</p><dl><dt>Referencia propuesta</dt><dd>{p.referencia_normativa_propuesta || 'Sin referencia propuesta'}</dd></dl>{p.observaciones && <p className="reglas-note">{p.observaciones}</p>}{instrucciones?.estado === 'disponible' && <div className="reglas-instruccion"><h4>Instrucción del turno</h4><p>{textoInstrucciones.get(p.id_propuesta_turno)}</p></div>}</article>)}
        <details className="reglas-structure"><summary>Etapas, transiciones y turnos de referencia</summary><h3>Etapas conservadas</h3><ol>{contenido.etapas.map(e => <li key={e.id_etapa}>{e.orden}. {e.nombre} · {e.activo ? 'Activa en el catálogo' : 'Inactiva en el catálogo'}</li>)}</ol><h3>Transiciones del catálogo</h3><ul>{contenido.transiciones.map((t, i) => <li key={i}>{contenido.etapas.find(e => e.id_etapa === t.id_etapa_origen)?.nombre ?? `Etapa ${t.id_etapa_origen}`} → {contenido.etapas.find(e => e.id_etapa === t.id_etapa_destino)?.nombre ?? `Etapa ${t.id_etapa_destino}`} · {t.activo ? 'Activa' : 'Inactiva'}</li>)}</ul>{!contenido.transiciones.length && <p>Sin transiciones conservadas.</p>}<h3>Turnos de referencia, no propuestas aprobadas</h3>{contenido.turnos_catalogo_referencia.map(t => <p key={t.id_turno_etapa}>{nombreRol(t.rol)} · Turno {t.orden}: {t.instruccion || 'Sin instrucción'}</p>)}{!contenido.turnos_catalogo_referencia.length && <p>Sin turnos en el catálogo de referencia.</p>}</details>
        <footer className="reglas-reading-actions">{!habilitacion.isError && habilitacion.data && puedeRevisar(v, habilitacion.data, usuario!.id) && <button className="btn btn-primary" type="button" disabled={habilitacion.isFetching || version.isFetching} onClick={() => setParaRevisar(v)}><ClipboardCheck size={16} aria-hidden="true" />Registrar revisión</button>}</footer>
      </div><aside className="reglas-context" aria-label="Condiciones de revisión"><h2>Su habilitación</h2>{habilitacion.isPending ? <p role="status">Comprobando el ámbito…</p> : habilitacion.isError ? <ErrorReglas error={habilitacion.error} reintentar={() => void habilitacion.refetch()} /> : habilitacion.data ? <><p>{habilitacion.data.alcance}</p><p className="reglas-meta">Hasta {fechaLegible(habilitacion.data.fecha_vencimiento)}</p>{!puedeRevisar(v, habilitacion.data, usuario!.id) && <p className="reglas-note">No puede revisar autoría propia o desconocida. La habilitación también debe seguir vigente.</p>}</> : <p>No tiene una habilitación vigente para este tipo de audiencia. Administrar el sistema no concede autoridad jurídica.</p>}
        <h2>Contenido de la decisión</h2><p>{instrucciones?.estado === 'disponible' ? 'Incluye las propuestas y todas sus instrucciones. Deberá confirmar expresamente ambas lecturas.' : instrucciones?.estado === 'incompatible' ? 'Las instrucciones no están disponibles para revisión. No registre una decisión sobre una lectura incompleta.' : 'Esta copia conserva propuestas, sin instrucciones para ejecutar turnos. Su revisión no podrá aprobar instrucciones añadidas después.'}</p>
        <h2>Huella de la versión</h2><p className="reglas-mono">{v.huella_contenido}</p><p className="reglas-meta">Corresponde a la copia completa sellada. No es una firma profesional.</p><Link className="btn btn-secondary" to={`${raiz}?seccion=habilitaciones`}>Consultar habilitaciones</Link>
      </aside></div>
      {admin && !revision && <ActivacionReglasPanel key={v.id} version={v} />}
      <section className="reglas-history" aria-labelledby="historial-revision"><header><h2 id="historial-revision">Historial de decisiones</h2><p>Las decisiones anteriores se conservan; solo el estado de esta versión informa su revisión vigente.</p></header>
        {historial.isPending ? <p role="status" className="reglas-empty">Consultando decisiones…</p> : historial.isError ? <ErrorReglas error={historial.error} reintentar={() => void historial.refetch()} /> : !historial.data?.data.length ? <p className="reglas-empty">Todavía no se registraron decisiones sobre esta versión.</p> : historial.data.data.map(r => <article className="reglas-decision" key={r.id}><div className="reglas-decision-head"><div><EstadoRegla estado={r.revocacion ? 'revision_revocada' : r.decision} /><p className="reglas-meta">Cuenta revisora #{r.id_usuario_revisor} · {fechaLegible(r.fecha_creacion)}</p></div>{!r.revocacion && (admin || r.id_usuario_revisor === usuario!.id) && <button type="button" className="btn btn-secondary" onClick={() => setRetirar(r)}>Retirar revisión</button>}</div><p>{r.observacion}</p><details><summary>Fuentes, vigencia y trazabilidad</summary><dl><dt>Alcance revisado</dt><dd>{r.alcance_revisado}</dd><dt>Instrucciones de esta copia</dt><dd>{r.huella_ejecucion_revisada ? r.huella_ejecucion_revisada === v.huella_contenido ? 'Incluidas en esta decisión' : 'La huella registrada no coincide con esta copia' : 'Sin cobertura de instrucciones'}</dd><dt>Vigencia comprobada</dt><dd>{r.vigencia_comprobada_en}</dd><dt>Vencimiento de la decisión</dt><dd>{fechaLegible(r.fecha_vencimiento)}</dd>{r.revocacion && <><dt>Motivo del retiro</dt><dd>{r.revocacion.motivo}</dd><dt>Retirada</dt><dd>{fechaLegible(r.revocacion.fecha_creacion)}</dd></>}</dl><ul>{r.fuentes_verificadas.map((f, i) => <li key={i}>{f.referencia}<span className="reglas-mono">{f.huella_contenido}</span></li>)}</ul></details></article>)}
        {historial.data && !historial.isError && <PaginacionReglas meta={historial.data.meta} cambiar={setPagina} ocupada={historial.isFetching} />}
      </section>
      {paraRevisar?.id === id && habilitacion.data && <RevisionReglasForm key={`${paraRevisar.id}:${paraRevisar.huella_contenido}`} version={paraRevisar} habilitacion={habilitacion.data} usuario={usuario!.id} cerrar={cerrar} terminado={() => { cerrar(); setPagina(1); setAviso('La decisión quedó registrada sobre esta versión. Los turnos no se activaron.') }} />}
      {retirar && <RetiroReglaForm tipo="revisiones" id={retirar.id} etiqueta={`Decisión ${retirar.id} de la versión ${v.numero_version}`} cerrar={cerrar} terminado={() => { cerrar(); setAviso('La revisión fue retirada; se conserva su historial.') }} />}
    </>}
  </section>
}
