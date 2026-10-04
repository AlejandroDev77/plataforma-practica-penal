import { useRef, useState, type FormEvent } from 'react'
import axios from 'axios'
import { ZodError } from 'zod'

export function useRegistro(guardar: () => Promise<unknown>, terminado: () => void) {
  const cerrojo = useRef(false)
  const [ocupado, setOcupado] = useState(false)
  const [bloqueado, setBloqueado] = useState(false)
  const [error, setError] = useState('')
  const [errores, setErrores] = useState<Record<string, string>>({})
  async function enviar(evento: FormEvent) {
    evento.preventDefault()
    if (cerrojo.current || bloqueado) return
    cerrojo.current = true; setOcupado(true); setError(''); setErrores({})
    try { await guardar(); terminado() }
    catch (fallo) {
      if (fallo instanceof ZodError) {
        setErrores(Object.fromEntries(fallo.issues.map(i => [i.path.join('.'), i.message])))
        setError('Revise los campos indicados antes de registrar.')
      } else if (axios.isAxiosError(fallo)) {
        const estado = fallo.response?.status
        if (estado === 422) {
          setErrores(Object.fromEntries(Object.keys(fallo.response?.data?.errors ?? {}).map(c => [c, 'Revise este campo.'])))
          setError('Hay datos incompletos o inválidos. Revise los campos indicados.')
        } else {
          setBloqueado(true)
          setError(estado === 409 ? 'El registro cambió o la operación no está permitida. Cierre el formulario y vuelva a revisar el registro antes de decidir.'
            : estado === 401 || estado === 419 ? 'Su sesión venció. Cierre el formulario e inicie sesión de nuevo.'
              : estado === 403 || estado === 404 ? 'Su cuenta ya no puede realizar esta operación. Cierre y compruebe su habilitación.'
                : 'No recibimos una confirmación del servidor. Cierre y vuelva a consultar antes de repetir: la solicitud podría haberse registrado.')
        }
      } else setError(fallo instanceof Error ? fallo.message : 'No fue posible registrar esta operación.')
    } finally { cerrojo.current = false; setOcupado(false) }
  }
  return { enviar, ocupado, bloqueado, error, errores }
}
