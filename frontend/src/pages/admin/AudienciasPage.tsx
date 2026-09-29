import { useDeferredValue, useState } from 'react'
import { ArrowLeft, ArrowRight, CalendarDays, Search } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Badge, PageHeading, SearchBox } from '../../components/ui/AdminUi'
import { useCases } from '../../features/cases/model/use-cases'
import { getAuthErrorMessage } from '../../features/auth/api/auth-api'
import { shortDate } from '../../shared/lib/admin-utils'
import '../../features/simulations/simulations.css'

export function AudienciasPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const deferredSearch = useDeferredValue(search.trim())
  const result = useCases(deferredSearch, page)
  const cases = result.data?.data ?? []

  return <>
    <PageHeading eyebrow="Práctica guiada" title="Audiencias" description="Prepare una práctica desde un expediente propio y un análisis revisado por una persona.">
      <Link className="btn btn-secondary" to="/expedientes">Ver expedientes</Link>
    </PageHeading>

    <div className="collection-summary">
      <strong>{result.data?.meta.total ?? '—'}<span>expedientes propios</span></strong>
      <span className="collection-summary-note">La audiencia toma su contexto del análisis aprobado; no sustituye una actuación real.</span>
    </div>

    <section className="panel simulation-case-panel" aria-label="Expedientes para práctica">
      <div className="collection-toolbar">
        <SearchBox value={search} onChange={(value) => { setSearch(value); setPage(1) }} placeholder="Buscar expediente por título o referencia…" />
      </div>

      {result.isPending && <div className="empty-state" role="status"><span className="loading-spinner" />Cargando sus expedientes…</div>}
      {result.isError && <div className="empty-state"><CalendarDays size={28} /><h3>No pudimos cargar sus expedientes</h3><p>{getAuthErrorMessage(result.error)}</p><button className="btn btn-secondary" onClick={() => void result.refetch()}>Reintentar</button></div>}

      {result.isSuccess && cases.length > 0 && <div className="simulation-case-list">
        {cases.map((item) => <article className="simulation-case-card" key={item.id}>
          <div className="simulation-case-mark" aria-hidden="true"><CalendarDays size={19} /></div>
          <div className="simulation-case-copy">
            <p className="eyebrow">{item.case_number || `EXP-${String(item.id).padStart(5, '0')}`}</p>
            <h2>{item.title}</h2>
            <p>{item.file_count} {item.file_count === 1 ? 'documento' : 'documentos'} · Actualizado {shortDate(item.updated_at ?? item.created_at)}</p>
          </div>
          <Badge>{item.status === 'activo' ? 'Disponible' : item.status === 'archivado' ? 'Archivado' : 'En preparación'}</Badge>
          <Link className="btn btn-secondary" to={`/expedientes/${item.id}#simulaciones`} aria-label={`Preparar audiencia para ${item.title}`}>
            Preparar <ArrowRight size={15} />
          </Link>
        </article>)}
      </div>}

      {result.isSuccess && (result.data.meta.last_page > 1 || page > 1) && <div className="pagination">
        <span>{result.data.meta.total} expedientes</span>
        <div><button aria-label="Página anterior" className="icon-button" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}><ArrowLeft size={16} /></button><span>Página {result.data.meta.current_page} de {result.data.meta.last_page}</span><button aria-label="Página siguiente" className="icon-button" disabled={page >= result.data.meta.last_page} onClick={() => setPage((current) => current + 1)}><ArrowRight size={16} /></button></div>
      </div>}

      {result.isSuccess && cases.length === 0 && <div className="empty-state"><Search size={28} /><h3>{deferredSearch ? 'No encontramos coincidencias' : 'Todavía no hay expedientes'}</h3><p>{deferredSearch ? 'Pruebe con otro título o referencia.' : 'Abra un expediente para preparar su primera práctica de audiencia.'}</p><Link className="btn btn-primary" to={deferredSearch ? '/audiencias' : '/expedientes?crear=1'}>{deferredSearch ? 'Limpiar búsqueda' : 'Abrir expediente'}</Link></div>}
      {result.isFetching && !result.isPending && <p className="page-note" role="status">Actualizando expedientes…</p>}
    </section>

    <p className="page-note">Las prácticas son privadas y académicas. Solo se habilitan cuando el análisis vigente del expediente cuenta con aprobación humana.</p>
  </>
}
