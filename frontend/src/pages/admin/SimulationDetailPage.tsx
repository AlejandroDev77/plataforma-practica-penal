import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { AlertTriangle, ArrowLeft, ArrowRight, Check, Clock3, FileText, RefreshCw, Send, Sparkles } from 'lucide-react'
import { PageHeading } from '../../components/ui/AdminUi'
import { getAuthErrorMessage } from '../../features/auth/api/auth-api'
import { SimulationEvaluationPanel } from '../../features/simulations/components/SimulationEvaluationPanel'
import { addIntervention, advanceSimulation, requestInterventionProposal } from '../../features/simulations/api/simulations-api'
import { simulationsQueryKey, useSimulation } from '../../features/simulations/model/use-simulations'
import type { ParticipantRole, SimulationIntervention } from '../../features/simulations/model/simulation'
import '../../features/simulations/simulations.css'

const roleLabel: Record<string, string> = {
  abogado_defensor: 'Defensa',
  juez: 'Juzgado',
  fiscal: 'Fiscalía',
}

function labelRole(role: ParticipantRole | null | undefined): string {
  return role ? roleLabel[role] ?? role.replaceAll('_', ' ') : 'Participante sin identificar'
}

function formatMoment(value: string | null): string {
  if (!value) return 'Hora no disponible'
  const date = new Date(value)
  return Number.isNaN(date.valueOf()) ? 'Hora no disponible' : new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function InterventionCard({ intervention, currentStageId }: { intervention: SimulationIntervention; currentStageId?: number }) {
  const isUser = intervention.participant?.role === 'abogado_defensor'
  return <article className={`simulation-message${isUser ? ' is-user' : ''}`}>
    <div className="simulation-message-heading">
      <span className="simulation-avatar" aria-hidden="true">{labelRole(intervention.participant?.role).slice(0, 1)}</span>
      <div><strong>{intervention.participant?.display_name ?? labelRole(intervention.participant?.role)}</strong><span>{labelRole(intervention.participant?.role)}{currentStageId && intervention.stage_id !== currentStageId ? ' · etapa anterior' : ''}</span></div>
      <time dateTime={intervention.created_at ?? undefined}>{formatMoment(intervention.created_at)}</time>
    </div>
    <p className="simulation-message-content">{intervention.content}</p>
    {intervention.sources.length > 0 && <details className="simulation-citations">
      <summary><FileText size={14} />{intervention.sources.length} {intervention.sources.length === 1 ? 'referencia' : 'referencias'}</summary>
      <ul>{intervention.sources.map((citation) => <li key={citation.id}>
        <strong>{citation.source.title ?? citation.source.identifier ?? 'Fuente consultada'}</strong>
        {citation.source.locator && <span>{citation.source.locator}</span>}
        {citation.excerpt && <blockquote>{citation.excerpt}</blockquote>}
      </li>)}</ul>
    </details>}
  </article>
}

export function SimulationDetailPage() {
  const rawId = useParams().id
  const id = Number(rawId)
  const queryClient = useQueryClient()
  const simulationQuery = useSimulation(id)
  const [content, setContent] = useState('')
  const [nextStageId, setNextStageId] = useState('')
  const simulation = simulationQuery.data
  const turn = simulation?.current_turn
  const interventions = simulation?.interventions ?? []
  const allowedToSpeak = simulation?.status === 'activa' && turn?.configured && turn.role === simulation.user_role && turn.allowed_actions.includes('submit_text_intervention')
  const canAskForProposal = simulation?.status === 'activa' && turn?.configured && (turn.role === 'juez' || turn.role === 'fiscal') && !turn.complete
  const nextStages = simulation?.current_stage?.allowed_next_stages ?? []
  const canAdvance = simulation?.status === 'activa' && turn?.configured && turn.complete && nextStages.length > 0

  const proposal = useMutation({ mutationFn: () => requestInterventionProposal(id) })

  const saveTurn = useMutation({
    mutationFn: () => addIntervention(id, content.trim()),
    onSuccess: async () => {
      setContent('')
      proposal.reset()
      await queryClient.invalidateQueries({ queryKey: [...simulationsQueryKey, id] })
      await queryClient.invalidateQueries({ queryKey: [...simulationsQueryKey, 'expediente', simulation?.case_id] })
    },
  })

  const advance = useMutation({
    mutationFn: () => advanceSimulation(id, nextStageId ? Number(nextStageId) : undefined),
    onSuccess: async () => {
      setNextStageId('')
      proposal.reset()
      await queryClient.invalidateQueries({ queryKey: [...simulationsQueryKey, id] })
      await queryClient.invalidateQueries({ queryKey: [...simulationsQueryKey, 'expediente', simulation?.case_id] })
    },
  })

  function submitIntervention(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    saveTurn.mutate()
  }

  if (!Number.isSafeInteger(id) || id < 1) return <div className="empty-state"><FileText size={30} /><h1>Práctica no disponible</h1><p>La referencia de la sesión no es válida.</p><Link className="btn btn-secondary" to="/audiencias"><ArrowLeft size={16} />Volver a audiencias</Link></div>
  if (simulationQuery.isPending) return <div className="empty-state" role="status"><span className="loading-spinner" />Cargando práctica…</div>
  if (simulationQuery.isError || !simulation) return <div className="empty-state"><FileText size={30} /><h1>Práctica no disponible</h1><p>{simulationQuery.isError ? getAuthErrorMessage(simulationQuery.error) : 'No encontramos esta práctica en su cuenta.'}</p><Link className="btn btn-secondary" to="/audiencias"><ArrowLeft size={16} />Volver a audiencias</Link></div>

  const statusLabel = simulation.status === 'activa' ? 'En curso' : simulation.status === 'finalizada' ? 'Finalizada' : simulation.status

  return <>
    <Link className="back-link" to={`/expedientes/${simulation.case_id}#simulaciones`}><ArrowLeft size={16} />Volver al expediente</Link>
    <PageHeading eyebrow={`PRÁCTICA · ${simulation.hearing_type?.code ?? `AUD-${simulation.id}`}`} title={simulation.hearing_type?.name ?? 'Audiencia'} description="Ejercicio académico privado · El registro no representa una actuación judicial real.">
      <span className={`simulation-status-pill${simulation.status === 'activa' ? ' is-active' : ''}`}><span />{statusLabel}</span>
    </PageHeading>

    <section className="simulation-brief panel" aria-label="Estado de la audiencia">
      <div><p className="eyebrow">ETAPA ACTUAL</p><strong>{simulation.current_stage?.name ?? 'Sin etapa activa'}</strong></div>
      <div><p className="eyebrow">SU ROL</p><strong>{labelRole(simulation.user_role)}</strong></div>
      <div><p className="eyebrow">TURNO</p><strong>{turn?.configured ? turn.complete ? 'Etapa completada' : labelRole(turn.role) : 'Sin configurar'}</strong></div>
      <div><p className="eyebrow">INICIADA</p><strong>{formatMoment(simulation.started_at ?? simulation.created_at)}</strong></div>
    </section>

    {simulation.status === 'activa' && turn && !turn.configured && <div className="simulation-readiness simulation-turn-warning" role="status"><AlertTriangle size={19} /><div><strong>Los turnos de esta audiencia aún no están configurados</strong><p>La estructura de etapas existe, pero no hay una secuencia revisada que habilite intervenciones. No se generará contenido ni se avanzará la práctica hasta que se configure.</p></div></div>}
    {simulation.status === 'activa' && turn?.configured && turn.role === 'abogado_defensor' && !allowedToSpeak && <div className="simulation-readiness" role="status"><Clock3 size={18} /><div><strong>Su intervención no está habilitada en este momento</strong><p>La sesión solo acepta texto cuando el servidor autoriza expresamente el turno de la defensa.</p></div></div>}

    <div className="simulation-workspace">
      <section className="panel simulation-transcript" aria-labelledby="transcript-title">
        <div className="simulation-panel-heading"><div><p className="eyebrow">ACTA DE PRÁCTICA</p><h2 id="transcript-title">Transcripción</h2></div><span>{interventions.length} {interventions.length === 1 ? 'intervención' : 'intervenciones'}</span></div>
        {interventions.length > 0 ? <div className="simulation-message-list">{interventions.map((item) => <InterventionCard key={item.id} intervention={item} currentStageId={simulation.current_stage?.id} />)}</div> : <div className="empty-state simulation-transcript-empty"><FileText size={28} /><h3>Aún no hay intervenciones</h3><p>El acta se completará únicamente con intervenciones registradas por el servidor durante turnos habilitados.</p></div>}
      </section>

      <aside className="simulation-side-panel">
        <section className="panel simulation-participants" aria-labelledby="participants-title">
          <div className="simulation-panel-heading"><div><p className="eyebrow">MESA DE AUDIENCIA</p><h2 id="participants-title">Participantes</h2></div></div>
          {simulation.participants?.length ? <ul>{simulation.participants.map((participant) => <li key={participant.id}><span className="simulation-avatar" aria-hidden="true">{labelRole(participant.role).slice(0, 1)}</span><div><strong>{participant.display_name}</strong><span>{labelRole(participant.role)}{participant.controlled_by === 'ia' ? ' · apoyo de generación' : ''}</span></div><i className={participant.status === 'activo' ? 'is-present' : ''} aria-label={participant.status === 'activo' ? 'Activo' : 'Inactivo'} /></li>)}</ul> : <p className="simulation-empty">No hay participantes cargados.</p>}
        </section>

        {allowedToSpeak && <section className="panel simulation-composer-panel" aria-labelledby="composer-title">
          <div className="simulation-panel-heading"><div><p className="eyebrow">SU TURNO</p><h2 id="composer-title">Intervención</h2></div></div>
          <form className="simulation-composer" onSubmit={submitIntervention}>
            <label htmlFor="simulation-intervention">Escriba su intervención</label>
            <textarea id="simulation-intervention" value={content} onChange={(event) => setContent(event.target.value)} maxLength={20000} rows={7} placeholder="Formule su intervención con base en el expediente y las referencias verificables…" required />
            <div className="simulation-composer-footer"><span>{content.length.toLocaleString('es-BO')} / 20.000</span><button className="btn btn-primary" type="submit" disabled={!content.trim() || saveTurn.isPending}>{saveTurn.isPending ? <><RefreshCw size={15} className="spin" />Registrando…</> : <><Send size={15} />Registrar intervención</>}</button></div>
            {saveTurn.isError && <p className="form-error" role="alert">{getAuthErrorMessage(saveTurn.error)}</p>}
            <p className="simulation-composer-note">Solo el servidor puede confirmar el turno y registrar esta intervención en el acta.</p>
          </form>
        </section>}

        {canAskForProposal && <section className="panel simulation-assist-panel" aria-labelledby="assist-title">
          <div className="simulation-panel-heading"><div><p className="eyebrow">APOYO PARA REVISIÓN</p><h2 id="assist-title">Propuesta de intervención</h2></div><Sparkles size={18} aria-hidden="true" /></div>
          <p>Solicite un borrador para revisión humana basado en el contexto autorizado de esta etapa.</p>
          <button className="btn btn-secondary" disabled={proposal.isPending} onClick={() => proposal.mutate()}>{proposal.isPending ? <><RefreshCw size={15} className="spin" />Preparando…</> : <><Sparkles size={15} />Solicitar borrador</>}</button>
          {proposal.isError && <div className="simulation-inline-error" role="alert"><p>{getAuthErrorMessage(proposal.error)}</p><button className="text-link" onClick={() => proposal.reset()}>Cerrar</button></div>}
          {proposal.data && <div className="simulation-draft" aria-live="polite"><p className="simulation-draft-label"><AlertTriangle size={15} />BORRADOR TEMPORAL · NO INCORPORADO AL ACTA</p><p>{proposal.data.proposal.content}</p>{proposal.data.sources.length > 0 && <details><summary>Ver fuentes del borrador ({proposal.data.sources.length})</summary><ul>{proposal.data.sources.map((source) => <li key={source.id}><strong>{source.title ?? 'Fuente consultada'}</strong>{source.locator && <span>{source.locator}</span>}{source.excerpt && <blockquote>{source.excerpt}</blockquote>}</li>)}</ul></details>}<p className="simulation-draft-note">Revise el contenido y las fuentes. Este texto no se guarda ni puede avanzar la etapa.</p></div>}
        </section>}
      </aside>
    </div>

    {canAdvance && <section className="panel simulation-advance-panel">
      <div><p className="eyebrow">SIGUIENTE ETAPA</p><strong>Los turnos configurados de esta etapa se completaron.</strong><p>Seleccione una transición disponible para continuar.</p></div>
      <label><span className="sr-only">Etapa de destino</span><select value={nextStageId} onChange={(event) => setNextStageId(event.target.value)}><option value="">{nextStages.length === 1 ? 'Continuar a la siguiente etapa' : 'Seleccione una etapa'}</option>{nextStages.map((stage) => <option key={stage.id} value={stage.id}>{stage.name}</option>)}</select></label>
      <button className="btn btn-primary" disabled={advance.isPending || (nextStages.length > 1 && !nextStageId)} onClick={() => advance.mutate()}>{advance.isPending ? 'Avanzando…' : <>Avanzar etapa <ArrowRight size={15} /></>}</button>
      {advance.isError && <p className="form-error" role="alert">{getAuthErrorMessage(advance.error)}</p>}
    </section>}

    {simulation.status === 'finalizada' && <>
      <div className="simulation-readiness simulation-finished" role="status"><Check size={18} /><div><strong>Práctica finalizada</strong><p>Esta sesión permanece disponible para consulta y no admite nuevas intervenciones.</p></div></div>
      <SimulationEvaluationPanel simulationId={id} />
    </>}
  </>
}
