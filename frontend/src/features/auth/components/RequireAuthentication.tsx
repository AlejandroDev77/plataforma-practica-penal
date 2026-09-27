import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { LogOut } from 'lucide-react'
import { Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { currentUserQueryKey, signOut } from '../api/auth-api'
import { useCurrentUser } from '../model/use-current-user'

const administrativeRoles = new Set(['administrador_plataforma', 'administrador_institucional'])

export function RequireAuthentication() {
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { data: user, isPending, isError, refetch } = useCurrentUser()
  const [isRetrying, setIsRetrying] = useState(false)
  const [isSigningOut, setIsSigningOut] = useState(false)
  const [signOutError, setSignOutError] = useState('')

  async function handleSignOut() {
    setIsSigningOut(true)
    setSignOutError('')
    try {
      await signOut()
      queryClient.setQueryData(currentUserQueryKey, null)
      navigate('/login', { replace: true })
    } catch {
      setSignOutError('No pudimos cerrar la sesión. Compruebe la conexión e inténtelo otra vez.')
    } finally {
      setIsSigningOut(false)
    }
  }

  if (isPending) {
    return <main className="auth-state" role="status" aria-live="polite">Verificando el acceso…</main>
  }

  if (isError) {
    return (
      <main className="auth-state">
        <section className="auth-state-panel" aria-labelledby="auth-connection-title">
          <p className="eyebrow">ACCESO A JURISSIM</p>
          <h1 id="auth-connection-title">No pudimos verificar tu sesión.</h1>
          <p>Comprueba que el servidor esté activo y vuelve a intentar. No hemos cerrado tu sesión.</p>
          <button
            className="btn btn-primary"
            type="button"
            disabled={isRetrying}
            onClick={async () => {
              setIsRetrying(true)
              try {
                await refetch()
              } finally {
                setIsRetrying(false)
              }
            }}
          >
            {isRetrying ? 'Comprobando…' : 'Intentar de nuevo'}
          </button>
        </section>
      </main>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />
  }

  if (!user.roles.some((role) => administrativeRoles.has(role))) {
    return (
      <main className="auth-state">
        <section className="auth-state-panel" aria-labelledby="auth-permission-title">
          <p className="eyebrow">ACCESO INSTITUCIONAL</p>
          <h1 id="auth-permission-title">Su cuenta aún no tiene acceso administrativo.</h1>
          <p>El registro no concede permisos elevados. Solicite a la persona administradora de su institución que habilite su acceso.</p>
          {signOutError && <p className="form-error" role="alert">{signOutError}</p>}
          <button className="btn btn-primary" type="button" disabled={isSigningOut} onClick={handleSignOut}>
            <LogOut size={16} aria-hidden="true" />
            {isSigningOut ? 'Cerrando sesión…' : 'Cerrar sesión'}
          </button>
        </section>
      </main>
    )
  }

  return <Outlet />
}
