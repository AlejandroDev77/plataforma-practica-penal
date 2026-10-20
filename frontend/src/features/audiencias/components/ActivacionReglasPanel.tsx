import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Modal } from '../../../components/ui/AdminUi'
import { activarVersion, consultarPreparacionActivacion, listarActivaciones, retirarActivacion } from '../api/reglas-api'
import { explicarBloqueoActivacion, nombresActivacion, prepararRetiroActivacion, prepararSolicitudActivacion, type ActivacionReglas, type PreparacionActivacion } from '../model/activacion-reglas'
import { fechaLegible, type VersionReglas } from '../model/reglas'
import { useActualizarReglas, useReglasQuery } from '../model/use-reglas'
import { useRegistro } from '../model/use-registro-reglas'
import { AccionesFormulario, AvisoFormulario } from './ReglasForms'
import { CampoRegla, ErrorReglas, PaginacionReglas } from './ReglasUi'
import './activacion-reglas.css'

type Operacion = { tipo: 'activar'; consulta: PreparacionActivacion } | { tipo: 'retirar'; registro: ActivacionReglas }
function ConfirmarActivacion({ operacion, version, cerrar, terminado }: { operacion: Operacion; version: VersionReglas; cerrar: () => void; terminado: () => void }) {
  const [motivo, setMotivo] = useState('')
  const [confirmado, setConfirmado] = useState(false)
  const activar = operacion.tipo === 'activar'
  const registro = useRegistro(async () => {
    const entrada = { motivo, confirmacion_humana: confirmado }
    if (operacion.tipo === 'activar') await activarVersion(version.id, prepararSolicitudActivacion(entrada, operacion.consulta))
    else await retirarActivacion(operacion.registro.id, prepararRetiroActivacion(entrada))
  }, terminado)
  return <Modal title={activar ? 'Confirmar activación administrativa' : 'Retirar activación administrativa'} description={activar ? `Versión ${version.numero_version}. No habilita intervenciones de audiencia.` : `Registro #${operacion.registro.id} de la versión #${operacion.registro.id_version_reglas}.`} busy={registro.ocupado} onClose={cerrar}>
    <form className="modal-body reglas-form reglas-activacion-form" noValidate onSubmit={registro.enviar} aria-busy={registro.ocupado}>
      <AvisoFormulario registro={registro} />
      {operacion.tipo === 'activar' ? <dl><dt>Revisión jurídica consultada</dt><dd>Decisión #{operacion.consulta.precondicion.id_revision_esperada}</dd><dt>Activación anterior de este tipo</dt><dd>{operacion.consulta.precondicion.id_activacion_previa === null ? 'Primera activación' : `Registro #${operacion.consulta.precondicion.id_activacion_previa}. La nueva activación lo sustituye; no lo borra.`}</dd><dt>Huella de la copia</dt><dd className="reglas-mono">{operacion.consulta.precondicion.huella_version_esperada}</dd></dl> : <p className="reglas-note">El retiro conserva la evidencia. No restaura una activación anterior ni puede deshacerse editando el registro.</p>}
      <fieldset disabled={registro.ocupado || registro.bloqueado}><legend className="reglas-sr">Motivo y confirmación</legend>
        <CampoRegla nombre="motivo" etiqueta={activar ? 'Motivo de la activación' : 'Motivo del retiro'} errores={registro.errores}>{atributos => <textarea {...atributos} required rows={4} maxLength={2000} value={motivo} onChange={e => { setMotivo(e.target.value); setConfirmado(false) }} />}</CampoRegla>
        <CampoRegla nombre="confirmacion_humana" etiqueta={activar ? 'Confirmación de la activación' : 'Confirmación del retiro'} errores={registro.errores}>{atributos => <span className="reglas-check"><input {...atributos} type="checkbox" checked={confirmado} onChange={e => setConfirmado(e.target.checked)} /><span>{activar ? 'Comprobé esta copia y su revisión vigente. Confirmo su activación administrativa y entiendo que todavía no habilita turnos.' : 'Confirmo el retiro de este registro. Entiendo que el historial se conserva y no se recupera una activación anterior.'}</span></span>}</CampoRegla>
      </fieldset><AccionesFormulario registro={registro} cancelar={cerrar} texto={activar ? 'Registrar activación' : 'Confirmar retiro'} />
    </form>
  </Modal>
}

/** Solo se monta en el detalle administrativo. El servidor revalida cada operación. */
export function ActivacionReglasPanel({ version }: { version: VersionReglas }) {
  const [pagina, setPagina] = useState(1)
  const [operacion, setOperacion] = useState<Operacion | null>(null)
  const [aviso, setAviso] = useState('')
  const foco = useRef<HTMLElement>(null)
  const actualizar = useActualizarReglas()
  const consulta = useReglasQuery(['preparacion-activacion', version.id, version.huella_contenido], signal => consultarPreparacionActivacion(version, signal))
  const historia = useReglasQuery(['activaciones', version.id_tipo_audiencia, pagina], signal => listarActivaciones(version.id_tipo_audiencia, pagina, signal))
  function cerrar() { setOperacion(null); void actualizar().finally(() => foco.current?.focus()) }
  const p = consulta.data
  return <section ref={foco} tabIndex={-1} className="reglas-history reglas-activacion" aria-labelledby="activacion-administrativa">
    <header><div className="reglas-decision-head"><div><h2 id="activacion-administrativa">Activación administrativa</h2><p>Elija la copia revisada para este tipo de audiencia. Las prácticas existentes no cambian.</p></div><button type="button" className="btn btn-secondary" disabled={consulta.isFetching || historia.isFetching} onClick={() => { setAviso(''); void actualizar() }}>Consultar activación</button></div></header>
    {aviso && <p className="reglas-success" role="status">{aviso}</p>}
    {consulta.isPending ? <p className="reglas-empty" role="status">Comprobando las condiciones de activación…</p> : consulta.isError ? <ErrorReglas error={consulta.error} reintentar={() => void consulta.refetch()} /> : p && <article className="reglas-decision">
      <div className="reglas-decision-head"><div><p className="reglas-kicker">Estado del tipo de audiencia</p><strong>{nombresActivacion[p.activacion_actual.activation_status]}</strong>{p.activacion_actual.activation_id !== null && <p className="reglas-meta">Registro #{p.activacion_actual.activation_id} · Versión #{p.activacion_actual.version_id}{p.activacion_actual.version_id === version.id ? ' (esta copia)' : ' (otra copia)'}</p>}</div>{p.puede_solicitar_activacion && <button type="button" className="btn btn-primary" disabled={consulta.isFetching} onClick={() => setOperacion({ tipo: 'activar', consulta: p })}>Preparar activación</button>}</div>
      <p>Los turnos siguen sin habilitarse. Aún falta vincular las reglas a cada nueva práctica.</p>
      {!p.puede_solicitar_activacion && <><p className="reglas-note">{p.activacion_actual.activation_current && p.activacion_actual.version_id === version.id ? 'Para registrar otra activación:' : 'Para activar esta copia:'}</p><ul>{[...new Set(p.bloqueos.map(explicarBloqueoActivacion))].map(texto => <li key={texto}>{texto}</li>)}</ul></>}
    </article>}
    <details className="reglas-structure"><summary>Historial de activaciones de este tipo</summary><p className="reglas-note">Un retiro o vencimiento no restaura registros anteriores. Este historial no indica permiso para intervenir.</p>
      {historia.isPending ? <p role="status">Consultando el historial…</p> : historia.isError ? <ErrorReglas error={historia.error} reintentar={() => void historia.refetch()} /> : !historia.data?.data.length ? <p>No se registraron activaciones administrativas.</p> : historia.data.data.map(a => <article className="reglas-decision" key={a.id}>
        <div className="reglas-decision-head"><div><h3>Registro #{a.id}</h3><Link className="reglas-back" to={`/configuracion/reglas/versiones/${a.id_version_reglas}`}>Consultar versión #{a.id_version_reglas}</Link><p className="reglas-meta">{a.revocacion ? 'Retirada' : 'Registro conservado; consulte el estado actual'} · {fechaLegible(a.fecha_creacion)}</p></div>{!a.revocacion && <button type="button" className="btn btn-secondary" disabled={historia.isFetching} onClick={() => setOperacion({ tipo: 'retirar', registro: a })}>Retirar registro #{a.id}</button>}</div><p>{a.motivo}</p>
        <dl><dt>Responsable</dt><dd>Cuenta #{a.id_usuario_responsable}</dd><dt>Revisión utilizada</dt><dd>Decisión #{a.id_revision_reglas}</dd><dt>Activación anterior</dt><dd>{a.id_activacion_previa ?? 'Ninguna'}</dd>{a.revocacion && <><dt>Motivo del retiro</dt><dd>{a.revocacion.motivo}</dd><dt>Retirada por</dt><dd>Cuenta #{a.revocacion.id_usuario_responsable} · {fechaLegible(a.revocacion.fecha_creacion)}</dd></>}</dl>
      </article>)}
      {historia.data && !historia.isError && <PaginacionReglas meta={historia.data.meta} cambiar={setPagina} ocupada={historia.isFetching} />}
    </details>
    {operacion && <ConfirmarActivacion operacion={operacion} version={version} cerrar={cerrar} terminado={() => { cerrar(); setPagina(1); setAviso(operacion.tipo === 'activar' ? 'La activación administrativa quedó registrada. Los turnos no se habilitaron.' : 'El retiro quedó registrado. No se restauró una activación anterior.') }} />}
  </section>
}
