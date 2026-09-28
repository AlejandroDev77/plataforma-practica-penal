import { useDeferredValue, useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { ArrowLeft, ArrowRight, BriefcaseBusiness, FilePlus2, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Field, Modal, PageHeading, SearchBox, Badge } from '../../components/ui/AdminUi'
import { createCase, deleteCase, updateCase } from '../../features/cases/api/cases-api'
import { casesQueryKey, useCases } from '../../features/cases/model/use-cases'
import type { LegalCase } from '../../features/cases/model/case'
import { getAuthErrorMessage } from '../../features/auth/api/auth-api'
import { shortDate } from '../../shared/lib/admin-utils'

const statusLabel: Record<LegalCase['status'], string> = {
  borrador: 'En preparación',
  activo: 'Activo',
  archivado: 'Archivado',
}

function CaseEditor({ record, onClose }: { record: LegalCase | 'new'; onClose: () => void }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [error, setError] = useState('')
  const isNew = record === 'new'
  const save = useMutation({
    mutationFn: (input: { title: string; case_number?: string; description?: string }) =>
      isNew ? createCase(input) : updateCase(record.id, input),
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: casesQueryKey })
      toast.success(isNew ? 'Expediente creado.' : 'Cambios guardados.')
      onClose()
      if (isNew) navigate(`/expedientes/${saved.id}`)
    },
    onError: (saveError) => setError(getAuthErrorMessage(saveError)),
  })

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    setError('')
    save.mutate({
      title: String(data.get('title') ?? '').trim(),
      case_number: String(data.get('case_number') ?? '').trim() || undefined,
      description: String(data.get('description') ?? '').trim() || undefined,
    })
  }

  return <Modal
    title={isNew ? 'Abrir expediente' : 'Editar expediente'}
    description="Los archivos se añadirán después, dentro del expediente y en almacenamiento privado."
    onClose={onClose}
  >
    <form className="modal-body" onSubmit={submit}>
      <Field label="Título del expediente">
        <input name="title" required minLength={3} maxLength={255} defaultValue={isNew ? '' : record.title} autoFocus />
      </Field>
      <Field label="Referencia de caso" hint="Opcional. Use la referencia académica o institucional, no datos personales sensibles.">
        <input name="case_number" maxLength={100} defaultValue={isNew ? '' : record.case_number ?? ''} />
      </Field>
      <Field label="Descripción" hint="No incluya información innecesaria ni datos sensibles en este resumen.">
        <textarea name="description" rows={4} maxLength={10000} defaultValue={isNew ? '' : record.description ?? ''} />
      </Field>
      {error && <p className="form-error" role="alert">{error}</p>}
      <div className="modal-actions">
        <button type="button" className="btn btn-secondary" onClick={onClose}>Cancelar</button>
        <button type="submit" className="btn btn-primary" disabled={save.isPending}>
          {save.isPending ? 'Guardando…' : isNew ? 'Crear expediente' : 'Guardar cambios'}
        </button>
      </div>
    </form>
  </Modal>
}

export function ExpedientesPage() {
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<LegalCase | 'new' | null>(null)
  const [removing, setRemoving] = useState<LegalCase | null>(null)
  const deferredSearch = useDeferredValue(search.trim())
  const queryClient = useQueryClient()
  const result = useCases(deferredSearch, page)
  const remove = useMutation({
    mutationFn: (id: number) => deleteCase(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: casesQueryKey })
      setRemoving(null)
      toast.success('Expediente eliminado de su cuenta.')
    },
    onError: (error) => toast.error(getAuthErrorMessage(error)),
  })

  function closeEditor() {
    setEditing(null)
    if (params.has('crear')) {
      const next = new URLSearchParams(params)
      next.delete('crear')
      setParams(next, { replace: true })
    }
  }

  const cases = result.data?.data ?? []
  const meta = result.data?.meta
  const activeEditor = editing ?? (params.has('crear') ? 'new' : null)

  return <>
    <PageHeading eyebrow="Gestión académica" title="Expedientes" description="Organice casos de práctica y administre de forma privada su documentación.">
      <button className="btn btn-primary" onClick={() => setEditing('new')}><Plus size={17} />Nuevo expediente</button>
    </PageHeading>

    <div className="collection-summary">
      <strong>{meta?.total ?? '—'}<span>expedientes propios</span></strong>
      <span className="collection-summary-note"><span className="live-dot" />Los documentos no son públicos y solo se consultan con una sesión autorizada.</span>
    </div>

    <section className="panel">
      <div className="collection-toolbar">
        <SearchBox value={search} onChange={(value) => { setSearch(value); setPage(1) }} placeholder="Buscar por título o referencia…" />
        <span className="text-small muted">PDF · DOCX · JPG · PNG · TIFF</span>
      </div>

      {result.isPending ? <div className="empty-state" role="status"><span className="loading-spinner" />Cargando sus expedientes…</div> : null}
      {result.isError ? <div className="empty-state"><BriefcaseBusiness size={28} /><h3>No pudimos cargar sus expedientes</h3><p>{getAuthErrorMessage(result.error)}</p><button className="btn btn-secondary" onClick={() => void result.refetch()}>Reintentar</button></div> : null}

      {result.isSuccess && cases.length > 0 ? <>
        <div className="table-scroll"><table><thead><tr><th>Expediente</th><th>Estado</th><th>Documentos</th><th>Actualizado</th><th>Acciones</th></tr></thead>
          <tbody>{cases.map((item) => <tr key={item.id}>
            <td><div className="name-cell"><div><Link className="record-title" to={`/expedientes/${item.id}`}>{item.title}</Link><small>{item.case_number || `EXP-${String(item.id).padStart(5, '0')}`}</small></div></div></td>
            <td><Badge>{statusLabel[item.status] ?? item.status}</Badge></td>
            <td>{item.file_count} {item.file_count === 1 ? 'archivo' : 'archivos'}</td>
            <td className="date-cell">{shortDate(item.updated_at ?? item.created_at)}</td>
            <td><div className="row-actions">
              <button className="icon-button" aria-label={`Editar ${item.title}`} onClick={() => setEditing(item)}><Pencil size={15} /></button>
              <button className="icon-button danger" aria-label={`Eliminar ${item.title}`} onClick={() => setRemoving(item)}><Trash2 size={15} /></button>
            </div></td>
          </tr>)}</tbody>
        </table></div>
        <div className="pagination"><span>{meta?.total ?? 0} expedientes</span><div>
          <button aria-label="Página anterior" className="icon-button" disabled={!meta || meta.current_page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}><ArrowLeft size={16} /></button>
          <span>Página {meta?.current_page ?? 1} de {meta?.last_page ?? 1}</span>
          <button aria-label="Página siguiente" className="icon-button" disabled={!meta || meta.current_page >= meta.last_page} onClick={() => setPage((current) => current + 1)}><ArrowRight size={16} /></button>
        </div></div>
      </> : null}

      {result.isSuccess && cases.length === 0 ? <div className="empty-state"><Search size={29} strokeWidth={1.4} /><h3>{deferredSearch ? 'No encontramos coincidencias' : 'Su archivo empieza aquí'}</h3><p>{deferredSearch ? 'Pruebe otro título o referencia.' : 'Cree un expediente y añada documentos cuando esté listo.'}</p>{deferredSearch ? <button className="text-link" onClick={() => setSearch('')}>Limpiar búsqueda</button> : <button className="btn btn-primary" onClick={() => setEditing('new')}><FilePlus2 size={16} />Crear el primer expediente</button>}</div> : null}
      {result.isFetching && !result.isPending ? <p className="page-note" role="status">Actualizando resultados…</p> : null}
    </section>
    <p className="page-note">Los archivos se guardan en privado. La extracción por página está habilitada; el OCR de escaneos depende de Tesseract en este equipo.</p>

    {activeEditor && <CaseEditor record={activeEditor} onClose={closeEditor} />}
    {removing && <Modal title="Eliminar expediente" description="Esta acción elimina el expediente y solicita retirar todos sus archivos privados." onClose={() => setRemoving(null)}>
      <div className="modal-body"><p>¿Eliminar <strong>{removing.title}</strong>? No se podrá recuperar desde la aplicación.</p><div className="modal-actions">
        <button className="btn btn-secondary" onClick={() => setRemoving(null)}>Conservar expediente</button>
        <button className="btn btn-danger" onClick={() => remove.mutate(removing.id)} disabled={remove.isPending}>{remove.isPending ? 'Eliminando…' : 'Eliminar definitivamente'}</button>
      </div></div>
    </Modal>}
  </>
}
