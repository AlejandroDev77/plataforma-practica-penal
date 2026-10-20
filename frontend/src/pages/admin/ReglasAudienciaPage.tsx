import { useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Archive, ArrowUpRight, Plus, RefreshCw, ShieldCheck } from 'lucide-react'
import { PageHeading } from '../../components/ui/AdminUi'
import { useCurrentUser } from '../../features/auth/model/use-current-user'
import { listarHabilitaciones, listarVersiones } from '../../features/audiencias/api/reglas-api'
import { listarTiposAudiencia } from '../../features/audiencias/api/propuestas-turnos-api'
import { fechaLegible, type Habilitacion } from '../../features/audiencias/model/reglas'
import { useActualizarReglas, useReglasQuery } from '../../features/audiencias/model/use-reglas'
import { EstadoRegla, ErrorReglas, PaginacionReglas } from '../../features/audiencias/components/ReglasUi'
import { HabilitacionForm, RetiroReglaForm, SellarVersionForm } from '../../features/audiencias/components/ReglasForms'
import { PrepararInstruccionesForm } from '../../features/audiencias/components/PrepararInstruccionesForm'
import './reglas-audiencia.css'

export function ReglasAudienciaPage({ revision = false }: { revision?: boolean }) {
  const { data: usuario } = useCurrentUser()
  const admin = usuario?.roles.includes('administrador_plataforma') ?? false
  const permiso = revision || admin
  const raiz = revision ? '/revision-reglas' : '/configuracion/reglas'
  const [parametros] = useSearchParams()
  const [seccion, setSeccion] = useState<'versiones' | 'habilitaciones'>(() => parametros.get('seccion') === 'habilitaciones' ? 'habilitaciones' : 'versiones')
  const [tipo, setTipo] = useState('')
  const [pagina, setPagina] = useState(1)
  const [formulario, setFormulario] = useState<'sellado' | 'habilitacion' | 'instrucciones' | null>(null)
  const [retirar, setRetirar] = useState<Habilitacion | null>(null)
  const [aviso, setAviso] = useState('')
  const listaRef = useRef<HTMLElement>(null)
  const navigate = useNavigate()
  const actualizar = useActualizarReglas()
  const tipos = useReglasQuery(['tipos'], () => listarTiposAudiencia(), permiso)
  const filtro = { page: pagina, id_tipo_audiencia: tipo ? Number(tipo) : undefined }
  const versiones = useReglasQuery(['versiones', filtro], signal => listarVersiones(filtro, signal), permiso && seccion === 'versiones')
  const habilitaciones = useReglasQuery(['habilitaciones', filtro], signal => listarHabilitaciones(filtro, signal), permiso && seccion === 'habilitaciones')
  const consulta = seccion === 'versiones' ? versiones : habilitaciones
  const nombreTipo = (id: number) => tipos.data?.find(t => t.id === id)?.name ?? 'Audiencia fuera del catálogo disponible'
  function cerrar() { setFormulario(null); setRetirar(null); void actualizar().finally(() => listaRef.current?.focus()) }
  if (!permiso) return <section className="reglas-page"><PageHeading eyebrow="Reglas de audiencia" title="Acceso reservado" description="Solo la administración de la plataforma gestiona habilitaciones. Si tiene una habilitación jurídica, use su espacio de revisión." /><Link className="btn btn-secondary" to="/revision-reglas">Ir a revisión de reglas</Link></section>
  return <section ref={listaRef} tabIndex={-1} className="reglas-page"><PageHeading eyebrow={revision ? 'Revisión jurídica' : 'Configuración de audiencias'} title="Reglas de audiencia" description="Versiones para revisar, decisiones documentadas y habilitaciones con alcance definido.">
    <button type="button" className="btn btn-secondary" disabled={consulta.isFetching} onClick={() => { setAviso(''); void actualizar() }}><RefreshCw size={16} aria-hidden="true" />Actualizar</button>
    {admin && <button type="button" className={seccion === 'versiones' ? 'btn btn-secondary' : 'btn btn-primary'} disabled={!tipos.data?.length} onClick={() => setFormulario(seccion === 'versiones' ? 'sellado' : 'habilitacion')}><Plus size={16} aria-hidden="true" />{seccion === 'versiones' ? 'Sellar versión' : 'Habilitar revisor'}</button>}
    {admin && seccion === 'versiones' && <button type="button" className="btn btn-primary" disabled={!tipos.data?.length} onClick={() => setFormulario('instrucciones')}>Preparar instrucciones</button>}
  </PageHeading><p className="reglas-note reglas-limit"><ShieldCheck size={18} aria-hidden="true" /><span>La revisión jurídica no activa turnos de audiencia. Las reglas conservan su estado de ejecución por separado.</span></p>
    {aviso && <p role="status" className="reglas-success">{aviso}</p>}
    <div className="reglas-toolbar"><div className="reglas-switch" role="group" aria-label="Registros de reglas"><button type="button" aria-pressed={seccion === 'versiones'} onClick={() => { setSeccion('versiones'); setPagina(1) }}>Versiones</button><button type="button" aria-pressed={seccion === 'habilitaciones'} onClick={() => { setSeccion('habilitaciones'); setPagina(1) }}>{admin ? 'Habilitaciones' : 'Mis habilitaciones'}</button></div>
      <label className="reglas-filter">Tipo de audiencia<select value={tipo} onChange={e => { setTipo(e.target.value); setPagina(1) }}><option value="">Todas las disponibles</option>{tipos.data?.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
    </div>
    {tipos.isError && <ErrorReglas error={tipos.error} reintentar={() => void tipos.refetch()} />}
    <div className="reglas-ledger" aria-busy={consulta.isFetching}>
      {consulta.isPending ? <p className="reglas-empty" role="status">Consultando {seccion}…</p> : consulta.isError ? <ErrorReglas error={consulta.error} reintentar={() => void consulta.refetch()} />
        : !consulta.data?.data.length ? <div className="reglas-empty"><Archive size={24} aria-hidden="true" /><h2>{seccion === 'versiones' ? 'Sin versiones para revisar' : 'Sin habilitaciones en este listado'}</h2><p>{tipo ? 'Pruebe con otro tipo de audiencia o quite el filtro.' : admin ? seccion === 'versiones' ? 'Prepare propuestas de turnos y selle una versión antes de solicitar su revisión.' : 'Compruebe la acreditación de una cuenta real antes de habilitarla para revisar reglas.' : 'La administración debe comprobar su acreditación y habilitar el tipo de audiencia correspondiente.'}</p>{tipo && <button type="button" className="btn btn-secondary" onClick={() => { setTipo(''); setPagina(1) }}>Quitar filtro</button>}{admin && seccion === 'versiones' && <Link className="btn btn-secondary" to="/configuracion/turnos">Ver propuestas de turnos</Link>}</div>
          : seccion === 'versiones' ? <div className="reglas-list" aria-label="Versiones selladas">{versiones.data?.data.map(v => <article className="reglas-row" key={v.id}><div className="reglas-number">{String(v.numero_version).padStart(2, '0')}</div><div><p className="reglas-kicker">{nombreTipo(v.id_tipo_audiencia)}</p><h2><Link to={`${raiz}/versiones/${v.id}`}>Versión {v.numero_version}<ArrowUpRight size={17} aria-hidden="true" /></Link></h2><p className="reglas-row-text">{v.motivo}</p><p className="reglas-meta">Sellada {fechaLegible(v.fecha_creacion)} · Autor de la copia #{v.id_usuario_autor}</p></div><EstadoRegla estado={v.revision_juridica.legal_review_status} /></article>)}</div>
            : <div className="reglas-list" aria-label="Habilitaciones jurídicas">{habilitaciones.data?.data.map(h => <article className="reglas-row reglas-habilitacion" key={h.id}><div><p className="reglas-kicker">{nombreTipo(h.id_tipo_audiencia)}</p><h2>Habilitación {h.id}</h2><p className="reglas-meta">Cuenta revisora #{h.id_usuario_revisor} · Hasta {fechaLegible(h.fecha_vencimiento)}</p><p className="reglas-row-text">{h.alcance}</p><details><summary>Evidencia y trazabilidad</summary><dl><dt>Referencia de acreditación</dt><dd>{h.referencia_acreditacion}</dd><dt>Huella del documento</dt><dd className="reglas-mono">{h.huella_acreditacion}</dd><dt>Registrada</dt><dd>{fechaLegible(h.fecha_creacion)} · Por cuenta #{h.id_usuario_habilitador}</dd>{h.revocacion && <><dt>Motivo del retiro</dt><dd>{h.revocacion.motivo}</dd></>}</dl></details></div><div className="reglas-row-actions"><EstadoRegla estado={h.estado} />{!h.revocacion && <button className="btn btn-secondary" type="button" onClick={() => setRetirar(h)}>Retirar habilitación</button>}</div></article>)}</div>}
      {consulta.data && !consulta.isError && <PaginacionReglas meta={consulta.data.meta} cambiar={setPagina} ocupada={consulta.isFetching} />}
    </div>
    {formulario === 'sellado' && <SellarVersionForm tipos={tipos.data ?? []} cerrar={cerrar} terminado={v => { cerrar(); navigate(`${raiz}/versiones/${v.id}`) }} />}
    {formulario === 'instrucciones' && <PrepararInstruccionesForm tipos={tipos.data ?? []} cerrar={cerrar} terminado={v => { cerrar(); navigate(`${raiz}/versiones/${v.id}`) }} />}
    {formulario === 'habilitacion' && <HabilitacionForm tipos={tipos.data ?? []} cerrar={cerrar} terminado={() => { cerrar(); setPagina(1); setAviso('La habilitación quedó registrada. No se asignaron permisos administrativos.') }} />}
    {retirar && <RetiroReglaForm tipo="habilitaciones" id={retirar.id} etiqueta={`Cuenta revisora #${retirar.id_usuario_revisor} · Habilitación ${retirar.id}`} cerrar={cerrar} terminado={() => { cerrar(); setAviso('La habilitación fue retirada. El historial se conserva.') }} />}
  </section>
}
