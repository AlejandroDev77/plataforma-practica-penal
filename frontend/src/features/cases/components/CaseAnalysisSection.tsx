import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, CircleHelp, FileSearch, History, LoaderCircle, ShieldCheck } from 'lucide-react'
import { getAuthErrorMessage } from '../../auth/api/auth-api'
import { reviewCaseAnalysis } from '../api/cases-api'
import { casesQueryKey, useCaseAnalysis } from '../model/use-cases'
import type { AnalysisCitation, AnalysisFinding, AnalysisReviewDecision, CaseAnalysis } from '../model/case-analysis'
import { shortDate } from '../../../shared/lib/admin-utils'

const decisionLabel: Record<AnalysisReviewDecision, string> = {
  aprobado: 'Aprobado para uso',
  requiere_cambios: 'Requiere revisión',
}

const certaintyLabel: Record<NonNullable<AnalysisFinding['certainty']>, string> = {
  textual: 'Consta literalmente',
  inferido: 'Interpretación por verificar',
  incierto: 'Incierto',
}

function citationsOf(analysis: CaseAnalysis): AnalysisCitation[] {
  const findings = [analysis.summary, analysis.procedural_stage, ...analysis.participants, ...analysis.offenses, ...analysis.facts, ...analysis.evidence, ...analysis.chronology, ...analysis.missing_information, ...analysis.uncertainties]
  return findings.flatMap((finding) => finding ? [...(finding.sources ?? []), ...(finding.context_sources ?? [])] : [])
}

function CitationList({ citations }: { citations: AnalysisCitation[] }) {
  if (citations.length === 0) return <p className="analysis-no-citations">Sin referencia documental asociada.</p>

  return <ul className="analysis-citations" aria-label="Referencias del documento">
    {citations.map((citation, index) => <li className={citation.available ? '' : 'citation-unavailable'} key={`${citation.file_name ?? 'retirada'}-${citation.locator ?? index}-${index}`}>
      {citation.available ? <FileSearch size={15} aria-hidden="true" /> : <AlertTriangle size={15} aria-hidden="true" />}
      <div>
        <strong>{citation.available ? `${citation.file_name} · ${citation.locator ?? `Página ${citation.page_number}`}` : 'Referencia no disponible'}</strong>
        {citation.available && citation.excerpt && <blockquote>“{citation.excerpt}”</blockquote>}
        {!citation.available && <span>El archivo pudo retirarse o el extracto ya no coincide. No se puede aprobar este análisis.</span>}
      </div>
    </li>)}
  </ul>
}

function DetailGroup({ title, items, label, detail }: {
  title: string
  items: AnalysisFinding[]
  label: (finding: AnalysisFinding) => string
  detail: (finding: AnalysisFinding) => string | null
}) {
  if (items.length === 0) return null
  return <section className="analysis-group" aria-label={title}>
    <div className="analysis-group-heading"><h3>{title}</h3><span>{items.length}</span></div>
    {items.map((finding, index) => <article className="analysis-finding" key={`${label(finding)}-${index}`}>
      <div className="analysis-finding-title"><strong>{label(finding)}</strong>{finding.certainty && <span className={`certainty certainty-${finding.certainty}`}>{certaintyLabel[finding.certainty]}</span>}</div>
      {detail(finding) && <p>{detail(finding)}</p>}
      <CitationList citations={finding.sources ?? finding.context_sources ?? []} />
    </article>)}
  </section>
}

export function CaseAnalysisSection({ caseId }: { caseId: number }) {
  const queryClient = useQueryClient()
  const analysisQuery = useCaseAnalysis(caseId)
  const [observation, setObservation] = useState('')
  const analysis = analysisQuery.data
  const citations = analysis ? citationsOf(analysis) : []
  const citationsVerifiable = citations.length > 0 && citations.every((citation) => citation.available)

  const review = useMutation({
    mutationFn: (decision: AnalysisReviewDecision) => reviewCaseAnalysis(caseId, analysis?.id ?? 0, decision, observation.trim()),
    onSuccess: async () => {
      setObservation('')
      await queryClient.invalidateQueries({ queryKey: [...casesQueryKey, caseId, 'analisis'] })
    },
  })

  return <section className="panel analysis-panel" aria-labelledby="analysis-heading">
    <div className="analysis-header">
      <div>
        <p className="eyebrow">REVISIÓN DEL EXPEDIENTE</p>
        <h2 id="analysis-heading">Lectura estructurada</h2>
        <p className="analysis-intro">Cada afirmación conserva su referencia de origen y espera validación profesional.</p>
      </div>
      {analysis && <span className={`analysis-review-state review-${analysis.review_state}`}>
        {analysis.review_state === 'aprobado' ? <ShieldCheck size={15} /> : analysis.review_state === 'requiere_cambios' ? <AlertTriangle size={15} /> : <CircleHelp size={15} />}
        {analysis.review_state === 'pendiente' ? 'Pendiente de revisión' : decisionLabel[analysis.review_state]}
      </span>}
    </div>

    {analysisQuery.isPending && <div className="analysis-state" role="status"><LoaderCircle size={17} className="spin" />Consultando el análisis vinculado al expediente…</div>}
    {analysisQuery.isError && <div className="analysis-error" role="alert"><p>{getAuthErrorMessage(analysisQuery.error)}</p><button className="btn btn-secondary" onClick={() => void analysisQuery.refetch()}>Reintentar</button></div>}
    {!analysisQuery.isPending && !analysisQuery.isError && !analysis && <div className="analysis-empty">
      <span><FileSearch size={21} /></span>
      <div><strong>Aún no hay un análisis disponible.</strong><p>La extracción documental está lista, pero falta acordar el tratamiento del contenido antes de generar interpretaciones. Aquí no se muestran resultados simulados.</p></div>
    </div>}

    {analysis && <>
      <div className="analysis-meta"><span>Versión {analysis.version}</span><span>·</span><span>{analysis.created_at ? shortDate(analysis.created_at) : 'Fecha no disponible'}</span><span>·</span><span>No confirmado</span></div>
      {(analysis.summary || analysis.procedural_stage) && <div className="analysis-overview">
        {analysis.summary && <article className="analysis-summary"><p className="eyebrow">SÍNTESIS DOCUMENTAL</p><p>{analysis.summary.text}</p>{analysis.summary.certainty && <span className={`certainty certainty-${analysis.summary.certainty}`}>{certaintyLabel[analysis.summary.certainty]}</span>}<CitationList citations={analysis.summary.sources ?? []} /></article>}
        {analysis.procedural_stage && <article className="analysis-stage"><p className="eyebrow">ETAPA MENCIONADA</p><strong>{analysis.procedural_stage.text}</strong>{analysis.procedural_stage.certainty && <span className={`certainty certainty-${analysis.procedural_stage.certainty}`}>{certaintyLabel[analysis.procedural_stage.certainty]}</span>}<CitationList citations={analysis.procedural_stage.sources ?? []} /></article>}
      </div>}

      <div className="analysis-groups">
        <DetailGroup title="Personas y roles" items={analysis.participants} label={(item) => item.name_as_written ?? 'Persona'} detail={(item) => [item.role_as_written, item.description].filter(Boolean).join(' · ') || null} />
        <DetailGroup title="Delitos referidos" items={analysis.offenses} label={(item) => item.label_as_written ?? 'Referencia'} detail={(item) => [item.article_as_written, item.description].filter(Boolean).join(' · ') || null} />
        <DetailGroup title="Hechos descritos" items={analysis.facts} label={(item) => item.description ?? 'Hecho'} detail={(item) => [item.kind, item.date_as_written].filter(Boolean).join(' · ') || null} />
        <DetailGroup title="Elementos probatorios" items={analysis.evidence} label={(item) => item.name_as_written ?? 'Elemento'} detail={(item) => [item.kind_as_written, item.status_as_written, item.description].filter(Boolean).join(' · ') || null} />
        <DetailGroup title="Secuencia de actuaciones" items={analysis.chronology} label={(item) => item.title ?? 'Actuación'} detail={(item) => [item.date_as_written, item.description].filter(Boolean).join(' · ') || null} />
        <DetailGroup title="Información pendiente" items={analysis.missing_information} label={(item) => item.question ?? 'Dato pendiente'} detail={(item) => item.relevance ?? null} />
        <DetailGroup title="Puntos inciertos" items={analysis.uncertainties} label={(item) => item.issue ?? 'Punto incierto'} detail={(item) => item.explanation ?? null} />
      </div>

      <div className="analysis-safety-note"><AlertTriangle size={16} aria-hidden="true" /><p>Este material organiza texto documental; no determina responsabilidad, no reemplaza la lectura del expediente ni constituye asesoría jurídica.</p></div>

      <section className="analysis-review-box" aria-label="Registrar revisión humana">
        <div><p className="eyebrow">DECISIÓN DEL REVISOR</p><h3>Registrar una revisión</h3><p>La aprobación solo se habilita mientras todas las citas coincidan con sus páginas fuente.</p></div>
        <label htmlFor={`analysis-observation-${analysis.id}`}>Observación <span>(obligatoria para solicitar cambios)</span></label>
        <textarea id={`analysis-observation-${analysis.id}`} rows={3} maxLength={2000} value={observation} onChange={(event) => setObservation(event.target.value)} placeholder="Anote qué debe corregirse o verificarse…" />
        {review.isError && <p className="analysis-error-text" role="alert">{getAuthErrorMessage(review.error)}</p>}
        {!citationsVerifiable && <p className="analysis-blocked-note">Para aprobar se necesita al menos una cita disponible y verificable. Sí puede dejar una observación para solicitar cambios.</p>}
        <div className="analysis-review-actions">
          <button className="btn btn-secondary" disabled={review.isPending || observation.trim().length === 0} onClick={() => review.mutate('requiere_cambios')}>{review.isPending ? 'Guardando…' : 'Solicitar cambios'}</button>
          <button className="btn btn-primary" disabled={review.isPending || !citationsVerifiable} onClick={() => review.mutate('aprobado')}><Check size={15} />{review.isPending ? 'Guardando…' : 'Aprobar revisión'}</button>
        </div>
      </section>

      {analysis.reviews.length > 0 && <section className="analysis-history" aria-label="Historial de revisión">
        <div className="analysis-group-heading"><h3><History size={16} />Historial de revisión</h3><span>{analysis.reviews.length}</span></div>
        {analysis.reviews.map((item, index) => <article className="analysis-history-row" key={`${item.reviewed_at}-${index}`}>
          <span className={`history-mark history-${item.decision}`} aria-hidden="true">{item.decision === 'aprobado' ? <Check size={14} /> : <AlertTriangle size={14} />}</span>
          <div><strong>{decisionLabel[item.decision]}</strong><p>{item.observation || 'Sin observación adjunta.'}</p><small>{item.reviewer_name} · {item.reviewed_at ? shortDate(item.reviewed_at) : 'Fecha no disponible'}</small></div>
        </article>)}
      </section>}
    </>}
  </section>
}
