import { useState } from 'react'
import { Link, Outlet, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { LogOut } from 'lucide-react'
import { Brand } from './Brand'
import { signOut } from '../features/auth/api/auth-api'
import { useCurrentUser } from '../features/auth/model/use-current-user'
import '../pages/admin/reglas-audiencia.css'

export function RevisionReglasLayout() {
  const { data: usuario } = useCurrentUser()
  const [ocupado, setOcupado] = useState(false)
  const [error, setError] = useState('')
  const client = useQueryClient()
  const navigate = useNavigate()
  async function salir() {
    setOcupado(true); setError('')
    try { await signOut(); client.clear(); navigate('/login', { replace: true }) }
    catch { setError('No pudimos cerrar la sesión. Compruebe la conexión y vuelva a intentarlo.') }
    finally { setOcupado(false) }
  }
  return <div className="reglas-review-layout"><header className="topbar"><Brand to="/revision-reglas" /><div className="reglas-account"><span>{usuario?.name}</span>{usuario?.roles.includes('administrador_plataforma') && <Link className="btn btn-secondary" to="/configuracion/reglas">Administración</Link>}<button type="button" className="btn btn-secondary" disabled={ocupado} onClick={salir}><LogOut size={16} aria-hidden="true" />{ocupado ? 'Saliendo…' : 'Cerrar sesión'}</button></div></header>{error && <p role="alert" className="reglas-error">{error}</p>}<main className="page-content"><Outlet /></main></div>
}
