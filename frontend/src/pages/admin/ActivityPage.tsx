import { useState } from 'react'
import { ScrollText } from 'lucide-react'
import { useAdminStore } from '../../features/admin/model/admin-store'
import { ExportButton, PageHeading, SearchBox } from '../../components/ui/AdminUi'
import { downloadCsv } from '../../shared/lib/admin-utils'

export function ActivityPage() {
  const activity = useAdminStore((state) => state.activity)
  const [search, setSearch] = useState('')
  const filtered = activity.filter((entry) => `${entry.title} ${entry.detail}`.toLowerCase().includes(search.toLowerCase()))
  return <><PageHeading eyebrow="Administración" title="Registro de actividad" description="Consulte los movimientos del espacio y las acciones del equipo."><ExportButton onClick={() => downloadCsv('actividad-demo.csv', [['Acción', 'Detalle', 'Momento'], ...filtered.map((entry) => [entry.title, entry.detail, entry.time])])} /></PageHeading><section className="panel"><div className="collection-toolbar"><SearchBox value={search} onChange={setSearch} placeholder="Buscar una acción, persona o expediente…" /><span className="muted text-small">{filtered.length} eventos</span></div><div className="audit-list">{filtered.map((entry, index) => <div className="audit-row" key={index}><span className="activity-node"><ScrollText size={17} /></span><div><strong>{entry.title}</strong><p>{entry.detail}</p><span className="audit-reference">EVENTO {String(activity.indexOf(entry) + 1).padStart(4, '0')}</span></div><time>{entry.time}</time></div>)}{!filtered.length && <div className="empty-state"><h3>Sin coincidencias</h3><p>No se encontraron eventos con este término.</p></div>}</div></section><p className="page-note">Registro ilustrativo. Las nuevas acciones de esta sesión aparecen aquí automáticamente.</p></>
}
