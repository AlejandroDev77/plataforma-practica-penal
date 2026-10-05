import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { useCurrentUser } from '../../auth/model/use-current-user'
import { useHearingTypes, simulationsQueryKey } from '../../simulations/model/use-simulations'
import { consultarCopiaActual, crearPractica, errorPractica } from '../api/practicas-api'
import '../practicas.css'

export function CrearPracticaReglada({ caseId, analysisId }: { caseId: number; analysisId: number }) {
  const tipos = useHearingTypes()
  const usuario = useCurrentUser().data?.id
  const [tipo, setTipo] = useState('')
  return <div className="practica-reglada practica-creacion">
    <div><p className="eyebrow">NUEVA PRÁCTICA</p><h3>Preparar una audiencia</h3><p>Tu papel será la defensa. Elige una versión revisada para comenzar.</p></div>
    <div className="practica-campo"><label htmlFor="practica-tipo">Tipo de audiencia</label>
      <select id="practica-tipo" value={tipo} disabled={tipos.isPending} onChange={e => setTipo(e.target.value)}>
        <option value="">{tipos.isPending ? 'Consultando audiencias…' : 'Selecciona una audiencia'}</option>
        {tipos.data?.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
      </select>
    </div>
    {tipos.isError && <div className="practica-error" role="alert"><p>No se pudo cargar el catálogo.</p><button className="btn btn-secondary" onClick={() => void tipos.refetch()}>Volver a consultar</button></div>}
    {tipos.isSuccess && !tipos.data.length && <p role="status">Aún no hay tipos de audiencia disponibles. Consulta con la administración.</p>}
    {tipo && <CopiaElegida key={`${usuario}-${caseId}-${analysisId}-${tipo}`} caso={caseId} analisis={analysisId} tipo={Number(tipo)} />}
  </div>
}
function CopiaElegida({ caso, analisis, tipo }: { caso: number; analisis: number; tipo: number }) {
  const usuario = useCurrentUser().data?.id
  const copia = useQuery({ queryKey: ['practicas-regladas', usuario, caso, tipo, 'copia'],
    queryFn: ({ signal }) => consultarCopiaActual(tipo, signal), retry: false, gcTime: 0, staleTime: 0 })
  if (copia.isPending) return <p role="status">Comprobando la versión revisada…</p>
  if (copia.isError) return <div className="practica-error" role="alert"><p>{errorPractica(copia.error)}</p><button className="btn btn-secondary" onClick={() => void copia.refetch()}>Actualizar versión</button></div>
  if (!copia.data) return <div className="practica-aviso" role="status"><strong>No hay una versión revisada vigente</strong><p>La administración debe completar la revisión jurídica y su activación. No se puede iniciar una práctica todavía.</p><Link className="text-link" to="/configuracion/reglas">Consultar reglas de audiencia</Link><button className="btn btn-secondary" onClick={() => void copia.refetch()}>Actualizar versión</button></div>
  return <ConfirmarCopia key={copia.data.activacion} caso={caso} analisis={analisis} tipo={tipo} copia={copia.data} actualizando={copia.isFetching} />
}
function ConfirmarCopia({ caso, analisis, tipo, copia, actualizando }: {
  caso: number; analisis: number; tipo: number; copia: { activacion: number; version: number; idVersion: number }; actualizando: boolean
}) {
  const [elegida, setElegida] = useState(false)
  const enviando = useRef(false)
  const [incierto, setIncierto] = useState(false)
  const navigate = useNavigate(), client = useQueryClient()
  const crear = useMutation({ gcTime: 0, mutationFn: () => crearPractica(caso, analisis, tipo, copia.activacion), retry: false, onSettled: () => { enviando.current = false },
    onSuccess: async s => {
      await client.invalidateQueries({ queryKey: [...simulationsQueryKey, 'expediente', caso] })
      navigate(`/simulaciones/${s.id_simulacion}`)
    }, onError: () => { setIncierto(true); setElegida(false) },
  })
  return <div className="practica-copia">
    <div><strong>Versión {copia.version} · revisión vigente</strong><p>Se conservará esta versión durante toda la práctica. Crear la sesión no la inicia.</p></div>
    <Link className="text-link" to={`/configuracion/reglas/versiones/${copia.idVersion}`}>Consultar versión</Link>
    <label className="practica-check"><input type="checkbox" checked={elegida} disabled={crear.isPending || incierto || actualizando} onChange={e => setElegida(e.target.checked)} /><span>Usar esta versión revisada para mi práctica.</span></label>
    <button className="btn btn-primary" disabled={!elegida || crear.isPending || incierto || actualizando} onClick={() => { if (!enviando.current && elegida && !incierto && !actualizando) { enviando.current = true; crear.mutate() } }}>{crear.isPending ? 'Preparando sesión…' : 'Crear práctica'}</button>
    {crear.isError && <div className="practica-error" role="alert"><p>{errorPractica(crear.error)}</p><Link className="text-link" to="/audiencias">Consultar prácticas guardadas</Link><button className="btn btn-secondary" onClick={async () => {
      await client.invalidateQueries({ queryKey: [...simulationsQueryKey, 'expediente', caso] })
      setIncierto(false); crear.reset()
    }}>He revisado las prácticas; volver a preparar</button></div>}
  </div>
}
