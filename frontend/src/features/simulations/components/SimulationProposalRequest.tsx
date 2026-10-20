import { useState } from 'react'
import { RefreshCw, Sparkles } from 'lucide-react'
import type { SimulationGenerationProvider } from '../model/simulation'

interface Props {
  provider: SimulationGenerationProvider
  pending: boolean
  onRequest: (authorizeExternal: boolean) => void
}

export function SimulationProposalRequest({ provider, pending, onRequest }: Props) {
  const [authorized, setAuthorized] = useState(false)
  if (provider.provider === 'disabled') return <p className="simulation-composer-note" role="status">La generación de borradores está desactivada. Configure un proveedor antes de solicitar una intervención.</p>

  return <div className="simulation-proposal-request">
    {provider.requires_external_authorization ? <>
      <p className="simulation-composer-note">Esta audiencia usa OpenAI por internet. Se enviarán hechos resumidos, títulos y extractos de fuentes y hasta las últimas 12 intervenciones de esta etapa; no el archivo completo. La solicitud puede consumir saldo de su API.</p>
      <label className="simulation-external-consent">
        <input type="checkbox" checked={authorized} disabled={pending} onChange={(event) => setAuthorized(event.target.checked)} />
        <span>Autorizo enviar este contexto de la práctica a OpenAI para generar un solo borrador.</span>
      </label>
    </> : <p className="simulation-composer-note">Esta audiencia usa Ollama en este equipo. JURISSIM no enviará el contexto a un proveedor externo.</p>}
    <button className="btn btn-secondary" disabled={pending || (provider.requires_external_authorization && !authorized)}
      onClick={() => {
        const authorizeExternal = provider.requires_external_authorization && authorized
        setAuthorized(false)
        onRequest(authorizeExternal)
      }}>
      {pending ? <><RefreshCw size={15} className="spin" aria-hidden="true" />Preparando…</> : <><Sparkles size={15} aria-hidden="true" />Solicitar borrador</>}
    </button>
  </div>
}
