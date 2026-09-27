import { useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, ChevronLeft, ChevronRight, Download, FileText, RefreshCw, Trash2, Upload } from 'lucide-react'
import { toast } from 'sonner'
import { Badge, Modal, PageHeading } from '../../components/ui/AdminUi'
import { deleteCaseFile, downloadCaseFile, uploadCaseFiles } from '../../features/cases/api/cases-api'
import { casesQueryKey, useCase, useCaseFilePages } from '../../features/cases/model/use-cases'
import type { CaseFile, LegalCase, ProcessingStatus } from '../../features/cases/model/case'
import { getAuthErrorMessage } from '../../features/auth/api/auth-api'
import { shortDate } from '../../shared/lib/admin-utils'

const statusLabel: Record<LegalCase['status'], string> = {
  borrador: 'En preparación',
  activo: 'Activo',
  archivado: 'Archivado',
}

const extractionLabel: Record<ProcessingStatus, string> = {
  pendiente: 'En cola',
  procesando: 'Leyendo texto',
  procesado: 'Listo',
  error: 'Revisar extracción',
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function CaseDetailPage() {
  const { id: rawId } = useParams()
  const id = Number(rawId)
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [files, setFiles] = useState<File[]>([])
  const [removing, setRemoving] = useState<CaseFile | null>(null)
  const [downloadId, setDownloadId] = useState<number | null>(null)
  const [expandedFileId, setExpandedFileId] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const result = useCase(id)
  const record = result.data
  const pages = useCaseFilePages(id, expandedFileId, page, expandedFileId !== null)

  const upload = useMutation({
    mutationFn: () => uploadCaseFiles(id, files),
    onSuccess: async (saved) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...casesQueryKey, id] }),
        queryClient.invalidateQueries({ queryKey: casesQueryKey }),
      ])
      setFiles([])
      if (inputRef.current) inputRef.current.value = ''
      toast.success(`${saved.length} ${saved.length === 1 ? 'archivo cargado' : 'archivos cargados'} en privado.`)
    },
    onError: (error) => toast.error(getAuthErrorMessage(error)),
  })

  const remove = useMutation({
    mutationFn: (fileId: number) => deleteCaseFile(id, fileId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...casesQueryKey, id] }),
        queryClient.invalidateQueries({ queryKey: casesQueryKey }),
      ])
      if (expandedFileId === removing?.id) setExpandedFileId(null)
      setRemoving(null)
      toast.success('Archivo eliminado del expediente.')
    },
    onError: (error) => toast.error(getAuthErrorMessage(error)),
  })

  async function download(file: CaseFile) {
    setDownloadId(file.id)
    try {
      await downloadCaseFile(id, file.id, file.name)
    } catch (error) {
      toast.error(getAuthErrorMessage(error))
    } finally {
      setDownloadId(null)
    }
  }

  function toggleExtraction(fileId: number) {
    setExpandedFileId((current) => current === fileId ? null : fileId)
    setPage(1)
  }

  if (!Number.isSafeInteger(id) || id < 1) return <div className="empty-state"><FileText size={30} /><h1>Expediente no disponible</h1><p>La referencia del expediente no es válida.</p><Link className="btn btn-secondary" to="/expedientes"><ArrowLeft size={16} />Volver a expedientes</Link></div>
  if (result.isPending) return <div className="empty-state" role="status"><span className="loading-spinner" />Cargando expediente…</div>
  if (result.isError || !record) return <div className="empty-state"><FileText size={30} /><h1>Expediente no disponible</h1><p>{result.isError ? getAuthErrorMessage(result.error) : 'No encontramos este expediente en su cuenta.'}</p><Link className="btn btn-secondary" to="/expedientes"><ArrowLeft size={16} />Volver a expedientes</Link></div>

  const orderedFiles = record.files ?? []

  return <>
    <Link className="back-link" to="/expedientes"><ArrowLeft size={16} />Todos los expedientes</Link>
    <PageHeading
      eyebrow={record.case_number || `EXP-${String(record.id).padStart(5, '0')}`}
      title={record.title}
      description={`Creado el ${shortDate(record.created_at)} · ${record.file_count} ${record.file_count === 1 ? 'archivo' : 'archivos'}`}
    >
      <Badge>{statusLabel[record.status] ?? record.status}</Badge>
    </PageHeading>

    <div className="detail-grid">
      <section className="panel padded-panel">
        <p className="eyebrow">CONTEXTO DEL CASO</p>
        <h2>Información general</h2>
        <p className="detail-description">{record.description || 'Aún no se añadió una descripción. Mantenga este resumen libre de información innecesaria.'}</p>
        <dl className="detail-list">
          <div><dt>Referencia</dt><dd>{record.case_number || 'Sin referencia asignada'}</dd></div>
          <div><dt>Estado del procesamiento</dt><dd>{extractionLabel[record.processing_status]}</dd></div>
          <div><dt>Última actualización</dt><dd>{shortDate(record.updated_at ?? record.created_at)}</dd></div>
        </dl>
        <div className="notice">Los documentos permanecen privados. El texto se prepara en segundo plano; las páginas que no puedan leerse quedan señaladas para revisión.</div>
      </section>

      <section className="panel padded-panel">
        <div className="panel-heading"><div><p className="eyebrow">ARCHIVO PRIVADO</p><h2>Documentación</h2></div><span className="status">{orderedFiles.length} adjuntos</span></div>
        <p className="muted text-small">PDF, DOCX, JPG, PNG o TIFF · hasta 50 MB por archivo · máximo 5 por carga.</p>
        <div className="upload-zone">
          <Upload size={23} aria-hidden="true" />
          <strong>Añadir documentos</strong>
          <input
            ref={inputRef}
            type="file"
            multiple
            accept=".pdf,.docx,.jpg,.jpeg,.png,.tif,.tiff,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/tiff"
            aria-label="Seleccionar documentos para el expediente"
            onChange={(event) => setFiles(Array.from(event.target.files ?? []))}
          />
          {files.length > 0 && <small>{files.length} seleccionados · {files.map((file) => file.name).join(', ')}</small>}
          <button className="btn btn-primary" type="button" disabled={files.length === 0 || upload.isPending} onClick={() => upload.mutate()}>
            {upload.isPending ? <><RefreshCw size={15} className="spin" />Cargando…</> : <><Upload size={15} />Guardar en privado</>}
          </button>
        </div>

        {orderedFiles.length > 0 ? <div className="case-file-list">{orderedFiles.map((file) => <article className="document-entry" key={file.id}>
          <div className="document-row">
            <FileText size={21} aria-hidden="true" />
            <div><strong>{file.name}</strong><small>{file.extension.toUpperCase()} · {formatSize(file.size_bytes)}{file.page_count ? ` · ${file.page_count} páginas` : file.extension === 'docx' && file.processing_status === 'procesado' ? ' · Paginación no disponible' : ''} · {shortDate(file.created_at)}</small></div>
            <Badge>{extractionLabel[file.processing_status]}</Badge>
            <div className="row-actions">
              <button className="icon-button" aria-label={`Descargar ${file.name}`} disabled={downloadId === file.id} onClick={() => void download(file)}><Download size={16} /></button>
              {(file.processing_status === 'procesado' || file.processing_status === 'error') && <button className="btn btn-secondary extraction-toggle" aria-expanded={expandedFileId === file.id} aria-controls={`extraction-${file.id}`} onClick={() => toggleExtraction(file.id)}>{expandedFileId === file.id ? 'Ocultar texto' : 'Ver texto'}</button>}
              <button className="icon-button danger" aria-label={`Eliminar ${file.name}`} onClick={() => setRemoving(file)}><Trash2 size={15} /></button>
            </div>
          </div>
          {file.processing_message && <p className="file-processing-message" role="status">{file.processing_message}</p>}
          {expandedFileId === file.id && <section id={`extraction-${file.id}`} className="extraction-panel" aria-label={`Texto extraído de ${file.name}`}>
            {pages.isPending ? <p className="muted" role="status">Cargando texto extraído…</p> : pages.isError ? <div className="inline-error" role="alert"><p>{getAuthErrorMessage(pages.error)}</p><button className="btn btn-secondary" onClick={() => void pages.refetch()}>Reintentar</button></div> : pages.data?.data.length ? <>
              {pages.data.data.map((item) => <article className="extraction-page" key={item.id}>
                <div className="extraction-page-heading"><strong>{item.locator}</strong>{item.used_ocr && <span>Reconocido desde imagen</span>}</div>
                {item.text ? <p>{item.text}</p> : <p className="muted">No se encontró texto legible en esta página.</p>}
              </article>)}
              {pages.data.meta.last_page > 1 && <nav className="extraction-pagination" aria-label="Páginas del texto extraído">
                <button className="btn btn-secondary" disabled={!pages.data.links.prev} onClick={() => setPage((current) => Math.max(1, current - 1))}><ChevronLeft size={15} />Anterior</button>
                <span>Página {pages.data.meta.current_page} de {pages.data.meta.last_page}</span>
                <button className="btn btn-secondary" disabled={!pages.data.links.next} onClick={() => setPage((current) => current + 1)}>Siguiente<ChevronRight size={15} /></button>
              </nav>}
            </> : <p className="muted">Todavía no hay páginas procesadas para mostrar.</p>}
          </section>}
        </article>)}</div> : <div className="empty-small">Todavía no se han añadido documentos.</div>}
      </section>
    </div>

    {removing && <Modal title="Retirar documento" description="Se elimina el archivo privado y su registro de este expediente." onClose={() => setRemoving(null)}>
      <div className="modal-body"><p>¿Eliminar <strong>{removing.name}</strong>? Esta acción no se puede deshacer desde la aplicación.</p><div className="modal-actions">
        <button className="btn btn-secondary" onClick={() => setRemoving(null)}>Cancelar</button>
        <button className="btn btn-danger" disabled={remove.isPending} onClick={() => remove.mutate(removing.id)}>{remove.isPending ? 'Eliminando…' : 'Eliminar archivo'}</button>
      </div></div>
    </Modal>}
  </>
}
