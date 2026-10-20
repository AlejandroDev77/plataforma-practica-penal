import { Link } from 'react-router-dom'
import { ArrowRight, CalendarDays, CircleAlert } from 'lucide-react'
import { Badge } from '../../../components/ui/AdminUi'
import { getAuthErrorMessage } from '../../auth/api/auth-api'
import { useCaseAnalysis } from '../../cases/model/use-cases'
import '../simulations.css'
import { useCaseSimulations } from '../model/use-simulations'
import { CrearPracticaReglada } from '../../practicas/components/CrearPracticaReglada'

function SimulationStatus({ status }: { status: string }) {
  return <Badge>{status === 'activa' ? 'En curso' : status === 'finalizada' ? 'Finalizada' : status}</Badge>
}

export function CaseSimulationsSection({ caseId }: { caseId: number }) {
  const analysis = useCaseAnalysis(caseId)
  const sessions = useCaseSimulations(caseId)
  const approved = analysis.data?.review_state === 'aprobado'

  return <section id="simulaciones" className="panel simulation-section" aria-labelledby="case-simulations-title">
    <div className="simulation-section-heading">
      <div><p className="eyebrow">PRÁCTICA PROCESAL</p><h2 id="case-simulations-title">Audiencias de este expediente</h2><p>Las intervenciones que se registran aquí forman parte de un ejercicio académico privado.</p></div>
      <span className="simulation-section-icon" aria-hidden="true"><CalendarDays size={21} /></span>
    </div>

    {analysis.isPending && <div className="analysis-state" role="status"><span className="loading-spinner" />Consultando el estado de revisión del análisis…</div>}
    {analysis.isError && <div className="simulation-inline-error" role="alert"><p>{getAuthErrorMessage(analysis.error)}</p><button className="btn btn-secondary" onClick={() => void analysis.refetch()}>Reintentar</button></div>}
    {analysis.isSuccess && !approved && <div className="simulation-readiness"><CircleAlert size={18} /><div><strong>El análisis todavía no está aprobado</strong><p>Revise el análisis del expediente y obtenga su aprobación humana antes de preparar una audiencia.</p></div></div>}

    {approved && analysis.data && <CrearPracticaReglada caseId={caseId} analysisId={analysis.data.id} />}

    {sessions.isPending && <div className="analysis-state" role="status"><span className="loading-spinner" />Cargando prácticas anteriores…</div>}
    {sessions.isError && <div className="simulation-inline-error" role="alert"><p>{getAuthErrorMessage(sessions.error)}</p><button className="btn btn-secondary" onClick={() => void sessions.refetch()}>Reintentar</button></div>}
    {sessions.isSuccess && sessions.data.data.length > 0 && <div className="simulation-session-list">
      {sessions.data.data.map((simulation) => <Link className="simulation-session-row" to={`/simulaciones/${simulation.id}`} key={simulation.id}>
        <div className="simulation-session-mark" aria-hidden="true"><CalendarDays size={17} /></div>
        <div className="simulation-session-copy"><strong>{simulation.hearing_type?.name ?? 'Práctica de audiencia'}</strong><span>{simulation.current_stage?.name ?? 'Sin etapa activa'} · {simulation.current_turn?.configured ? simulation.current_turn.complete ? 'Etapa lista para avanzar' : `Turno: ${simulation.current_turn.role ?? 'pendiente'}` : 'Turnos por configurar'}</span></div>
        <SimulationStatus status={simulation.status} /><ArrowRight size={16} aria-hidden="true" />
      </Link>)}
    </div>}
    {sessions.isSuccess && sessions.data.data.length === 0 && <p className="simulation-empty">Aún no hay prácticas de audiencia asociadas a este expediente.</p>}
  </section>
}
