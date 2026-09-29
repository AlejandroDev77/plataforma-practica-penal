import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, ClipboardCheck, LoaderCircle, RotateCcw } from 'lucide-react'
import { getAuthErrorMessage } from '../../auth/api/auth-api'
import { requestSimulationEvaluation } from '../api/simulations-api'
import { simulationsQueryKey, useLatestSimulationEvaluation } from '../model/use-simulations'
import type { EvaluationPoint, SimulationEvaluation } from '../model/simulation'

function EvaluationPoints({ title, points, tone }: { title: string; points: EvaluationPoint[]; tone: 'positive' | 'caution' }) {
  if (points.length === 0) return null

  return <section className={`evaluation-feedback evaluation-feedback-${tone}`} aria-label={title}>
    <h3>{title}<span>{points.length}</span></h3>
    <ul>{points.map((point, index) => <li key={`${point.criterion_id}-${point.evidence.intervention_order}-${index}`}>
      <strong>{point.criterion_name}</strong>
      <p>{point.content}</p>
      <blockquote>“{point.evidence.quote}”</blockquote>
      <small>Intervención {point.evidence.intervention_order}</small>
    </li>)}</ul>
  </section>
}

function EvaluationResult({ evaluation }: { evaluation: SimulationEvaluation }) {
  const rawScore = evaluation.score_percent === null ? null : Number(evaluation.score_percent)
  const score = rawScore === null || !Number.isFinite(rawScore) ? null : Math.round(rawScore * 10) / 10

  return <div className="evaluation-result" aria-live="polite">
    <div className="evaluation-score-row">
      <div className="evaluation-score" role="group" aria-label={score === null ? 'Puntaje no disponible' : `Puntaje orientativo: ${score} de 100`}>
        <span className="eyebrow">PUNTAJE ORIENTATIVO</span>
        <strong>{score === null ? '—' : score.toLocaleString('es-BO', { maximumFractionDigits: 1 })}<small>{score === null ? '' : ' / 100'}</small></strong>
      </div>
      <div className="evaluation-rubric">
        <span className="eyebrow">CRITERIOS APLICADOS</span>
        <strong>{evaluation.rubric?.name ?? 'Rúbrica revisada'}</strong>
        {evaluation.rubric && <span>Versión {evaluation.rubric.version} · {evaluation.criteria.length} criterios</span>}
      </div>
    </div>

    {evaluation.summary && <p className="evaluation-summary">{evaluation.summary}</p>}

    <div className="evaluation-feedback-grid">
      <EvaluationPoints title="Fortalezas observadas" points={evaluation.strengths} tone="positive" />
      <EvaluationPoints title="Aspectos por revisar" points={evaluation.errors} tone="caution" />
    </div>

    {evaluation.recommendations.length > 0 && <section className="evaluation-recommendations" aria-label="Sugerencias de práctica">
      <h3>Sugerencias para otra práctica</h3>
      <ul>{evaluation.recommendations.map((item, index) => <li key={`${item.criterion_id}-${index}`}><span>{item.criterion_name}</span><p>{item.content}</p></li>)}</ul>
    </section>}

    {evaluation.criteria.length > 0 && <details className="evaluation-criteria">
      <summary>Ver desglose por criterio</summary>
      <ol>{evaluation.criteria.map((item) => <li key={item.criterion_id}>
        <div><strong>{item.name ?? 'Criterio'}</strong><span>{item.max_score === null ? item.score : `${item.score} / ${item.max_score}`}</span></div>
        <p>{item.feedback}</p>
        {item.evidence && <small>Evidencia registrada: {item.evidence}</small>}
      </li>)}</ol>
    </details>}
  </div>
}

function requestErrorMessage(error: unknown): string {
  const message = getAuthErrorMessage(error)
  if (/rúbrica/i.test(message) && /(activa|aplicable|criterios)/i.test(message)) {
    return 'Aún no hay una rúbrica revisada y habilitada para esta práctica. El equipo académico debe configurarla antes de solicitar una devolución.'
  }

  return message
}

function evaluationStatus(status: SimulationEvaluation['status']): string {
  return status === 'pendiente' ? 'En espera para comenzar' : 'Preparando devolución'
}

export function SimulationEvaluationPanel({ simulationId }: { simulationId: number }) {
  const queryClient = useQueryClient()
  const evaluationQuery = useLatestSimulationEvaluation(simulationId)
  const evaluation = evaluationQuery.data
  const processing = evaluation?.status === 'pendiente' || evaluation?.status === 'procesando'
  const request = useMutation({
    mutationFn: () => requestSimulationEvaluation(simulationId),
    onSuccess: async () => queryClient.invalidateQueries({ queryKey: [...simulationsQueryKey, simulationId, 'evaluacion'] }),
  })
  const canRequest = !processing && evaluation?.status !== 'procesado'

  return <section className="panel evaluation-panel" aria-labelledby="evaluation-title">
    <div className="simulation-panel-heading evaluation-heading">
      <div><p className="eyebrow">CIERRE DE LA PRÁCTICA</p><h2 id="evaluation-title">Devolución formativa</h2></div>
      {evaluation?.status === 'procesado' && <span className="evaluation-complete-mark"><Check size={14} />Disponible</span>}
    </div>
    <p className="evaluation-intro">Una lectura orientativa de la intervención de defensa, basada en una rúbrica revisada y con sus citas de origen.</p>

    {evaluationQuery.isPending && <div className="evaluation-state" role="status"><LoaderCircle size={16} className="spin" />Consultando si hay una devolución guardada…</div>}
    {evaluationQuery.isError && <div className="evaluation-state is-error" role="alert"><p>No pudimos consultar la devolución. Puede volver a intentarlo.</p><button className="btn btn-secondary" onClick={() => void evaluationQuery.refetch()}>Consultar de nuevo</button></div>}

    {!evaluationQuery.isPending && !evaluationQuery.isError && evaluation === null && !request.isError && <div className="evaluation-empty">
      <div><strong>La práctica está lista para una devolución</strong><p>La solicitud solo se habilita si existe una rúbrica activa revisada para esta audiencia.</p></div>
      <button className="btn btn-primary" disabled={request.isPending} onClick={() => { request.reset(); request.mutate() }}>
        {request.isPending ? <><LoaderCircle size={15} className="spin" />Solicitando…</> : <><ClipboardCheck size={15} />Solicitar devolución</>}
      </button>
    </div>}

    {evaluation?.status === 'pendiente' || evaluation?.status === 'procesando' ? <div className="evaluation-state" role="status" aria-live="polite"><LoaderCircle size={16} className="spin" /><div><strong>{evaluationStatus(evaluation.status)}</strong><p>El resultado se actualizará aquí al terminar; puedes permanecer en esta página o volver después.</p></div></div> : null}

    {evaluation?.status === 'error' && <div className="evaluation-state is-error" role="alert"><AlertTriangle size={17} /><div><strong>No se pudo completar esta devolución</strong><p>{evaluation.message ?? 'La práctica no se modifica. Puede volver a solicitar el procesamiento local.'}</p></div></div>}

    {evaluation?.status === 'procesado' && <EvaluationResult evaluation={evaluation} />}

    {request.isError && <div className="evaluation-request-error" role="alert"><AlertTriangle size={16} /><p>{requestErrorMessage(request.error)}</p></div>}
    {canRequest && evaluationQuery.isSuccess && (evaluation !== null || request.isError) && <button className="btn btn-secondary evaluation-retry" disabled={request.isPending} onClick={() => { request.reset(); request.mutate() }}>
      {request.isPending ? <><LoaderCircle size={15} className="spin" />Solicitando…</> : <><RotateCcw size={15} />{evaluation?.status === 'error' ? 'Volver a solicitar' : 'Intentar de nuevo'}</>}
    </button>}

    <div className="evaluation-safety-note"><AlertTriangle size={15} aria-hidden="true" /><p>Orientación académica, no confirma hechos ni sustituye revisión profesional o asesoría jurídica.</p></div>
  </section>
}
