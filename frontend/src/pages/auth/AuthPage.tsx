import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ArrowRight, ArrowLeft, Eye, EyeOff, ShieldCheck, BookOpenText, Check } from 'lucide-react'
import { Brand } from '../../layouts/AppShell'
import { Field } from '../../components/ui/AdminUi'
import { useAdminStore } from '../../features/admin/model/admin-store'

export function AuthPage({ mode }: { mode: 'login' | 'register' | 'recover' }) {
  const [visible, setVisible] = useState(false)
  const [error, setError] = useState('')
  const [sent, setSent] = useState(false)
  const navigate = useNavigate()
  const updateProfile = useAdminStore((state) => state.updateProfile)
  const register = mode === 'register'
  const recover = mode === 'recover'
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setError('')
    const form = new FormData(event.currentTarget)
    const password = String(form.get('password') ?? '')
    if (recover) { setSent(true); return }
    if (register) {
      if (!String(form.get('name')).trim() || !String(form.get('institution')).trim()) { setError('Complete su nombre e institución.'); return }
      if (password.length < 8) { setError('La contraseña debe tener al menos 8 caracteres.'); return }
      if (password !== form.get('confirm')) { setError('Las contraseñas no coinciden.'); return }
      updateProfile({ name: String(form.get('name')).trim(), email: String(form.get('email')).trim(), institution: String(form.get('institution')).trim() })
    }
    navigate('/')
  }
  return <div className="auth-layout"><aside className="auth-editorial"><Brand /><div className="auth-editorial-copy"><p className="eyebrow">EL CONOCIMIENTO SE CONSTRUYE EN LA PRÁCTICA</p><h1>El rigor del derecho.<br /><em>El valor de<br />la experiencia.</em></h1><p>Un espacio para organizar, acompañar y transformar la formación jurídica de su institución.</p><div className="auth-feature"><BookOpenText size={19} /><span>Expedientes, fuentes y práctica en un solo lugar.</span></div></div><div className="column-art" aria-hidden="true"><span /><span /><span /><span /><span /><span /><span /></div><div className="auth-editorial-footer"><span>PRAXIS PENAL</span><span>Formación jurídica aplicada</span></div></aside><main className="auth-main"><div className="auth-top"><span>ESPACIO INSTITUCIONAL</span><Link to="/">Explorar demostración <ArrowUpRightIcon /></Link></div><div className="auth-form-wrap">{recover && <Link to="/login" className="back-link"><ArrowLeft size={15} />Volver a iniciar sesión</Link>}<span className="auth-kicker"><span />{register ? 'NUEVO ESPACIO DE TRABAJO' : recover ? 'RECUPERACIÓN DE ACCESO' : 'BIENVENIDO A PRAXIS'}</span><h2>{register ? 'Comience una nueva etapa.' : recover ? 'Recupere su acceso.' : 'Es un gusto tenerle de vuelta.'}</h2><p className="auth-intro">{register ? 'Complete sus datos para conocer la experiencia administrativa.' : recover ? 'Indique el correo asociado a su cuenta institucional.' : 'Acceda al espacio de administración de su institución.'}</p><div className="auth-demo-note">Vista de demostración · Use datos ficticios. No se crean cuentas ni se guardan contraseñas.</div>
      {sent ? <div className="recovery-success" role="status"><span><Check size={24} /></span><h3>Solicitud de ejemplo completada</h3><p>Así se confirmará una recuperación. En esta demostración no se envían correos.</p><Link className="btn btn-primary" to="/login">Volver al inicio de sesión</Link></div> : <form className="auth-form" onSubmit={submit}>
      {register && <div className="form-grid"><Field label="Nombre completo"><input autoComplete="name" required name="name" placeholder="Su nombre y apellido" maxLength={100} /></Field><Field label="Institución"><input autoComplete="organization" required name="institution" placeholder="Nombre de su institución" maxLength={120} /></Field></div>}
      <Field label="Correo institucional"><input type="email" required name="email" autoComplete="email" placeholder="nombre@institucion.edu" /></Field>
      {!recover && <><Field label="Contraseña"><div className="password-field"><input type={visible ? 'text' : 'password'} required name="password" autoComplete={register ? 'new-password' : 'current-password'} minLength={register ? 8 : 1} placeholder={register ? 'Al menos 8 caracteres' : 'Ingrese su contraseña'} /><button type="button" aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'} onClick={() => setVisible(!visible)}>{visible ? <EyeOff size={18} /> : <Eye size={18} />}</button></div></Field>{register && <Field label="Confirmar contraseña"><input required type={visible ? 'text' : 'password'} name="confirm" autoComplete="new-password" placeholder="Repita su contraseña" /></Field>}{!register && <div className="auth-options"><span><ShieldCheck size={14} />Acceso institucional</span><Link to="/recuperar-acceso">¿Olvidó su contraseña?</Link></div>}</>}
      {error && <p className="form-error" role="alert">{error}</p>}<button type="submit" className="btn btn-primary auth-submit">{recover ? 'Simular recuperación' : register ? 'Crear perfil de demostración' : 'Iniciar sesión de demostración'}<ArrowRight size={17} /></button>
      </form>}
      {!recover && <p className="auth-switch">{register ? '¿Ya tiene una cuenta?' : '¿Su institución aún no está registrada?'} <Link to={register ? '/login' : '/registro'}>{register ? 'Iniciar sesión' : 'Crear cuenta'}</Link></p>}
    </div><footer className="auth-footer"><span>© 2026 Praxis Penal</span><span><ShieldCheck size={14} />Entorno académico</span></footer></main></div>
}
function ArrowUpRightIcon() { return <ArrowRight size={14} /> }
