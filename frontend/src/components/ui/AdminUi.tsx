import { useEffect, useRef, type ReactNode } from 'react'
import { X, Search, Download } from 'lucide-react'
import { cn } from '../../shared/lib/cn'

export function Badge({ children }: { children: string }) {
  const good = ['Activo', 'Disponible', 'Verificado', 'Publicado', 'Finalizada'].includes(children)
  const bad = ['Suspendido', 'Observado', 'Cancelada'].includes(children)
  return <span className={cn('status', good ? 'status-green' : bad ? 'status-red' : 'status-amber')}><span />{children}</span>
}
export function PageHeading({ eyebrow, title, description, children }: { eyebrow: string; title: string; description: string; children?: ReactNode }) {
  return <div className="page-heading"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1><p className="page-description">{description}</p></div><div className="heading-actions">{children}</div></div>
}
export function SearchBox({ value, onChange, placeholder = 'Buscar por nombre o referencia…' }: { value: string; onChange: (value: string) => void; placeholder?: string }) {
  return <div className="search-field"><Search size={17} aria-hidden="true" /><input aria-label={placeholder} placeholder={placeholder} value={value} onChange={(event) => onChange(event.target.value)} />{value && <button aria-label="Limpiar búsqueda" onClick={() => onChange('')}><X size={15} /></button>}</div>
}
export function Modal({ title, description, children, onClose }: { title: string; description?: string; children: ReactNode; onClose: () => void }) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => { const dialog = ref.current; dialog?.showModal(); return () => dialog?.close() }, [])
  return <dialog ref={ref} className="modal" onCancel={onClose} onClick={(event) => { if (event.target === event.currentTarget) onClose() }} aria-labelledby="dialog-title"><div className="modal-head"><div><h2 id="dialog-title">{title}</h2>{description && <p>{description}</p>}</div><button className="icon-button" aria-label="Cerrar diálogo" onClick={onClose}><X size={20} /></button></div>{children}</dialog>
}
export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return <label className="field"><span>{label}</span>{children}{hint && <small>{hint}</small>}</label>
}
export function ExportButton({ onClick }: { onClick: () => void }) {
  return <button className="btn btn-secondary" onClick={onClick}><Download size={16} />Exportar</button>
}
