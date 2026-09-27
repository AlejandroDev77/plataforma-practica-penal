import { useState, type FormEvent } from 'react'
import { Save, RotateCcw } from 'lucide-react'
import { toast } from 'sonner'
import { Field, PageHeading } from '../../components/ui/AdminUi'
import { useAdminStore } from '../../features/admin/model/admin-store'
import { useCurrentUser } from '../../features/auth/model/use-current-user'

export function SettingsPage() {
  const { profile, updateProfile } = useAdminStore()
  const { data: currentUser } = useCurrentUser()
  const [tab, setTab] = useState('General')
  const [notifications, setNotifications] = useState([true, true, false])
  const [timezone, setTimezone] = useState('America/La_Paz')
  const [savedTimezone, setSavedTimezone] = useState('America/La_Paz')
  const [savedNotifications, setSavedNotifications] = useState([true, true, false])
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    if (tab === 'General') { if (!String(data.get('institution') ?? '').trim()) { toast.error('Escriba el nombre de la institución.'); return }; updateProfile({ name: currentUser?.name ?? profile.name, email: currentUser?.email ?? profile.email, institution: String(data.get('institution')).trim() }); setSavedTimezone(timezone) }
    if (tab === 'Notificaciones') setSavedNotifications([...notifications])
    toast.success('Preferencias guardadas para esta demostración')
  }
  return <><PageHeading eyebrow="Administración" title="Configuración" description="Personalice su perfil y las preferencias del espacio institucional." /><div className="settings-layout"><nav className="settings-nav" aria-label="Secciones de configuración">{['General', 'Notificaciones', 'Acceso'].map((label) => <button className={tab === label ? 'selected' : ''} key={label} onClick={() => setTab(label)}>{label}</button>)}</nav><form key={tab} className="panel settings-panel" onSubmit={submit}>
    {tab === 'General' && <><div className="settings-section"><p className="eyebrow">IDENTIDAD</p><h2>Perfil de la cuenta</h2><p className="muted">Los datos de identidad se consultan desde su sesión autenticada. La edición del perfil se habilitará con su flujo propio.</p><div className="profile-preview"><span className="avatar large-avatar">{(currentUser?.name ?? profile.name).split(' ').slice(0, 2).map((word) => word[0]).join('')}</span><div><strong>{currentUser?.name ?? profile.name}</strong><small>{currentUser?.email ?? profile.email}</small></div></div><dl className="detail-list"><div><dt>Rol asignado</dt><dd>{currentUser?.roles.join(', ') || 'Sin rol asignado'}</dd></div></dl></div><div className="settings-section"><h2>Espacio de trabajo</h2><p className="muted">Estas preferencias todavía se guardan solo en esta demostración.</p><div className="form-grid"><Field label="Nombre de la institución"><input name="institution" required maxLength={120} defaultValue={profile.institution} /></Field><Field label="Zona horaria"><select value={timezone} onChange={(e) => setTimezone(e.target.value)}><option value="America/La_Paz">La Paz (UTC−04:00)</option><option value="America/Lima">Lima (UTC−05:00)</option><option value="America/Bogota">Bogotá (UTC−05:00)</option></select></Field></div></div></>}
    {tab === 'Notificaciones' && <div className="settings-section"><p className="eyebrow">PREFERENCIAS</p><h2>Notificaciones del espacio</h2><p className="muted">Configure los avisos que desea recibir. No se enviarán correos en esta versión.</p>{[{ title: 'Revisión de expedientes', text: 'Cuando un expediente necesite revisión del equipo.' }, { title: 'Agenda de audiencias', text: 'Recordatorios de sesiones y cambios de programación.' }, { title: 'Resumen de actividad', text: 'Un resumen periódico del trabajo institucional.' }].map((item, index) => <label className="toggle-row" key={item.title}><span><strong>{item.title}</strong><small>{item.text}</small></span><input type="checkbox" role="switch" checked={notifications[index]} onChange={(e) => setNotifications(notifications.map((value, i) => i === index ? e.target.checked : value))} /></label>)}</div>}
    {tab === 'Acceso' ? <div className="settings-section"><p className="eyebrow">CUENTA INSTITUCIONAL</p><h2>Acceso y seguridad</h2><p className="detail-description">La sesión se valida en Laravel mediante Sanctum. Cerrar sesión invalida la sesión del servidor y su token CSRF.</p><div className="notice">El registro público crea una cuenta básica. Los permisos administrativos solo se conceden mediante un proceso autorizado, nunca desde el formulario de registro.</div><dl className="detail-list"><div><dt>Cuenta</dt><dd>{currentUser?.email ?? 'No disponible'}</dd></div><div><dt>Roles</dt><dd>{currentUser?.roles.join(', ') || 'Sin rol asignado'}</dd></div><div><dt>Autenticación</dt><dd>Sesión protegida</dd></div></dl></div> : <div className="settings-actions"><button className="btn btn-secondary" type="reset" onClick={() => { setNotifications([...savedNotifications]); setTimezone(savedTimezone) }}><RotateCcw size={15} />Descartar cambios</button><button type="submit" className="btn btn-primary"><Save size={16} />Guardar cambios</button></div>}
  </form></div></>
}
