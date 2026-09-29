import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { ArrowRight, CalendarDays, CircleAlert, Plus, RefreshCw } from 'lucide-react'
import { Badge } from '../../../components/ui/AdminUi'
import { getAuthErrorMessage } from '../../auth/api/auth-api'
import { useCaseAnalysis } from '../../cases/model/use-cases'
import { createSimulation } from '../api/simulations-api'
import { hearingTypesQueryKey, simulationsQueryKey, useCaseSimulations, useHearingTypes } from '../model/use-simulations'
import type { HearingType } from '../model/simulation'

function SimulationStatus({ status }: { status: string }) {
  return <Badge>{status === 'activa' ? 'En curso' : status === 'finalizada' ? 'Finalizada' : status}</Badge>
}

export function CaseSimulationsSection({ caseId }: { caseId: number }) {
  const analysis = useCaseAnalysis(caseId)
  const sessions = useCaseSimulations(caseId)
  const types = useHearingTypes()
  const [typeId, setTypeId] = useState('')
  const [error, setError] = useState('')
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const create = useMutation({
    mutationFn: () => createSimulation(caseId, analysis.data?.id ?? 0, Number(typeId)),
    onSuccess: async (simulation) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...simulationsQueryKey, 'expediente', caseId] }),
        queryClient.invalidateQueries({ queryKey: hearingTypesQueryKey }),
      ])
      navigate(`/simulaciones/${simulation.id}`)
    },
    onError: (mutationError) => setError(getAuthErrorMessage(mutationError)),
  })

  const approved = analysis.data?.review_state === 'aprobado'
  const hearingTypes = types.data ?? []

  return <section id="simulaciones" className="panel simulation-section" aria-labelledby="case-simulations-title">
    <div className="simulation-section-heading">
      <div><p className="eyebrow">PRÁCTICA PROCESAL</p><h2 id="case-simulations-title">Audiencias de este expediente</h2><p>Las intervenciones que se registran aquí forman parte de un ejercicio académico privado.</p></div>
      <span className="simulation-section-icon" aria-hidden="true"><CalendarDays size={21} /></span>
    </div>

    {analysis.isPending && <div className="analysis-state" role="status"><span className="loading-spinner" />Consultando el estado de revisión del análisis…</div>}
    {analysis.isError && <div className="simulation-inline-error" role="alert"><p>{getAuthErrorMessage(analysis.error)}</p><button className="btn btn-secondary" onClick={() => void analysis.refetch()}>Reintentar</button></div>}
    {analysis.isSuccess && !approved && <div className="simulation-readiness"><CircleAlert size={18} /><div><strong>El análisis todavía no está aprobado</strong><p>Revise el análisis del expediente y obtenga su aprobación humana antes de preparar una audiencia.</p></div></div>}

    {approved && <div className="simulation-create-row">
      <div className="simulation-create-copy"><strong>Preparar una nueva práctica</strong><p>El tipo de audiencia y las etapas disponibles se consultan desde la configuración vigente.</p></div>
      <label className="simulation-type-field"><span className="sr-only">Tipo de audiencia</span>
        <select value={typeId} onChange={(event) => { setTypeId(event.target.value); setError('') }} disabled={types.isPending || hearingTypes.length === 0}>
          <option value="">{types.isPending ? 'Cargando tipos…' : 'Seleccione un tipo de audiencia'}</option>
          {hearingTypes.map((type: HearingType) => <option value={type.id} key={type.id}>{type.name}</option>)}
        </select>
      </label>
      <button className="btn btn-primary" disabled={!typeId || create.isPending || types.isError} onClick={() => { setError(''); create.mutate() }}>
        {create.isPending ? <><RefreshCw size={15} className="spin" />Preparando…</> : <><Plus size={16} />Crear práctica</>}
      </button>
    </div>}
    {approved && types.isError && <div className="simulation-inline-error" role="alert"><p>{getAuthErrorMessage(types.error)}</p><button className="btn btn-secondary" onClick={() => void types.refetch()}>Reintentar</button></div>}
    {approved && types.isSuccess && hearingTypes.length === 0 && <div className="simulation-readiness"><CircleAlert size={18} /><div><strong>No hay tipos de audiencia disponibles</strong><p>La administración debe configurar las etapas de práctica antes de crear una sesión.</p></div></div>}
    {approved && types.isSuccess && hearingTypes.length > 0 && <p className="simulation-create-note"><CircleAlert size={14} />Las intervenciones solo se habilitan cuando la secuencia de turnos de la audiencia está activa y revisada. Si aún no existe, la sesión quedará disponible para consulta, sin poder avanzar.</p>}
    {error && <p className="form-error simulation-form-error" role="alert">{error}</p>}

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
