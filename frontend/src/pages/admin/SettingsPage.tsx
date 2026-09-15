import { useState, type FormEvent } from 'react'
import { Save, RotateCcw } from 'lucide-react'
import { toast } from 'sonner'
import { Field, PageHeading } from '../../components/ui/AdminUi'
import { useAdminStore } from '../../features/admin/model/admin-store'

export function SettingsPage() {
  const { profile, updateProfile } = useAdminStore()
  const [tab, setTab] = useState('General')
  const [notifications, setNotifications] = useState([true, true, false])
  const [timezone, setTimezone] = useState('America/La_Paz')
  const [savedTimezone, setSavedTimezone] = useState('America/La_Paz')
  const [savedNotifications, setSavedNotifications] = useState([true, true, false])
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    if (tab === 'General') { if (![data.get('name'), data.get('institution')].every((value) => String(value).trim())) { toast.error('Complete los campos sin dejarlos en blanco.'); return }; updateProfile({ name: String(data.get('name')).trim(), email: String(data.get('email')).trim(), institution: String(data.get('institution')).trim() }); setSavedTimezone(timezone) }
    if (tab === 'Notificaciones') setSavedNotifications([...notifications])
    toast.success('Preferencias guardadas para esta demostración')
  }
  return <><PageHeading eyebrow="Administración" title="Configuración" description="Personalice su perfil y las preferencias del espacio institucional." /><div className="settings-layout"><nav className="settings-nav" aria-label="Secciones de configuración">{['General', 'Notificaciones', 'Acceso'].map((label) => <button className={tab === label ? 'selected' : ''} key={label} onClick={() => setTab(label)}>{label}</button>)}</nav><form key={tab} className="panel settings-panel" onSubmit={submit}>
    {tab === 'General' && <><div className="settings-section"><p className="eyebrow">IDENTIDAD</p><h2>Perfil administrativo</h2><p className="muted">Esta información identifica sus acciones dentro del espacio.</p><div className="profile-preview"><span className="avatar large-avatar">{profile.name.split(' ').slice(0, 2).map((word) => word[0]).join('')}</span><div><strong>{profile.name}</strong><small>Administrador institucional</small></div></div><div className="form-grid"><Field label="Nombre completo"><input name="name" required maxLength={100} defaultValue={profile.name} /></Field><Field label="Correo institucional"><input type="email" name="email" required defaultValue={profile.email} /></Field></div></div><div className="settings-section"><h2>Espacio de trabajo</h2><div className="form-grid"><Field label="Nombre de la institución"><input name="institution" required maxLength={120} defaultValue={profile.institution} /></Field><Field label="Zona horaria"><select value={timezone} onChange={(e) => setTimezone(e.target.value)}><option value="America/La_Paz">La Paz (UTC−04:00)</option><option value="America/Lima">Lima (UTC−05:00)</option><option value="America/Bogota">Bogotá (UTC−05:00)</option></select></Field></div></div></>}
    {tab === 'Notificaciones' && <div className="settings-section"><p className="eyebrow">PREFERENCIAS</p><h2>Notificaciones del espacio</h2><p className="muted">Configure los avisos que desea recibir. No se enviarán correos en esta versión.</p>{[{ title: 'Revisión de expedientes', text: 'Cuando un expediente necesite revisión del equipo.' }, { title: 'Agenda de audiencias', text: 'Recordatorios de sesiones y cambios de programación.' }, { title: 'Resumen de actividad', text: 'Un resumen periódico del trabajo institucional.' }].map((item, index) => <label className="toggle-row" key={item.title}><span><strong>{item.title}</strong><small>{item.text}</small></span><input type="checkbox" role="switch" checked={notifications[index]} onChange={(e) => setNotifications(notifications.map((value, i) => i === index ? e.target.checked : value))} /></label>)}</div>}
    {tab === 'Acceso' ? <div className="settings-section"><p className="eyebrow">CUENTA INSTITUCIONAL</p><h2>Acceso y seguridad</h2><p className="detail-description">Está explorando una interfaz de demostración. El inicio de sesión, la verificación de correo y la recuperación de contraseñas requieren la conexión con el backend.</p><div className="notice">En esta versión no se almacenan credenciales ni existen sesiones autenticadas.</div><dl className="detail-list"><div><dt>Perfil visible</dt><dd>Administrador</dd></div><div><dt>Autenticación</dt><dd>Pendiente de integración</dd></div></dl></div> : <div className="settings-actions"><button className="btn btn-secondary" type="reset" onClick={() => { setNotifications([...savedNotifications]); setTimezone(savedTimezone) }}><RotateCcw size={15} />Descartar cambios</button><button type="submit" className="btn btn-primary"><Save size={16} />Guardar cambios</button></div>}
  </form></div></>
}
