import { useState } from 'react'
import { NavLink, Outlet, Link, useLocation, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { LayoutDashboard, BriefcaseBusiness, Users, FileText, BookOpenText, CalendarDays, ShieldCheck, Settings2, ScrollText, Menu, Bell, Search, ChevronDown, LogOut, ArrowUpRight, ListOrdered } from 'lucide-react'
import { useAdminStore } from '../features/admin/model/admin-store'
import { currentUserQueryKey, signOut } from '../features/auth/api/auth-api'
import { useCurrentUser } from '../features/auth/model/use-current-user'
import { useCases } from '../features/cases/model/use-cases'
import { Modal } from '../components/ui/AdminUi'
import { Brand } from './Brand'

const groups = [
  { label: 'ESPACIO DE TRABAJO', items: [{ title: 'Vista general', path: '/', icon: LayoutDashboard }, { title: 'Expedientes', path: '/expedientes', icon: BriefcaseBusiness }, { title: 'Audiencias', path: '/audiencias', icon: CalendarDays }] },
  { label: 'RECURSOS ACADÉMICOS', items: [{ title: 'Documentos', path: '/documentos', icon: FileText }, { title: 'Biblioteca jurídica', path: '/biblioteca', icon: BookOpenText }] },
  { label: 'ADMINISTRACIÓN', items: [{ title: 'Usuarios', path: '/usuarios', icon: Users }, { title: 'Roles y permisos', path: '/roles', icon: ShieldCheck }, { title: 'Registro de actividad', path: '/actividad', icon: ScrollText }, { title: 'Configuración', path: '/configuracion', icon: Settings2 }] },
]
export function AppShell() {
  const [popover, setPopover] = useState<'navigation' | 'notifications' | 'account' | null>(null)
  const [searchOpen, setSearchOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [loggingOut, setLoggingOut] = useState(false)
  const [logoutError, setLogoutError] = useState('')
  const { records, profile, activity, notificationsRead, readNotifications } = useAdminStore()
  const { data: user } = useCurrentUser()
  const navigationGroups = groups.map((group) => group.label === 'ADMINISTRACIÓN' && user?.roles.includes('administrador_plataforma')
    ? { ...group, items: [...group.items, { title: 'Propuestas de turnos', path: '/configuracion/turnos', icon: ListOrdered }] }
    : group)
  const caseCountQuery = useCases('', 1)
  const caseQuery = useCases(search.trim(), 1, searchOpen)
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const title = navigationGroups.flatMap((group) => group.items).find((item) => item.path === location.pathname)?.title ?? (location.pathname.startsWith('/configuracion/turnos') ? 'Propuestas de turnos' : 'Detalle de expediente')
  const displayName = user?.name ?? profile.name
  const initials = displayName.split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase()
  const caseResults = (caseQuery.data?.data ?? []).map((item) => ({
    id: `expediente-${item.id}`,
    title: item.title,
    detail: item.case_number || `EXP-${String(item.id).padStart(5, '0')}`,
    section: 'expedientes',
    path: `/expedientes/${item.id}`,
  }))
  const sampleResults = search.trim() ? Object.entries(records)
    .filter(([section]) => section !== 'expedientes')
    .flatMap(([section, items]) => items
      .filter((item) => `${item.title} ${item.id} ${item.subtitle}`.toLowerCase().includes(search.toLowerCase()))
      .map((item) => ({ id: item.id, title: item.title, detail: item.subtitle || item.id, section, path: `/${section}` }))) : []
  const results = [...caseResults, ...sampleResults].slice(0, 8)
  async function handleLogout() {
    setLoggingOut(true)
    setLogoutError('')
    try {
      await signOut()
      queryClient.setQueryData(currentUserQueryKey, null)
      navigate('/login', { replace: true })
    } catch {
      setLogoutError('No pudimos cerrar la sesión. Compruebe la conexión e inténtelo otra vez.')
    } finally {
      setLoggingOut(false)
    }
  }
  return <div className="admin-app">
    <div className="app-body"><header className="topbar"><div className="topbar-left"><Brand /><div className="popover-host"><button className="section-button" aria-label="Abrir secciones" aria-expanded={popover === 'navigation'} onClick={() => setPopover(popover === 'navigation' ? null : 'navigation')}><Menu size={17} /><span>Secciones</span></button>{popover === 'navigation' && <nav className="top-popover navigation-popover" aria-label="Navegación administrativa">{navigationGroups.map((group) => <div className="navigation-group" key={group.label}><p>{group.label}</p>{group.items.map(({ title: label, path, icon: Icon }) => <NavLink key={path} end={path === '/'} to={path} onClick={() => setPopover(null)} className={({ isActive }) => `navigation-link ${isActive ? 'active' : ''}`}><Icon size={16} /><span>{label}</span>{path === '/expedientes' && <b>{caseCountQuery.data?.meta.total ?? 0}</b>}</NavLink>)}</div>)}</nav>}</div><div className="breadcrumb"><span>Administración</span><i>/</i><strong>{title}</strong></div></div><div className="topbar-actions"><button className="global-search" onClick={() => setSearchOpen(true)}><Search size={17} /><span>Buscar en el espacio</span></button><span className="topbar-divider" /><div className="popover-host"><button className="icon-button notification-button" aria-label="Notificaciones" aria-expanded={popover === 'notifications'} onClick={() => { setPopover(popover === 'notifications' ? null : 'notifications'); readNotifications() }}><Bell size={19} />{!notificationsRead && <i />}</button>{popover === 'notifications' && <div className="top-popover"><h3>Notificaciones</h3><p className="muted text-small">Actividad de demostración</p>{activity.slice(0, 3).map((item, i) => <div className="notification-item" key={i}><strong>{item.title}</strong><span>{item.detail}</span><small>{item.time}</small></div>)}<Link to="/actividad" onClick={() => setPopover(null)} className="text-link">Ver toda la actividad</Link></div>}</div><div className="popover-host"><button className="account-button" onClick={() => setPopover(popover === 'account' ? null : 'account')} aria-expanded={popover === 'account'}><span className="avatar">{initials}</span><span className="account-text">{displayName}<small>{user?.email ?? 'Cuenta de usuario'}</small></span><ChevronDown size={14} /></button>{popover === 'account' && <div className="top-popover account-popover"><Link to="/configuracion" onClick={() => setPopover(null)}><Settings2 size={16} />Mi perfil</Link>{logoutError && <p className="form-error" role="alert">{logoutError}</p>}<button type="button" onClick={handleLogout} disabled={loggingOut}><LogOut size={16} />{loggingOut ? 'Cerrando sesión…' : 'Cerrar sesión'}</button></div>}</div></div></header>
      <main className="page-content" key={location.pathname}><Outlet /></main><footer className="app-footer"><span>JURISSIM <span className="footer-separator">/</span> Espacio de administración</span><span>Expedientes privados · Otros módulos con datos de muestra</span></footer>
    </div>
    {searchOpen && <Modal title="Buscar en el espacio" description="Los expedientes son propios; los demás resultados todavía son datos de muestra." onClose={() => setSearchOpen(false)}><div className="modal-body"><input className="control" autoFocus aria-label="Búsqueda global" placeholder="Nombre o referencia…" value={search} onChange={(e) => setSearch(e.target.value)} /><div className="global-results">{results.length ? results.map((item) => <Link key={item.id} to={item.path} onClick={() => setSearchOpen(false)}><span>{item.title}<small>{item.detail} · {item.section}</small></span><ArrowUpRight size={17} /></Link>) : <p className="empty-small">{caseQuery.isFetching ? 'Buscando…' : 'No se encontraron coincidencias.'}</p>}</div></div></Modal>}
  </div>
}
