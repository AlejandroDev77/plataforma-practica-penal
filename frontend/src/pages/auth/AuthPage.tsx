import { useEffect, useState, type FormEvent } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, BookOpenText, Check, Eye, EyeOff, ShieldCheck } from 'lucide-react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Field } from '../../components/ui/AdminUi'
import {
  currentUserQueryKey,
  getAuthErrorMessage,
  registerAccount,
  requestPasswordReset,
  resetPassword,
  signIn,
} from '../../features/auth/api/auth-api'
import type { AuthenticatedUser } from '../../features/auth/model/auth-types'
import { useCurrentUser } from '../../features/auth/model/use-current-user'
import { Brand } from '../../layouts/AppShell'

type AuthMode = 'login' | 'register' | 'recover' | 'reset'

interface NavigationState {
  from?: { pathname?: string }
}

const copy = {
  login: {
    kicker: 'ACCESO A JURISSIM',
    title: 'Continúe su práctica jurídica.',
    introduction: 'Ingrese a su espacio de trabajo con su correo y contraseña.',
    action: 'Iniciar sesión',
  },
  register: {
    kicker: 'CREAR CUENTA',
    title: 'Empiece a prepararse.',
    introduction: 'Cree una cuenta para acceder a JURISSIM. Los permisos institucionales se asignan por separado.',
    action: 'Crear cuenta',
  },
  recover: {
    kicker: 'RECUPERAR ACCESO',
    title: 'Recupere su cuenta.',
    introduction: 'Le enviaremos instrucciones al correo asociado a su cuenta, si existe.',
    action: 'Enviar instrucciones',
  },
  reset: {
    kicker: 'ACTUALIZAR CONTRASEÑA',
    title: 'Elija una contraseña nueva.',
    introduction: 'Use al menos 12 caracteres. Este enlace solo puede utilizarse una vez.',
    action: 'Guardar contraseña',
  },
} satisfies Record<AuthMode, { kicker: string; title: string; introduction: string; action: string }>

export function AuthPage({ mode }: { mode: AuthMode }) {
  const [visible, setVisible] = useState(false)
  const [error, setError] = useState('')
  const [sent, setSent] = useState(false)
  const [busy, setBusy] = useState(false)
  const navigate = useNavigate()
  const location = useLocation()
  const params = useParams()
  const [searchParams] = useSearchParams()
  const queryClient = useQueryClient()
  const { data: currentUser } = useCurrentUser()
  const register = mode === 'register'
  const recover = mode === 'recover'
  const reset = mode === 'reset'
  const pageCopy = copy[mode]
  const resetEmail = searchParams.get('email') ?? ''
  const resetToken = params.token ?? ''

  useEffect(() => {
    if (currentUser && (mode === 'login' || mode === 'register')) {
      navigate('/', { replace: true })
    }
  }, [currentUser, mode, navigate])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setBusy(true)

    const form = new FormData(event.currentTarget)
    const email = String(form.get('email') ?? '').trim().toLowerCase()
    const password = String(form.get('password') ?? '')

    try {
      if (recover) {
        await requestPasswordReset(email)
        setSent(true)
        return
      }

      if (reset) {
        if (!resetToken || !resetEmail) {
          setError('El enlace no está completo. Solicite uno nuevo para continuar.')
          return
        }

        await resetPassword({
          token: resetToken,
          email: resetEmail,
          password,
          password_confirmation: String(form.get('password_confirmation') ?? ''),
        })
        setSent(true)
        return
      }

      if (register) {
        await registerAccount({
          name: String(form.get('name') ?? '').trim(),
          email,
          password,
          password_confirmation: String(form.get('password_confirmation') ?? ''),
        })
        setSent(true)
        return
      }

      const user: AuthenticatedUser = await signIn({ email, password })
      queryClient.setQueryData(currentUserQueryKey, user)
      const destination = (location.state as NavigationState | null)?.from?.pathname
      const returnPath = destination?.startsWith('/') && !destination.startsWith('//') ? destination : '/'
      navigate(returnPath, { replace: true })
    } catch (submitError) {
      setError(getAuthErrorMessage(submitError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="auth-layout">
      <aside className="auth-editorial" aria-label="JURISSIM, formación jurídica">
        <Brand />
        <div className="auth-editorial-copy">
          <p className="eyebrow">FORMACIÓN JURÍDICA APLICADA</p>
          <h1>El rigor del derecho.<br /><em>El valor de<br />la experiencia.</em></h1>
          <p>Prepare expedientes, contraste fuentes y practique audiencias en un entorno de aprendizaje.</p>
          <div className="auth-feature"><BookOpenText size={19} aria-hidden="true" /><span>Estudio y práctica jurídica en un mismo espacio.</span></div>
        </div>
        <div className="column-art" aria-hidden="true"><span /><span /><span /><span /><span /><span /><span /></div>
        <div className="auth-editorial-footer"><span>JURISSIM</span><span>Práctica penal boliviana</span></div>
      </aside>

      <main className="auth-main">
        <div className="auth-top">
          {recover || reset ? (
            <Link to="/login" className="back-link"><ArrowLeft size={15} aria-hidden="true" />Volver al inicio de sesión</Link>
          ) : (
            <span>ACCESO INSTITUCIONAL</span>
          )}
          <span className="auth-secure-label"><ShieldCheck size={15} aria-hidden="true" />Sesión protegida</span>
        </div>

        <section className="auth-form-wrap" aria-labelledby="auth-title">
          <span className="auth-kicker"><span aria-hidden="true" />{pageCopy.kicker}</span>
          <h2 id="auth-title">{pageCopy.title}</h2>
          <p className="auth-intro">{pageCopy.introduction}</p>

          {sent ? (
            <div className="recovery-success" role="status" aria-live="polite">
              <span><Check size={24} aria-hidden="true" /></span>
              <h3>{register ? 'Cuenta creada' : reset ? 'Contraseña actualizada' : 'Revise su correo'}</h3>
              <p>{register
                ? 'La cuenta se creó con acceso básico. Solicite a la persona administradora de su institución que habilite el acceso administrativo.'
                : reset
                  ? 'Ya puede ingresar con su nueva contraseña.'
                  : 'Si el correo está asociado a una cuenta, recibirá instrucciones para recuperar el acceso.'}</p>
              <Link className="btn btn-primary" to="/login">{register ? 'Iniciar sesión' : 'Ir al inicio de sesión'}</Link>
            </div>
          ) : (
            <form className="auth-form" onSubmit={submit} aria-busy={busy}>
              {register && (
                <Field label="Nombre completo">
                  <input autoComplete="name" required name="name" placeholder="Nombre y apellido" maxLength={120} minLength={2} />
                </Field>
              )}

              <Field label="Correo electrónico">
                <input
                  type="email"
                  required
                  name="email"
                  autoComplete="email"
                  autoCapitalize="none"
                  spellCheck={false}
                  placeholder="nombre@institucion.edu"
                  defaultValue={reset ? resetEmail : undefined}
                  readOnly={reset}
                />
              </Field>

              {!recover && (
                <>
                  <Field label={reset ? 'Nueva contraseña' : 'Contraseña'} hint="12 caracteres como mínimo.">
                    <div className="password-field">
                      <input
                        type={visible ? 'text' : 'password'}
                        required
                        name="password"
                        autoComplete={register || reset ? 'new-password' : 'current-password'}
                        minLength={register || reset ? 12 : undefined}
                        placeholder={register || reset ? 'Al menos 12 caracteres' : 'Ingrese su contraseña'}
                      />
                      <button
                        type="button"
                        aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                        aria-pressed={visible}
                        onClick={() => setVisible((value) => !value)}
                      >
                        {visible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
                      </button>
                    </div>
                  </Field>

                  {(register || reset) && (
                    <Field label="Confirmar contraseña">
                      <input
                        type={visible ? 'text' : 'password'}
                        required
                        name="password_confirmation"
                        autoComplete="new-password"
                        minLength={12}
                        placeholder="Repita su contraseña"
                      />
                    </Field>
                  )}
                </>
              )}

              {error && <p className="form-error" role="alert" id="auth-error">{error}</p>}

              <button type="submit" className="btn btn-primary auth-submit" disabled={busy}>
                <span>{busy ? 'Un momento…' : pageCopy.action}</span>
                {busy ? <span className="auth-progress" aria-hidden="true" /> : <ArrowRight size={17} aria-hidden="true" />}
              </button>
            </form>
          )}

          {!sent && mode === 'login' && (
            <div className="auth-links">
              <Link to="/recuperar-acceso">¿Olvidó su contraseña?</Link>
              <p>¿Aún no tiene una cuenta? <Link to="/registro">Crear cuenta</Link></p>
            </div>
          )}
          {!sent && mode === 'register' && (
            <p className="auth-switch">¿Ya tiene una cuenta? <Link to="/login">Iniciar sesión</Link></p>
          )}
          {!sent && reset && (
            <p className="auth-switch">¿El enlace venció? <Link to="/recuperar-acceso">Solicitar otro</Link></p>
          )}
        </section>

        <footer className="auth-footer">
          <span>© 2026 JURISSIM</span>
          <span><ShieldCheck size={14} aria-hidden="true" />Entorno de formación</span>
        </footer>
      </main>
    </div>
  )
}
