import { Link } from 'react-router-dom'

export function Brand({ compact = false }: { compact?: boolean }) {
  return <Link to="/" className="brand" aria-label="JURISSIM, inicio"><span className="brand-symbol">J<i /></span>{!compact && <span className="brand-name">JURISSIM<span>PLATAFORMA DE PRÁCTICA PENAL</span></span>}</Link>
}
