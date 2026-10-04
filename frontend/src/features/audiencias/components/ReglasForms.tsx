import { useRef, useState, type FormEvent } from 'react'
import axios from 'axios'
import { ZodError } from 'zod'
import { Modal } from '../../../components/ui/AdminUi'
import { CampoRegla, HuellaDocumento } from './ReglasUi'
import { datosHabilitacion, datosRetiro, datosSellado, consultarInstrucciones, hoyBolivia, prepararRevision, type Habilitacion, type VersionReglas } from '../model/reglas'
import { habilitarRevisor, registrarRevision, retirarRegistro, sellarVersion } from '../api/reglas-api'
import type { TipoAudienciaDisponible } from '../api/propuestas-turnos-api'

function useRegistro(guardar: () => Promise<unknown>, terminado: () => void) {
  const cerrojo = useRef(false)
  const [ocupado, setOcupado] = useState(false)
  const [bloqueado, setBloqueado] = useState(false)
  const [error, setError] = useState('')
  const [errores, setErrores] = useState<Record<string, string>>({})
  async function enviar(evento: FormEvent) {
    evento.preventDefault()
    if (cerrojo.current || bloqueado) return
    cerrojo.current = true; setOcupado(true); setError(''); setErrores({})
    try { await guardar(); terminado() }
    catch (fallo) {
      if (fallo instanceof ZodError) {
        setErrores(Object.fromEntries(fallo.issues.map(i => [i.path.join('.'), i.message])))
        setError('Revise los campos indicados antes de registrar.')
      } else if (axios.isAxiosError(fallo)) {
        const estado = fallo.response?.status
        if (estado === 422) {
          setErrores(Object.fromEntries(Object.keys(fallo.response?.data?.errors ?? {}).map(c => [c, 'Revise este campo.'])))
          setError('Hay datos incompletos o inválidos. Revise los campos indicados.')
        } else {
          setBloqueado(true)
          setError(estado === 409 ? 'El registro cambió o la operación no está permitida. Cierre el formulario y vuelva a revisar el registro antes de decidir.'
            : estado === 401 || estado === 419 ? 'Su sesión venció. Cierre el formulario e inicie sesión de nuevo.'
              : estado === 403 || estado === 404 ? 'Su cuenta ya no puede realizar esta operación. Cierre y compruebe su habilitación.'
                : 'No recibimos una confirmación del servidor. Cierre y vuelva a consultar antes de repetir: la solicitud podría haberse registrado.')
        }
      } else setError(fallo instanceof Error ? fallo.message : 'No fue posible registrar esta operación.')
    } finally { cerrojo.current = false; setOcupado(false) }
  }
  return { enviar, ocupado, bloqueado, error, errores }
}
function AvisoFormulario({ registro }: { registro: ReturnType<typeof useRegistro> }) {
  return registro.error ? <p className="reglas-error" role="alert" tabIndex={-1}>{registro.error}</p> : null
}
function AccionesFormulario({ registro, cancelar, texto }: { registro: ReturnType<typeof useRegistro>; cancelar: () => void; texto: string }) {
  return <div className="modal-actions"><button type="button" className="btn btn-secondary" disabled={registro.ocupado} onClick={cancelar}>{registro.bloqueado ? 'Cerrar y consultar' : 'Cancelar'}</button><button type="submit" className="btn btn-primary" disabled={registro.ocupado || registro.bloqueado}>{registro.ocupado ? 'Registrando…' : texto}</button></div>
}
export function SellarVersionForm({ tipos, cerrar, terminado }: { tipos: TipoAudienciaDisponible[]; cerrar: () => void; terminado: (version: VersionReglas) => void }) {
  const [tipo, setTipo] = useState('')
  const [motivo, setMotivo] = useState('')
  const [confirmado, setConfirmado] = useState(false)
  const registro = useRegistro(async () => {
    const datos = datosSellado.parse({ id_tipo_audiencia: tipo, motivo, confirmado })
    terminado(await sellarVersion(datos.id_tipo_audiencia, datos.motivo))
  }, () => {})
  return <Modal title="Sellar una versión" description="Conserve una copia del catálogo y sus propuestas para revisión. No se activa ningún turno." busy={registro.ocupado} onClose={cerrar}>
    <form className="modal-body reglas-form" noValidate onSubmit={registro.enviar} aria-busy={registro.ocupado}><AvisoFormulario registro={registro} /><fieldset disabled={registro.ocupado || registro.bloqueado}><legend className="reglas-sr">Datos del sellado</legend>
      <CampoRegla nombre="id_tipo_audiencia" etiqueta="Tipo de audiencia" errores={registro.errores}>{atributos => <select {...atributos} value={tipo} onChange={e => { setTipo(e.target.value); setConfirmado(false) }} required><option value="">Seleccione una audiencia</option>{tipos.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}</select>}</CampoRegla>
      <CampoRegla nombre="motivo" etiqueta="Motivo de esta versión" errores={registro.errores}>{atributos => <textarea {...atributos} value={motivo} onChange={e => { setMotivo(e.target.value); setConfirmado(false) }} rows={3} required maxLength={2000} />}</CampoRegla>
      <CampoRegla nombre="confirmado" etiqueta="Confirmación del sellado" errores={registro.errores}>{atributos => <span className="reglas-check"><input {...atributos} type="checkbox" checked={confirmado} onChange={e => setConfirmado(e.target.checked)} /><span>Revisé el borrador que se conservará. Entiendo que la versión no podrá editarse ni borrarse.</span></span>}</CampoRegla>
    </fieldset><AccionesFormulario registro={registro} cancelar={cerrar} texto="Sellar versión" /></form>
  </Modal>
}
export function HabilitacionForm({ tipos, cerrar, terminado }: { tipos: TipoAudienciaDisponible[]; cerrar: () => void; terminado: () => void }) {
  const [datos, setDatos] = useState({ id_usuario_revisor: '', id_tipo_audiencia: '', alcance: '', referencia_acreditacion: '', huella_acreditacion: '', fecha_vencimiento: '', acreditacion_verificada: false })
  const registro = useRegistro(async () => { await habilitarRevisor(datosHabilitacion.parse(datos)) }, terminado)
  function campo(nombre: 'id_usuario_revisor' | 'alcance' | 'referencia_acreditacion' | 'fecha_vencimiento', etiqueta: string, hint?: string) {
    return <CampoRegla nombre={nombre} etiqueta={etiqueta} errores={registro.errores} hint={hint}>{atributos => nombre === 'alcance'
      ? <textarea {...atributos} required rows={3} maxLength={2000} value={datos[nombre]} onChange={e => setDatos({ ...datos, [nombre]: e.target.value, acreditacion_verificada: false })} />
      : <input {...atributos} required type={nombre === 'fecha_vencimiento' ? 'datetime-local' : nombre === 'id_usuario_revisor' ? 'number' : 'text'} min={nombre === 'id_usuario_revisor' ? 1 : undefined} maxLength={nombre === 'referencia_acreditacion' ? 500 : undefined} value={datos[nombre]} onChange={e => setDatos({ ...datos, [nombre]: e.target.value, acreditacion_verificada: false })} />}</CampoRegla>
  }
  return <Modal title="Habilitar una persona revisora" description="Compruebe identidad, competencia y acreditación antes de confirmar. Este registro no asigna roles administrativos." busy={registro.ocupado} onClose={cerrar}>
    <form className="modal-body reglas-form" noValidate onSubmit={registro.enviar} aria-busy={registro.ocupado}><AvisoFormulario registro={registro} /><fieldset disabled={registro.ocupado || registro.bloqueado}><legend>Persona y ámbito</legend>
      <div className="form-grid">{campo('id_usuario_revisor', 'Identificador de la cuenta', 'Use el ID de una cuenta real cuya identidad comprobó. No puede habilitarse a sí mismo.')}
      <CampoRegla nombre="id_tipo_audiencia" etiqueta="Tipo de audiencia" errores={registro.errores}>{atributos => <select {...atributos} required value={datos.id_tipo_audiencia} onChange={e => setDatos({ ...datos, id_tipo_audiencia: e.target.value, acreditacion_verificada: false })}><option value="">Seleccione una audiencia</option>{tipos.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}</select>}</CampoRegla></div>
      {campo('alcance', 'Alcance de la revisión', 'Describa las materias y los límites de esta habilitación.')}{campo('fecha_vencimiento', 'Vencimiento de la habilitación', 'Hora local de este equipo; se guardará con zona horaria.')}
    </fieldset><fieldset disabled={registro.ocupado || registro.bloqueado}><legend>Evidencia de acreditación</legend>{campo('referencia_acreditacion', 'Referencia de la acreditación', 'Indique cómo localizar la evidencia comprobada; no incluya contraseñas ni secretos.')}
      <HuellaDocumento nombre="huella_acreditacion" etiqueta="Documento de acreditación" valor={datos.huella_acreditacion} cambiar={v => setDatos(prev => ({ ...prev, huella_acreditacion: v, acreditacion_verificada: false }))} errores={registro.errores} deshabilitado={registro.ocupado || registro.bloqueado} />
      <CampoRegla nombre="acreditacion_verificada" etiqueta="Comprobación humana" errores={registro.errores}>{atributos => <span className="reglas-check"><input {...atributos} type="checkbox" checked={datos.acreditacion_verificada} onChange={e => setDatos({ ...datos, acreditacion_verificada: e.target.checked })} /><span>Comprobé la identidad, la competencia profesional y la evidencia de acreditación para este ámbito.</span></span>}</CampoRegla>
    </fieldset><AccionesFormulario registro={registro} cancelar={cerrar} texto="Registrar habilitación" /></form>
  </Modal>
}
export function RevisionReglasForm({ version, habilitacion, usuario, cerrar, terminado }: { version: VersionReglas; habilitacion: Habilitacion; usuario: number; cerrar: () => void; terminado: () => void }) {
  const [datos, setDatos] = useState({ decision: 'requiere_cambios', observacion: '', vigencia_comprobada_en: '', confirmacion_humana: false, confirmacion_instrucciones: false, fecha_vencimiento: '', fuentes_verificadas: [{ clave: crypto.randomUUID(), referencia: '', huella_contenido: '' }] })
  const instrucciones = consultarInstrucciones(version)
  const registro = useRegistro(async () => { await registrarRevision(version.id, prepararRevision(datos, version, habilitacion, usuario)) }, terminado)
  const fuente = (clave: string, campo: 'referencia' | 'huella_contenido', valor: string) => setDatos(prev => ({ ...prev, confirmacion_humana: false, confirmacion_instrucciones: false, fuentes_verificadas: prev.fuentes_verificadas.map(f => f.clave === clave ? { ...f, [campo]: valor } : f) }))
  return <Modal title={`Revisar versión ${version.numero_version}`} description={`${version.contenido?.tipo.nombre}. Su decisión quedará ligada a esta copia, no al catálogo editable.`} busy={registro.ocupado} onClose={cerrar}>
    <form className="modal-body reglas-form" noValidate onSubmit={registro.enviar} aria-busy={registro.ocupado}><AvisoFormulario registro={registro} /><p className="reglas-note">Ámbito: {habilitacion.alcance}. Habilitación hasta {new Date(habilitacion.fecha_vencimiento).toLocaleDateString('es-BO')}.</p>
      <fieldset disabled={registro.ocupado || registro.bloqueado}><legend>Conclusión de la revisión</legend>
        <CampoRegla nombre="decision" etiqueta="Decisión" errores={registro.errores}>{atributos => <select {...atributos} value={datos.decision} onChange={e => setDatos({ ...datos, decision: e.target.value, fecha_vencimiento: '', confirmacion_humana: false, confirmacion_instrucciones: false })}><option value="requiere_cambios">Requiere cambios</option><option value="aprobado">Aprobar la revisión</option></select>}</CampoRegla>
        <CampoRegla nombre="observacion" etiqueta="Fundamento y observaciones" errores={registro.errores}>{atributos => <textarea {...atributos} required rows={4} maxLength={5000} value={datos.observacion} onChange={e => setDatos({ ...datos, observacion: e.target.value, confirmacion_humana: false, confirmacion_instrucciones: false })} />}</CampoRegla>
        <CampoRegla nombre="vigencia_comprobada_en" etiqueta="Fecha de comprobación de vigencia" errores={registro.errores}>{atributos => <input {...atributos} required type="date" max={hoyBolivia()} value={datos.vigencia_comprobada_en} onChange={e => setDatos({ ...datos, vigencia_comprobada_en: e.target.value, confirmacion_humana: false, confirmacion_instrucciones: false })} />}</CampoRegla>
        {datos.decision === 'aprobado' && <CampoRegla nombre="fecha_vencimiento" etiqueta="Vencimiento de esta revisión" errores={registro.errores} hint="Fecha futura, no posterior a la habilitación. Hora local de este equipo.">{atributos => <input {...atributos} required type="datetime-local" value={datos.fecha_vencimiento} onChange={e => setDatos({ ...datos, fecha_vencimiento: e.target.value, confirmacion_humana: false, confirmacion_instrucciones: false })} />}</CampoRegla>}
      </fieldset><fieldset disabled={registro.ocupado || registro.bloqueado}><legend>Fuentes comprobadas</legend><p className="reglas-note">Identifique la versión de cada fuente jurídica que comprobó. No use huellas inventadas.</p>
        {datos.fuentes_verificadas.map((f, i) => <div className="reglas-fuente-form" key={f.clave}><h3>Fuente {i + 1}</h3><CampoRegla nombre={`fuentes_verificadas.${i}.referencia`} etiqueta={`Referencia de la fuente ${i + 1}`} errores={registro.errores}>{atributos => <textarea {...atributos} required rows={2} maxLength={500} value={f.referencia} onChange={e => fuente(f.clave, 'referencia', e.target.value)} />}</CampoRegla>
          <HuellaDocumento nombre={`fuentes_verificadas.${i}.huella_contenido`} etiqueta={`Documento de la fuente ${i + 1}`} valor={f.huella_contenido} cambiar={v => fuente(f.clave, 'huella_contenido', v)} errores={registro.errores} deshabilitado={registro.ocupado || registro.bloqueado} />
          {datos.fuentes_verificadas.length > 1 && <button type="button" className="btn btn-secondary" aria-label={`Quitar fuente ${i + 1}`} onClick={() => setDatos({ ...datos, confirmacion_humana: false, confirmacion_instrucciones: false, fuentes_verificadas: datos.fuentes_verificadas.filter((_, indice) => indice !== i) })}>Quitar fuente</button>}</div>)}
        <button type="button" className="btn btn-secondary" disabled={datos.fuentes_verificadas.length >= 20} onClick={() => setDatos({ ...datos, confirmacion_humana: false, confirmacion_instrucciones: false, fuentes_verificadas: [...datos.fuentes_verificadas, { clave: crypto.randomUUID(), referencia: '', huella_contenido: '' }] })}>Añadir fuente</button>
        {instrucciones.estado === 'disponible' && <div className="reglas-cobertura"><p>Esta decisión incluye {instrucciones.contrato.instrucciones.length} {instrucciones.contrato.instrucciones.length === 1 ? 'instrucción conservada' : 'instrucciones conservadas'} en la versión {version.numero_version}, junto con sus propuestas y fuentes.</p>
          <CampoRegla nombre="confirmacion_instrucciones" etiqueta="Lectura de las instrucciones" errores={registro.errores}>{atributos => <span className="reglas-check"><input {...atributos} type="checkbox" checked={datos.confirmacion_instrucciones} onChange={e => setDatos({ ...datos, confirmacion_instrucciones: e.target.checked, confirmacion_humana: false })} /><span>Leí todas las instrucciones de esta copia y comprobé su correspondencia con cada etapa, turno y rol.</span></span>}</CampoRegla>
        </div>}
        <CampoRegla nombre="confirmacion_humana" etiqueta="Confirmación de la revisión" errores={registro.errores}>{atributos => <span className="reglas-check"><input {...atributos} type="checkbox" checked={datos.confirmacion_humana} onChange={e => setDatos({ ...datos, confirmacion_humana: e.target.checked })} /><span>Leí esta versión, comprobé sus fuentes y vigencia y asumo la decisión registrada. Entiendo que no activa reglas de audiencia.</span></span>}</CampoRegla>
      </fieldset><AccionesFormulario registro={registro} cancelar={cerrar} texto="Registrar decisión" /></form>
  </Modal>
}
export function RetiroReglaForm({ tipo, id, etiqueta, cerrar, terminado }: { tipo: 'habilitaciones' | 'revisiones'; id: number; etiqueta: string; cerrar: () => void; terminado: () => void }) {
  const [motivo, setMotivo] = useState('')
  const [confirmado, setConfirmado] = useState(false)
  const registro = useRegistro(async () => { const datos = datosRetiro.parse({ motivo, confirmado }); await retirarRegistro(tipo, id, datos.motivo) }, terminado)
  return <Modal title={`Retirar ${tipo === 'habilitaciones' ? 'habilitación' : 'revisión'}`} description={etiqueta} busy={registro.ocupado} onClose={cerrar}><form className="modal-body reglas-form" noValidate onSubmit={registro.enviar} aria-busy={registro.ocupado}><AvisoFormulario registro={registro} /><fieldset disabled={registro.ocupado || registro.bloqueado}>
    <legend className="reglas-sr">Confirmación del retiro</legend><p className="reglas-note">El retiro quedará en el historial. No borra registros ni recupera una aprobación anterior.</p>
    <CampoRegla nombre="motivo" etiqueta="Motivo del retiro" errores={registro.errores}>{atributos => <textarea {...atributos} required rows={3} maxLength={2000} value={motivo} onChange={e => { setMotivo(e.target.value); setConfirmado(false) }} />}</CampoRegla>
    <CampoRegla nombre="confirmado" etiqueta="Confirmación del retiro" errores={registro.errores}>{atributos => <span className="reglas-check"><input {...atributos} type="checkbox" checked={confirmado} onChange={e => setConfirmado(e.target.checked)} /><span>Entiendo el efecto del retiro y confirmo esta operación.</span></span>}</CampoRegla>
  </fieldset><AccionesFormulario registro={registro} cancelar={cerrar} texto="Confirmar retiro" /></form></Modal>
}
