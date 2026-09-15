import { Link } from 'react-router-dom'

export function NotFoundPage() {
  return (
    <div className="grid min-h-[calc(100vh-4.5rem)] place-items-center px-6 text-center">
      <div>
        <p className="text-xs font-bold uppercase tracking-[0.2em] text-oxide-700">Página no disponible</p>
        <h1 className="mt-4 font-serif text-4xl font-semibold">Este módulo aún no está habilitado.</h1>
        <Link to="/" className="mt-6 inline-block text-sm font-bold text-oxide-700 hover:underline">
          Volver a la sala de trabajo
        </Link>
      </div>
    </div>
  )
}
