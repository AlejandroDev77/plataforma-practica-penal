import { useState } from 'react'
import { NavLink, Outlet, Link, useLocation } from 'react-router-dom'
import { LayoutDashboard, BriefcaseBusiness, Users, FileText, BookOpenText, CalendarDays, ShieldCheck, Settings2, ScrollText, Menu, Bell, Search, ChevronDown, LogOut, ArrowUpRight } from 'lucide-react'
import { useAdminStore } from '../features/admin/model/admin-store'
import { Modal } from '../components/ui/AdminUi'

const groups = [
  { label: 'ESPACIO DE TRABAJO', items: [{ title: 'Vista general', path: '/', icon: LayoutDashboard }, { title: 'Expedientes', path: '/expedientes', icon: BriefcaseBusiness }, { title: 'Audiencias', path: '/audiencias', icon: CalendarDays }] },
  { label: 'RECURSOS ACADÉMICOS', items: [{ title: 'Documentos', path: '/documentos', icon: FileText }, { title: 'Biblioteca jurídica', path: '/biblioteca', icon: BookOpenText }] },
  { label: 'ADMINISTRACIÓN', items: [{ title: 'Usuarios', path: '/usuarios', icon: Users }, { title: 'Roles y permisos', path: '/roles', icon: ShieldCheck }, { title: 'Registro de actividad', path: '/actividad', icon: ScrollText }, { title: 'Configuración', path: '/configuracion', icon: Settings2 }] },
]
export function Brand({ compact = false }: { compact?: boolean }) {
  return <Link to="/" className="brand"><span className="brand-symbol">p<span>p</span><i /></span>{!compact && <span className="brand-name">praxis<span>PLATAFORMA DE PRÁCTICA PENAL</span></span>}</Link>
}
export function AppShell() {
  const [popover, setPopover] = useState<'navigation' | 'notifications' | 'account' | null>(null)
  const [searchOpen, setSearchOpen] = useState(false)
  const [search, setSearch] = useState('')
  const { profile, records, activity, notificationsRead, readNotifications } = useAdminStore()
  const location = useLocation()
  const title = groups.flatMap((group) => group.items).find((item) => item.path === location.pathname)?.title ?? 'Detalle de expediente'
  const initials = profile.name.split(' ').slice(0, 2).map((part) => part[0]).join('')
  const results = Object.entries(records).flatMap(([section, items]) => items.filter((item) => `${item.title} ${item.id}`.toLowerCase().includes(search.toLowerCase())).map((item) => ({ ...item, section }))).slice(0, 8)
  return <div className="admin-app">
    <div className="app-body"><header className="topbar"><div className="topbar-left"><Brand /><div className="popover-host"><button className="section-button" aria-label="Abrir secciones" aria-expanded={popover === 'navigation'} onClick={() => setPopover(popover === 'navigation' ? null : 'navigation')}><Menu size={17} /><span>Secciones</span></button>{popover === 'navigation' && <nav className="top-popover navigation-popover" aria-label="Navegación administrativa">{groups.map((group) => <div className="navigation-group" key={group.label}><p>{group.label}</p>{group.items.map(({ title: label, path, icon: Icon }) => <NavLink key={path} end={path === '/'} to={path} onClick={() => setPopover(null)} className={({ isActive }) => `navigation-link ${isActive ? 'active' : ''}`}><Icon size={16} /><span>{label}</span>{path === '/expedientes' && <b>{records.expedientes.length}</b>}</NavLink>)}</div>)}</nav>}</div><div className="breadcrumb"><span>Administración</span><i>/</i><strong>{title}</strong></div></div><div className="topbar-actions"><button className="global-search" onClick={() => setSearchOpen(true)}><Search size={17} /><span>Buscar en el espacio</span></button><span className="topbar-divider" /><div className="popover-host"><button className="icon-button notification-button" aria-label="Notificaciones" aria-expanded={popover === 'notifications'} onClick={() => { setPopover(popover === 'notifications' ? null : 'notifications'); readNotifications() }}><Bell size={19} />{!notificationsRead && <i />}</button>{popover === 'notifications' && <div className="top-popover"><h3>Notificaciones</h3><p className="muted text-small">Actividad de demostración</p>{activity.slice(0, 3).map((item, i) => <div className="notification-item" key={i}><strong>{item.title}</strong><span>{item.detail}</span><small>{item.time}</small></div>)}<Link to="/actividad" onClick={() => setPopover(null)} className="text-link">Ver toda la actividad</Link></div>}</div><div className="popover-host"><button className="account-button" onClick={() => setPopover(popover === 'account' ? null : 'account')} aria-expanded={popover === 'account'}><span className="avatar">{initials}</span><span className="account-text">{profile.name}<small>Administración</small></span><ChevronDown size={14} /></button>{popover === 'account' && <div className="top-popover account-popover"><Link to="/configuracion" onClick={() => setPopover(null)}><Settings2 size={16} />Mi perfil</Link><Link to="/login" onClick={() => setPopover(null)}><LogOut size={16} />Salir de la demostración</Link></div>}</div></div></header>
      <main className="page-content" key={location.pathname}><Outlet /></main><footer className="app-footer"><span>Praxis Penal <span className="footer-separator">/</span> Espacio de administración</span><span>Datos ficticios · Cambios temporales</span></footer>
    </div>
    {searchOpen && <Modal title="Buscar en el espacio" description="Encuentre expedientes, personas y recursos de demostración." onClose={() => setSearchOpen(false)}><div className="modal-body"><input className="control" autoFocus aria-label="Búsqueda global" placeholder="Nombre o referencia…" value={search} onChange={(e) => setSearch(e.target.value)} /><div className="global-results">{results.length ? results.map((item) => <Link key={item.id} to={item.section === 'expedientes' ? `/expedientes/${item.id}` : `/${item.section}`} onClick={() => setSearchOpen(false)}><span>{item.title}<small>{item.id} · {item.section}</small></span><ArrowUpRight size={17} /></Link>) : <p className="empty-small">No se encontraron coincidencias.</p>}</div></div></Modal>}
  </div>
}
