import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import axios from 'axios'
import { ArrowLeft, ArrowRight, AlertCircle, RefreshCw } from 'lucide-react'
import { Field } from '../../../components/ui/AdminUi'
import { huellaArchivo, nombresEstado, type EstadoRevision, type Habilitacion, type Pagina } from '../model/reglas'

export function EstadoRegla({ estado }: { estado: EstadoRevision | Habilitacion['estado'] }) {
  return <span className={`reglas-status ${estado === 'aprobado' || estado === 'vigente' ? 'reglas-status-validado' : ''}`}>{nombresEstado[estado] ?? 'Estado no reconocido'}</span>
}
export function ErrorReglas({ error, reintentar }: { error: unknown; reintentar?: () => void }) {
  const estado = axios.isAxiosError(error) ? error.response?.status : undefined
  const mensaje = estado === 401 || estado === 419 ? 'La sesión venció. Vuelva a iniciar sesión antes de continuar.'
    : estado === 403 || estado === 404 ? 'Este registro no está disponible para su cuenta. Compruebe su habilitación o regrese al listado.'
      : estado === 409 ? 'El registro cambió o la operación no está permitida. Vuelva a consultarlo y revise su habilitación antes de decidir.'
        : estado === 429 ? 'Se hicieron demasiadas solicitudes. Espere un minuto antes de volver a consultar.'
          : 'No pudimos consultar el registro. Compruebe la conexión y vuelva a intentarlo.'
  return <div className="reglas-error" role="alert"><AlertCircle size={19} aria-hidden="true" /><div><p>{mensaje}</p>{reintentar && <button className="btn btn-secondary" type="button" onClick={reintentar}><RefreshCw size={16} aria-hidden="true" />Volver a consultar</button>}</div></div>
}
export function PaginacionReglas({ meta, cambiar, ocupada }: { meta: Pagina<unknown>['meta']; cambiar: (pagina: number) => void; ocupada?: boolean }) {
  return <div className="reglas-pagination"><span>Página {meta.current_page} de {meta.last_page} · {meta.total} registros</span><div><button type="button" className="btn btn-secondary" disabled={ocupada || meta.current_page <= 1} onClick={() => cambiar(meta.current_page - 1)}><ArrowLeft size={16} aria-hidden="true" />Anterior</button><button type="button" className="btn btn-secondary" disabled={ocupada || meta.current_page >= meta.last_page} onClick={() => cambiar(meta.current_page + 1)}>Siguiente<ArrowRight size={16} aria-hidden="true" /></button></div></div>
}
export function CampoRegla({ nombre, etiqueta, errores, hint, children }: { nombre: string; etiqueta: string; errores: Record<string, string>; hint?: string; children: (atributos: { id: string; 'aria-invalid': boolean; 'aria-describedby'?: string }) => ReactNode }) {
  const id = useId()
  return <Field label={etiqueta}>{children({ id, 'aria-invalid': Boolean(errores[nombre]), 'aria-describedby': `${id}-ayuda` })}<small id={`${id}-ayuda`} className={errores[nombre] ? 'reglas-field-error' : undefined}>{errores[nombre] || hint || 'Campo obligatorio.'}</small></Field>
}
export function HuellaDocumento({ nombre, etiqueta, valor, cambiar, errores, deshabilitado }: { nombre: string; etiqueta: string; valor: string; cambiar: (huella: string) => void; errores: Record<string, string>; deshabilitado: boolean }) {
  const [calculando, setCalculando] = useState(false)
  const [error, setError] = useState('')
  const contador = useRef(0)
  useEffect(() => () => { contador.current++ }, [])
  return <div className="reglas-huella"><CampoRegla nombre={nombre} etiqueta={etiqueta} errores={errores} hint="Puede calcular la huella con un archivo (máximo 20 MB). El archivo no se sube.">{atributos => <input {...atributos} type="file" disabled={deshabilitado || calculando} onChange={async event => {
    const archivo = event.currentTarget.files?.[0]
    const intento = ++contador.current
    setError(''); cambiar('')
    if (!archivo) return
    setCalculando(true)
    try { const huella = await huellaArchivo(archivo); if (contador.current === intento) cambiar(huella) }
    catch { if (contador.current === intento) setError('No se pudo calcular la huella. Use un archivo de hasta 20 MB o ingrésela manualmente.') }
    finally { if (contador.current === intento) setCalculando(false) }
  }} />}</CampoRegla>{calculando && <p role="status">Calculando huella…</p>}{error && <p role="alert" className="reglas-field-error">{error}</p>}
    <CampoRegla nombre={nombre} etiqueta="Huella SHA-256" errores={errores} hint="64 caracteres en minúsculas. También admite una huella calculada por usted.">{atributos => <input {...atributos} value={valor} onChange={e => cambiar(e.target.value)} disabled={deshabilitado || calculando} maxLength={64} spellCheck={false} autoComplete="off" className="reglas-mono" />}</CampoRegla></div>
}
