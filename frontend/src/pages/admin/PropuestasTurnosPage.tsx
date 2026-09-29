import { useDeferredValue, useEffect, useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { AlertTriangle, ArrowLeft, ArrowRight, ClipboardList, FilePlus2, Pencil, Plus, ShieldAlert, Trash2 } from 'lucide-react'
import { Badge, Field, Modal, PageHeading, SearchBox } from '../../components/ui/AdminUi'
import { useCurrentUser } from '../../features/auth/model/use-current-user'
import {
  eliminarPropuesta,
  guardarPropuesta,
  listarPropuestas,
  listarTiposAudiencia,
  type DatosPropuestaTurno,
  type PropuestaTurno,
} from '../../features/audiencias/api/propuestas-turnos-api'
import './propuestas-turnos.css'

const queryKey = ['administracion', 'propuestas-turnos'] as const
const roles = [
  { value: 'abogado_defensor', label: 'Abogado defensor' },
  { value: 'juez', label: 'Juez' },
  { value: 'fiscal', label: 'Fiscal' },
]

interface BorradorFormulario {
  id_tipo_audiencia: string
  id_etapa: string
  orden: string
  rol: string
  acto_propuesto: string
  descripcion_propuesta: string
  referencia_normativa_propuesta: string
  observaciones: string
}

const formularioVacio: BorradorFormulario = {
  id_tipo_audiencia: '', id_etapa: '', orden: '1', rol: '', acto_propuesto: '',
  descripcion_propuesta: '', referencia_normativa_propuesta: '', observaciones: '',
}

function datosDesdePropuesta(propuesta: PropuestaTurno): BorradorFormulario {
  return {
    id_tipo_audiencia: String(propuesta.id_tipo_audiencia),
    id_etapa: String(propuesta.id_etapa),
    orden: String(propuesta.orden),
    rol: propuesta.rol,
    acto_propuesto: propuesta.acto_propuesto,
    descripcion_propuesta: propuesta.descripcion_propuesta,
    referencia_normativa_propuesta: propuesta.referencia_normativa_propuesta ?? '',
    observaciones: propuesta.observaciones ?? '',
  }
}

function mensajeError(error: unknown): string {
  if (axios.isAxiosError(error)) return error.response?.data?.message ?? 'No fue posible guardar el borrador. Compruebe la conexión e inténtelo de nuevo.'
  return 'Ocurrió un problema inesperado. Inténtelo de nuevo.'
}

function erroresValidacion(error: unknown): Record<string, string> {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) return {}
  const errores = error.response.data?.errors as Record<string, string[] | string> | undefined
  return Object.fromEntries(Object.entries(errores ?? {}).map(([campo, mensajes]) => [campo, Array.isArray(mensajes) ? mensajes[0] : mensajes]))
}

function nombreRol(valor: string): string {
  return roles.find((rol) => rol.value === valor)?.label ?? valor
}

export function PropuestasTurnosPage() {
  const { data: usuario } = useCurrentUser()
  const queryClient = useQueryClient()
  const [busqueda, setBusqueda] = useState('')
  const [tipoFiltro, setTipoFiltro] = useState('')
  const [etapaFiltro, setEtapaFiltro] = useState('')
  const [pagina, setPagina] = useState(1)
  const [propuestaEditada, setPropuestaEditada] = useState<PropuestaTurno | null>(null)
  const [modalAbierto, setModalAbierto] = useState(false)
  const [formulario, setFormulario] = useState<BorradorFormulario>(formularioVacio)
  const [errores, setErrores] = useState<Record<string, string>>({})
  const [errorGeneral, setErrorGeneral] = useState('')
  const [aviso, setAviso] = useState('')
  const busquedaDiferida = useDeferredValue(busqueda.trim())
  const esAdministrador = usuario?.roles.includes('administrador_plataforma') ?? false

  const tiposQuery = useQuery({ queryKey: ['tipos-audiencia'], queryFn: listarTiposAudiencia, enabled: esAdministrador })
  const propuestasQuery = useQuery({
    queryKey: [...queryKey, busquedaDiferida, tipoFiltro, etapaFiltro, pagina],
    queryFn: () => listarPropuestas({ buscar: busquedaDiferida, id_tipo_audiencia: tipoFiltro ? Number(tipoFiltro) : undefined, id_etapa: etapaFiltro ? Number(etapaFiltro) : undefined, page: pagina }),
    enabled: esAdministrador,
  })
  const guardarMutation = useMutation({
    mutationFn: ({ id, datos }: { id?: number; datos: DatosPropuestaTurno }) => guardarPropuesta(datos, id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey })
      setModalAbierto(false)
      setAviso(propuestaEditada ? 'La propuesta se actualizó y continúa como borrador.' : 'La propuesta se guardó como borrador.')
      setPropuestaEditada(null)
      setFormulario(formularioVacio)
      setErrores({})
      setErrorGeneral('')
    },
    onError: (error) => {
      setErrores(erroresValidacion(error))
      setErrorGeneral(axios.isAxiosError(error) && error.response?.status === 422 ? '' : mensajeError(error))
    },
  })
  const eliminarMutation = useMutation({
    mutationFn: eliminarPropuesta,
    onSuccess: async () => { await queryClient.invalidateQueries({ queryKey }); setAviso('La propuesta se eliminó.') },
    onError: (error) => setErrorGeneral(mensajeError(error)),
  })

  const tipos = tiposQuery.data ?? []
  const tipoElegido = tipos.find((tipo) => String(tipo.id) === formulario.id_tipo_audiencia)
  const etapasFormulario = tipoElegido?.stages ?? []
  const tipoFiltroElegido = tipos.find((tipo) => String(tipo.id) === tipoFiltro)
  const etapasFiltro = tipoFiltroElegido?.stages ?? []
  const propuestas = propuestasQuery.data?.data ?? []
  const metadatos = propuestasQuery.data?.meta
  const paginas = useMemo(() => metadatos ? Array.from({ length: metadatos.last_page }, (_, indice) => indice + 1) : [], [metadatos])

  useEffect(() => { if (aviso) { const temporizador = window.setTimeout(() => setAviso(''), 5000); return () => window.clearTimeout(temporizador) } }, [aviso])

  function abrirNuevo() {
    setPropuestaEditada(null)
    setFormulario({ ...formularioVacio, id_tipo_audiencia: tipos[0] ? String(tipos[0].id) : '', id_etapa: String(tipos[0]?.stages[0]?.id ?? '') })
    setErrores({}); setErrorGeneral(''); setModalAbierto(true)
  }
  function abrirEdicion(propuesta: PropuestaTurno) {
    setPropuestaEditada(propuesta); setFormulario(datosDesdePropuesta(propuesta)); setErrores({}); setErrorGeneral(''); setModalAbierto(true)
  }
  function actualizarFormulario(campo: keyof BorradorFormulario, valor: string) {
    setFormulario((actual) => ({ ...actual, [campo]: valor, ...(campo === 'id_tipo_audiencia' ? { id_etapa: '' } : {}) }))
    setErrores((actual) => ({ ...actual, [campo]: '', ...(campo === 'id_tipo_audiencia' ? { id_etapa: '' } : {}) }))
  }
  function enviarFormulario(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault(); setErrorGeneral(''); setErrores({})
    guardarMutation.mutate({
      id: propuestaEditada?.id,
      datos: {
        id_tipo_audiencia: Number(formulario.id_tipo_audiencia), id_etapa: Number(formulario.id_etapa), orden: Number(formulario.orden), rol: formulario.rol,
        acto_propuesto: formulario.acto_propuesto.trim(), descripcion_propuesta: formulario.descripcion_propuesta.trim(),
        referencia_normativa_propuesta: formulario.referencia_normativa_propuesta.trim() || null, observaciones: formulario.observaciones.trim() || null,
      },
    })
  }
  function confirmarEliminacion(propuesta: PropuestaTurno) {
    if (window.confirm(`¿Eliminar el borrador «${propuesta.acto_propuesto}»? Esta acción no se puede deshacer.`)) { setErrorGeneral(''); eliminarMutation.mutate(propuesta.id) }
  }

  if (!esAdministrador) return <main className="auth-state"><section className="auth-state-panel" aria-labelledby="propuestas-sin-acceso"><p className="eyebrow">FACULTAD RESTRINGIDA</p><h1 id="propuestas-sin-acceso">Acceso exclusivo de administración de plataforma.</h1><p>La preparación de propuestas de secuencia jurídica no concede autoridad para aprobarlas o activarlas.</p></section></main>

  return <>
    <PageHeading eyebrow="Configuración de audiencias" title="Propuestas de turnos" description="Prepare borradores de intervención por etapa para revisión posterior del equipo jurídico.">
      <button className="btn btn-primary" type="button" onClick={abrirNuevo} disabled={tiposQuery.isLoading || tipos.length === 0}><Plus size={16} aria-hidden="true" />Nueva propuesta</button>
    </PageHeading>
    <section className="propuestas-aviso" aria-label="Alcance de esta herramienta"><ShieldAlert size={19} aria-hidden="true" /><p><strong>Solo borradores.</strong> Guardar o editar una propuesta no activa turnos, no cambia las simulaciones y no constituye aprobación ni validación jurídica.</p></section>
    {aviso && <p className="propuestas-confirmacion" role="status">{aviso}</p>}
    {errorGeneral && <p className="form-error propuestas-error" role="alert">{errorGeneral}</p>}
    {tiposQuery.isError && <div className="form-error propuestas-error" role="alert">No fue posible cargar las audiencias activas. <button type="button" className="text-link" onClick={() => void tiposQuery.refetch()}>Reintentar</button></div>}
    {!tiposQuery.isLoading && !tiposQuery.isError && tipos.length === 0 && <div className="propuestas-empty"><ClipboardList size={25} aria-hidden="true" /><h2>No hay audiencias configuradas</h2><p>Primero debe existir una audiencia activa con etapas disponibles.</p></div>}

    <section className="panel propuestas-panel" aria-label="Listado de propuestas">
      <div className="collection-toolbar propuestas-toolbar">
        <SearchBox value={busqueda} onChange={(valor) => { setBusqueda(valor); setPagina(1) }} placeholder="Buscar acto, descripción o referencia…" />
        <div className="filter-group"><label className="sr-only" htmlFor="filtro-audiencia">Filtrar por audiencia</label><select id="filtro-audiencia" value={tipoFiltro} onChange={(event) => { setTipoFiltro(event.target.value); setEtapaFiltro(''); setPagina(1) }}><option value="">Todas las audiencias</option>{tipos.map((tipo) => <option key={tipo.id} value={tipo.id}>{tipo.name}</option>)}</select><label className="sr-only" htmlFor="filtro-etapa">Filtrar por etapa</label><select id="filtro-etapa" value={etapaFiltro} disabled={!tipoFiltro} onChange={(event) => { setEtapaFiltro(event.target.value); setPagina(1) }}><option value="">Todas las etapas</option>{etapasFiltro.map((etapa) => <option key={etapa.id} value={etapa.id}>{etapa.order}. {etapa.name}</option>)}</select></div>
      </div>
      {propuestasQuery.isPending ? <div className="propuestas-cargando" role="status"><span className="loading-spinner" />Cargando propuestas…</div>
        : propuestasQuery.isError
          ? <div className="propuestas-empty" role="alert"><AlertTriangle size={24} aria-hidden="true" /><h2>No pudimos cargar las propuestas</h2><p>Compruebe su conexión y vuelva a intentarlo.</p><button className="btn btn-secondary" type="button" onClick={() => void propuestasQuery.refetch()}>Reintentar</button></div>
          : propuestas.length === 0 ? <div className="propuestas-empty"><FilePlus2 size={25} aria-hidden="true" /><h2>{busquedaDiferida || tipoFiltro || etapaFiltro ? 'No hay coincidencias' : 'Aún no hay propuestas'}</h2><p>{busquedaDiferida || tipoFiltro || etapaFiltro ? 'Ajuste los filtros para consultar otros borradores.' : 'Las propuestas que prepare el equipo aparecerán aquí. No se han precargado secuencias jurídicas.'}</p>{!busquedaDiferida && !tipoFiltro && !etapaFiltro && tipos.length > 0 && <button className="btn btn-secondary" type="button" onClick={abrirNuevo}><FilePlus2 size={16} aria-hidden="true" />Preparar primer borrador</button>}</div>
            : <>
              <div className="propuestas-lista">{propuestas.map((propuesta) => <article className="propuesta-card" key={propuesta.id}>
                <div className="propuesta-orden" aria-label={`Orden ${propuesta.orden}`}>{String(propuesta.orden).padStart(2, '0')}</div>
                <div className="propuesta-contenido"><div className="propuesta-titulo"><div><p className="eyebrow">{propuesta.tipo_audiencia} <span aria-hidden="true">/</span> {propuesta.etapa}</p><h2>{propuesta.acto_propuesto}</h2></div><Badge>Borrador</Badge></div><p className="propuesta-descripcion">{propuesta.descripcion_propuesta}</p><div className="propuesta-metadatos"><span><strong>Rol propuesto</strong>{nombreRol(propuesta.rol)}</span>{propuesta.referencia_normativa_propuesta && <span><strong>Referencia propuesta</strong>{propuesta.referencia_normativa_propuesta}</span>}<span><strong>Preparado por</strong>{propuesta.creador}</span></div>{propuesta.observaciones && <p className="propuesta-observacion"><strong>Nota interna:</strong> {propuesta.observaciones}</p>}</div>
                <div className="propuesta-acciones"><button className="btn btn-secondary" type="button" onClick={() => abrirEdicion(propuesta)} aria-label={`Editar borrador ${propuesta.acto_propuesto}`}><Pencil size={15} aria-hidden="true" /><span>Editar</span></button><button className="btn btn-secondary propuestas-eliminar" type="button" onClick={() => confirmarEliminacion(propuesta)} disabled={eliminarMutation.isPending} aria-label={`Eliminar borrador ${propuesta.acto_propuesto}`}><Trash2 size={15} aria-hidden="true" /><span>Eliminar</span></button></div>
              </article>)}</div>
              {metadatos && metadatos.last_page > 1 && <div className="pagination propuestas-paginacion"><span>{metadatos.total} propuestas · Página {metadatos.current_page} de {metadatos.last_page}</span><div><button className="btn btn-secondary" type="button" onClick={() => setPagina((actual) => Math.max(1, actual - 1))} disabled={pagina <= 1}><ArrowLeft size={15} aria-hidden="true" />Anterior</button><span className="propuestas-paginas" aria-label="Páginas">{paginas.map((numero) => <button type="button" key={numero} className={numero === pagina ? 'activa' : ''} aria-current={numero === pagina ? 'page' : undefined} onClick={() => setPagina(numero)}>{numero}</button>)}</span><button className="btn btn-secondary" type="button" onClick={() => setPagina((actual) => Math.min(metadatos.last_page, actual + 1))} disabled={pagina >= metadatos.last_page}>Siguiente<ArrowRight size={15} aria-hidden="true" /></button></div></div>}
            </>}
    </section>
    <p className="page-note">Las secuencias activas permanecen sin cambios. Antes de diseñar cualquier activación, el contenido requiere revisión de profesionales jurídicos competentes.</p>

    {modalAbierto && <Modal title={propuestaEditada ? 'Editar propuesta' : 'Nueva propuesta de turno'} description="El formulario se guarda como borrador y no altera la configuración de simulación." onClose={() => { if (!guardarMutation.isPending) setModalAbierto(false) }}>
      <form className="modal-body propuestas-form" onSubmit={enviarFormulario} noValidate>
        <div className="propuestas-form-nota"><ShieldAlert size={17} aria-hidden="true" /><span>Borrador no validado · sin efecto en simulaciones.</span></div>
        {errorGeneral && <p className="form-error" role="alert">{errorGeneral}</p>}
        <div className="form-grid">
          <Field label="Tipo de audiencia"><select required value={formulario.id_tipo_audiencia} aria-invalid={Boolean(errores.id_tipo_audiencia)} onChange={(event) => actualizarFormulario('id_tipo_audiencia', event.target.value)}><option value="">Seleccione una audiencia</option>{tipos.map((tipo) => <option key={tipo.id} value={tipo.id}>{tipo.name}</option>)}</select>{errores.id_tipo_audiencia && <small className="campo-error">{errores.id_tipo_audiencia}</small>}</Field>
          <Field label="Etapa"><select required disabled={!etapasFormulario.length} value={formulario.id_etapa} aria-invalid={Boolean(errores.id_etapa)} onChange={(event) => actualizarFormulario('id_etapa', event.target.value)}><option value="">Seleccione una etapa</option>{etapasFormulario.map((etapa) => <option key={etapa.id} value={etapa.id}>{etapa.order}. {etapa.name}</option>)}</select>{errores.id_etapa && <small className="campo-error">{errores.id_etapa}</small>}</Field>
          <Field label="Orden propuesto"><input required type="number" min="1" max="100" step="1" value={formulario.orden} aria-invalid={Boolean(errores.orden)} onChange={(event) => actualizarFormulario('orden', event.target.value)} />{errores.orden && <small className="campo-error">{errores.orden}</small>}</Field>
          <Field label="Rol propuesto"><select required value={formulario.rol} aria-invalid={Boolean(errores.rol)} onChange={(event) => actualizarFormulario('rol', event.target.value)}><option value="">Seleccione un rol</option>{roles.map((rol) => <option key={rol.value} value={rol.value}>{rol.label}</option>)}</select>{errores.rol && <small className="campo-error">{errores.rol}</small>}</Field>
        </div>
        <Field label="Acto propuesto"><input required maxLength={160} value={formulario.acto_propuesto} aria-invalid={Boolean(errores.acto_propuesto)} placeholder="Ej.: Apertura de audiencia" onChange={(event) => actualizarFormulario('acto_propuesto', event.target.value)} />{errores.acto_propuesto && <small className="campo-error">{errores.acto_propuesto}</small>}</Field>
        <Field label="Descripción del turno"><textarea required rows={4} maxLength={2000} value={formulario.descripcion_propuesta} aria-invalid={Boolean(errores.descripcion_propuesta)} placeholder="Explique qué se propone que realice el rol en esta intervención." onChange={(event) => actualizarFormulario('descripcion_propuesta', event.target.value)} />{errores.descripcion_propuesta && <small className="campo-error">{errores.descripcion_propuesta}</small>}</Field>
        <Field label="Referencia normativa propuesta"><input maxLength={500} value={formulario.referencia_normativa_propuesta} aria-invalid={Boolean(errores.referencia_normativa_propuesta)} placeholder="Referencia para revisión posterior; no se valida automáticamente." onChange={(event) => actualizarFormulario('referencia_normativa_propuesta', event.target.value)} />{errores.referencia_normativa_propuesta && <small className="campo-error">{errores.referencia_normativa_propuesta}</small>}</Field>
        <Field label="Observaciones internas"><textarea rows={2} maxLength={2000} value={formulario.observaciones} aria-invalid={Boolean(errores.observaciones)} placeholder="Pendientes o contexto para la revisión del equipo." onChange={(event) => actualizarFormulario('observaciones', event.target.value)} />{errores.observaciones && <small className="campo-error">{errores.observaciones}</small>}</Field>
        <div className="modal-actions"><button className="btn btn-secondary" type="button" onClick={() => setModalAbierto(false)} disabled={guardarMutation.isPending}>Cancelar</button><button className="btn btn-primary" type="submit" disabled={guardarMutation.isPending || !tipos.length || !etapasFormulario.length}><FilePlus2 size={15} aria-hidden="true" />{guardarMutation.isPending ? 'Guardando borrador…' : 'Guardar como borrador'}</button></div>
      </form>
    </Modal>}
  </>
}
