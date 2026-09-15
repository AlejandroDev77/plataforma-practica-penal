import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, ArrowUpRight, FileText, BookOpenText, Users } from 'lucide-react'
import { useAdminStore } from '../../features/admin/model/admin-store'
import { Badge } from '../../components/ui/AdminUi'
import { shortDate } from '../../shared/lib/admin-utils'

export function CaseDetailPage() {
  const { id } = useParams()
  const { records, activity } = useAdminStore()
  const item = records.expedientes.find((record) => record.id === id)
  const [tab, setTab] = useState('Resumen')
  if (!item) return <div className="empty-state"><h1>Expediente no encontrado</h1><Link className="text-link" to="/expedientes">Volver a expedientes</Link></div>
  const documents = records.documentos.filter((document) => document.owner === item.id)
  return <><Link className="back-link" to="/expedientes"><ArrowLeft size={16} />Todos los expedientes</Link><div className="page-heading"><div><p className="eyebrow">{item.id}</p><h1>{item.title}</h1><p className="page-description">{item.category} <span className="middle-dot">·</span> Actualizado el {shortDate(item.date)}</p></div><Badge>{item.status}</Badge></div><div className="tabs" role="tablist" aria-label="Secciones del expediente">{['Resumen', 'Documentos', 'Actividad'].map((label) => <button role="tab" aria-selected={tab === label} id={`tab-${label}`} aria-controls="case-tab-panel" key={label} onClick={() => setTab(label)} className={tab === label ? 'selected' : ''}>{label}{label === 'Documentos' && <span>{documents.length}</span>}</button>)}</div><div role="tabpanel" id="case-tab-panel" aria-labelledby={`tab-${tab}`}>
  {tab === 'Resumen' && <div className="detail-grid"><section className="panel padded-panel"><p className="eyebrow">CONTEXTO DEL CASO</p><h2>Información general</h2><p className="detail-description">{item.description || 'Expediente ficticio para la preparación de prácticas académicas. El equipo organiza aquí la documentación y los elementos necesarios para la sesión.'}</p><dl className="detail-list"><div><dt>Responsable</dt><dd>{item.owner}</dd></div><div><dt>Grupo y material</dt><dd>{item.subtitle}</dd></div><div><dt>Materia</dt><dd>{item.category}</dd></div></dl><div className="notice">Caso de formación. Los datos de este expediente no corresponden a un proceso judicial real.</div></section><aside className="panel padded-panel"><h2>Recursos del expediente</h2><Link className="resource-link" to="/documentos"><FileText size={19} />Archivo documental<ArrowUpRight size={16} /></Link><Link className="resource-link" to="/biblioteca"><BookOpenText size={19} />Fuentes jurídicas<ArrowUpRight size={16} /></Link><Link className="resource-link" to="/usuarios"><Users size={19} />Equipo académico<ArrowUpRight size={16} /></Link></aside></div>}
  {tab === 'Documentos' && <section className="panel padded-panel"><h2>Documentación relacionada</h2>{documents.length ? documents.map((document) => <div className="document-row" key={document.id}><FileText size={21} /><div><strong>{document.title}</strong><small>{document.subtitle}</small></div><Badge>{document.status}</Badge></div>) : <p className="empty-small">Aún no hay documentos asociados a este expediente.</p>}<Link className="text-link" to="/documentos">Administrar documentos <ArrowRightIcon /></Link></section>}
  {tab === 'Actividad' && <section className="panel padded-panel"><h2>Historial del expediente</h2>{activity.filter((entry) => entry.detail.includes(item.id) || entry.detail.includes(item.title)).length ? activity.filter((entry) => entry.detail.includes(item.id) || entry.detail.includes(item.title)).map((entry, index) => <div className="activity-row" key={index}><div><strong>{entry.title}</strong><p>{entry.detail}</p></div><time>{entry.time}</time></div>) : <p className="empty-small">No hay eventos adicionales registrados en esta demostración.</p>}</section>}
  </div></>
}
function ArrowRightIcon() { return <ArrowUpRight size={16} /> }
