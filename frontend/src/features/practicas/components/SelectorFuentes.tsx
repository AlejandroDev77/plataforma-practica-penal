import { useState, type FormEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { buscarFuentes, errorPractica } from '../api/practicas-api'
import type { FuenteSeleccionable } from '../model/practica-reglada'

export function SelectorFuentes({ id, seleccionadas, onChange, limite, busy }: {
  id: number; seleccionadas: FuenteSeleccionable[]; onChange: (fuentes: FuenteSeleccionable[]) => void; limite: number; busy: boolean
}) {
  const [consulta, setConsulta] = useState('')
  const buscar = useMutation({ gcTime: 0, mutationFn: (texto: string) => buscarFuentes(id, texto), retry: false })
  const resultados = buscar.data ?? []
  const fuentes = [...seleccionadas, ...resultados.filter(f => !seleccionadas.some(s => s.id === f.id))]
  function enviar(e: FormEvent) { e.preventDefault(); buscar.mutate(consulta.trim()) }
  return <section className="practica-fuentes" aria-labelledby="practica-fuentes-titulo">
    <div className="practica-subtitulo"><h3 id="practica-fuentes-titulo">Referencias del turno</h3><span>{seleccionadas.length} de {limite}</span></div>
    <p>Busca y marca los extractos que quieras utilizar. Ninguna fuente se incluye automáticamente.</p>
    <form onSubmit={enviar} className="practica-busqueda">
      <label className="practica-campo" htmlFor="practica-buscar"><span>Buscar en el expediente y las normas</span><input id="practica-buscar" value={consulta} maxLength={500} onChange={e => setConsulta(e.target.value)} disabled={busy || buscar.isPending} placeholder="Hecho, argumento o referencia…" /></label>
      <button className="btn btn-secondary" type="submit" disabled={busy || buscar.isPending || consulta.trim().length < 3}>{buscar.isPending ? 'Buscando…' : 'Buscar fuentes'}</button>
    </form>
    {buscar.isError && <p className="practica-error" role="alert">{errorPractica(buscar.error)}</p>}
    {buscar.isSuccess && !resultados.length && <p role="status">No encontramos coincidencias. Prueba con otras palabras o continúa sin referencias seleccionadas.</p>}
    {fuentes.length > 0 && <ul className="practica-fuente-lista">{fuentes.map(f => {
      const marcada = seleccionadas.some(s => s.id === f.id)
      return <li key={f.id}>
        <label className="practica-check"><input type="checkbox" checked={marcada} disabled={busy || (!marcada && seleccionadas.length >= limite)} onChange={e => onChange(e.target.checked ? [...seleccionadas, f] : seleccionadas.filter(s => s.id !== f.id))} />
          <span><strong>{f.fuente.archivo ?? f.fuente.titulo ?? 'Referencia disponible'}</strong><small>{f.clase === 'expediente' ? f.fuente.localizador ?? `Página ${f.fuente.pagina ?? 'sin identificar'}` : 'Normativa · consultar vigencia'}</small></span>
        </label>
        <details><summary>Leer extracto{limite === 8 ? ' que se enviará' : ''}</summary><blockquote>{limite === 8 ? Array.from(f.extracto).slice(0, 1500).join('') : f.extracto}</blockquote></details>
      </li>
    })}</ul>}
    {!seleccionadas.length && <p className="practica-nota">Sin referencias seleccionadas. El resultado no se presentará como respaldado por fuentes.</p>}
  </section>
}
