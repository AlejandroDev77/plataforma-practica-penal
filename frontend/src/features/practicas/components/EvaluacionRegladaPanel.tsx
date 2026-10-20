import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { consultarContextoEvaluacion, consultarEvaluacionReglada, errorPractica, solicitarEvaluacionReglada } from '../api/practicas-api'
import { useCurrentUser } from '../../auth/model/use-current-user'
import '../practicas.css'

const bloqueos: Record<string, string> = {
  practica_no_finalizada_o_vigente: 'La práctica debe estar cerrada y conservar su configuración revisada.',
  cierre_no_verificable: 'No se pudo comprobar el cierre completo de la audiencia.',
  rubrica_revisada_no_vigente: 'Todavía no hay una rúbrica profesional revisada y vigente para esta audiencia.',
  copia_rubrica_incompatible: 'La copia revisada de la rúbrica no cumple el formato requerido.',
  limite_intervenciones: 'El acta excede el máximo admitido para evaluación.',
  intervencion_incompatible: 'El acta contiene una intervención que no puede evaluarse con seguridad.',
  fuentes_incompatibles: 'Una referencia del acta necesita revisión antes de evaluarse.',
  sin_intervencion_defensa: 'El acta no contiene una intervención de la defensa.',
  limite_contexto: 'El acta y la rúbrica superan el límite de lectura.',
  evaluacion_ya_solicitada: 'Ya existe una solicitud para esta práctica; consulta su estado antes de intentar otra.',
}

export function EvaluacionRegladaPanel({ id }: { id: number }) {
  const usuario = useCurrentUser().data?.id
  const queryClient = useQueryClient()
  const clave = ['practicas-regladas', usuario, id, 'evaluacion'] as const
  const [consentimiento, setConsentimiento] = useState('')
  const [solicitudIncierta, setSolicitudIncierta] = useState(false)
  const evaluacion = useQuery({
    queryKey: clave,
    queryFn: ({ signal }) => consultarEvaluacionReglada(id, signal),
    enabled: Boolean(usuario), retry: false, refetchInterval: query =>
      ['pendiente', 'procesando'].includes(query.state.data?.status ?? '') ? 2500 : false,
  })
  const contexto = useQuery({
    queryKey: ['practicas-regladas', usuario, id, 'contexto-evaluacion'],
    queryFn: ({ signal }) => consultarContextoEvaluacion(id, signal),
    enabled: evaluacion.isSuccess && evaluacion.data === null,
    retry: false, staleTime: 0, gcTime: 0,
  })
  const solicitar = useMutation({
    mutationFn: (fingerprint: string) => solicitarEvaluacionReglada(id, fingerprint),
    onSuccess: resultado => {
      setConsentimiento('')
      setSolicitudIncierta(false)
      queryClient.setQueryData(clave, resultado)
      void queryClient.invalidateQueries({ queryKey: ['practicas-regladas', usuario, id, 'contexto-evaluacion'] })
    },
    onError: () => {
      setSolicitudIncierta(true)
      void queryClient.invalidateQueries({ queryKey: clave })
    },
  })
  const fingerprint = contexto.data?.fingerprint ?? ''
  const busy = evaluacion.isFetching || contexto.isFetching || solicitar.isPending
  const verificarSolicitud = async () => {
    const estado = await evaluacion.refetch()
    if (estado.data) {
      setSolicitudIncierta(false)
      return
    }
    const nuevoContexto = await contexto.refetch()
    if (nuevoContexto.data?.can_request) setSolicitudIncierta(false)
  }

  return <section className="panel practica-evaluacion" aria-labelledby="practica-evaluacion-titulo">
    <div className="practica-subtitulo"><div><p className="eyebrow">LECTURA ORIENTATIVA</p><h2 id="practica-evaluacion-titulo">Evaluación de la práctica</h2></div>
      {evaluacion.data?.status === 'procesado' && <span className="practica-estado">Revisión humana pendiente</span>}</div>

    {evaluacion.isPending && <p role="status">Consultando si ya existe una evaluación…</p>}
    {evaluacion.isError && <div className="practica-error" role="alert"><p>No se pudo consultar el estado. No vuelvas a enviar el acta hasta verificarlo.</p>
      <button className="btn btn-secondary" disabled={evaluacion.isFetching} onClick={() => void evaluacion.refetch()}>Consultar estado</button></div>}
    {evaluacion.data === null && contexto.isPending && <p role="status">Comprobando si la rúbrica permite evaluar esta práctica…</p>}
    {evaluacion.data === null && contexto.isError && <div className="practica-error" role="alert"><p>No se pudo revisar la disponibilidad de la evaluación.</p>
      <button className="btn btn-secondary" disabled={contexto.isFetching} onClick={() => void contexto.refetch()}>Volver a consultar</button></div>}

    {evaluacion.data === null && contexto.data && <>
      {!contexto.data.can_request && <div className="practica-aviso" role="status">
        <strong>La evaluación todavía no está habilitada</strong>
        <ul>{contexto.data.blocking_reasons.map(motivo => <li key={motivo}>{bloqueos[motivo] ?? 'La práctica requiere una revisión antes de evaluarse.'}</li>)}</ul>
      </div>}
      {contexto.data.can_request && contexto.data.preview && <div className="practica-evaluacion-solicitud">
        <p>La evaluación orientativa enviará a OpenAI la transcripción de esta audiencia, los extractos que acompañan las intervenciones de defensa y la rúbrica revisada. No se enviará el PDF completo ni el análisis del expediente. Puede consumir saldo de la API.</p>
        <details className="practica-evaluacion-contexto">
          <summary>Revisar exactamente lo que se enviará</summary>
          <div><strong>{contexto.data.preview.hearing_type}</strong><p>Rúbrica: {contexto.data.preview.rubric_name} · versión {contexto.data.preview.rubric_version}</p></div>
          <h3>Criterios de evaluación</h3>
          <ol>{contexto.data.preview.criteria.map(criterio => <li key={criterio.id}><strong>{criterio.name}</strong>
            <p>{criterio.description}</p><small>Puntaje máximo: {criterio.max_score} · peso: {criterio.weight}</small></li>)}</ol>
          <h3>Intervenciones del acta</h3>
          {contexto.data.preview.transcript.map(turno => <article className="practica-evaluacion-turno" key={turno.order}>
            <div className="simulation-message-heading"><strong>{turno.role === 'abogado_defensor' ? 'Defensa' : turno.role === 'fiscal' ? 'Fiscalía' : 'Juzgado'}</strong><span>Turno {turno.order}</span></div>
            <p className="practica-texto">{turno.content}</p>
            {turno.sources.map((fuente, index) => <blockquote key={`${turno.order}-${index}`}><strong>{fuente.title}</strong>
              <p>{fuente.locator} · {fuente.kind === 'expediente' ? 'Expediente' : 'Referencia jurídica'}</p>{fuente.excerpt}</blockquote>)}
          </article>)}
        </details>
        <label className="practica-check practica-consentimiento-evaluacion">
          <input type="checkbox" checked={consentimiento === fingerprint} disabled={busy || !fingerprint}
            onChange={event => setConsentimiento(event.target.checked ? fingerprint : '')} />
          <span>Autorizo enviar este contexto exacto a OpenAI para evaluar esta práctica una sola vez. Entiendo que el resultado requiere revisión humana.</span>
        </label>
        <button className="btn btn-primary" disabled={busy || solicitudIncierta || consentimiento !== fingerprint}
          onClick={() => solicitar.mutate(fingerprint)}>{solicitar.isPending ? 'Enviando solicitud…' : solicitudIncierta ? 'Solicitud por verificar' : 'Solicitar evaluación'}</button>
        {solicitar.isError && <div className="practica-error" role="alert"><p>{errorPractica(solicitar.error)} La solicitud podría haberse guardado; no se repetirá automáticamente.</p>
          <button className="btn btn-secondary" disabled={busy} onClick={() => void verificarSolicitud()}>Verificar antes de intentar otra vez</button></div>}
      </div>}
    </>}

    {evaluacion.data && ['pendiente', 'procesando'].includes(evaluacion.data.status) && <p role="status">
      {evaluacion.data.status === 'pendiente' ? 'Solicitud registrada; está esperando turno en la cola.' : 'OpenAI está preparando la lectura; este panel actualizará el estado.'}
    </p>}
    {evaluacion.data?.status === 'error' && <div className="practica-error" role="alert">
      <strong>No se pudo completar la evaluación</strong><p>{evaluacion.data.message ?? 'No hay un resultado guardado. Revisa la configuración con administración antes de autorizar otro envío.'}</p>
      <p>No se reenvió automáticamente para evitar posibles cargos duplicados.</p>
    </div>}
    {evaluacion.data?.status === 'procesado' && <div className="practica-evaluacion-resultado">
      <p className="practica-evaluacion-puntaje">Puntaje orientativo: {Number(evaluacion.data.score_percent ?? 0).toLocaleString('es-BO', { maximumFractionDigits: 1 })} / 100</p>
      <p>{evaluacion.data.summary}</p>
      {evaluacion.data.criteria?.map(criterio => <article className="practica-evaluacion-criterio" key={criterio.criterion_id}>
        <div><h3>{criterio.name}</h3><span>{criterio.score} / {criterio.max_score}</span></div><p>{criterio.feedback}</p>
        {[...criterio.strengths, ...criterio.errors].map((punto, index) => <blockquote key={index}>{punto.content}
          <small> · Turno {punto.evidence.intervention_order}: “{punto.evidence.quote}”</small></blockquote>)}
        {criterio.recommendations.length > 0 && <ul>{criterio.recommendations.map((recomendacion, index) => <li key={index}>{recomendacion}</li>)}</ul>}
      </article>)}
      <p className="practica-nota">Proveedor: OpenAI. Resultado orientativo; no representa asesoría jurídica ni decisión judicial. Requiere revisión humana.</p>
    </div>}
  </section>
}
