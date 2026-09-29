import { lazy, Suspense, type ReactNode } from 'react'

export const AppShell = lazy(() => import('../layouts/AppShell').then((module) => ({ default: module.AppShell })))
export const DashboardPage = lazy(() => import('../pages/dashboard/DashboardPage').then((module) => ({ default: module.DashboardPage })))
export const ExpedientesPage = lazy(() => import('../pages/admin/ExpedientesPage').then((module) => ({ default: module.ExpedientesPage })))
export const CaseDetailPage = lazy(() => import('../pages/admin/CaseDetailPage').then((module) => ({ default: module.CaseDetailPage })))
export const AudienciasPage = lazy(() => import('../pages/admin/AudienciasPage').then((module) => ({ default: module.AudienciasPage })))
export const SimulationDetailPage = lazy(() => import('../pages/admin/SimulationDetailPage').then((module) => ({ default: module.SimulationDetailPage })))
export const RecordsPage = lazy(() => import('../pages/admin/RecordsPage').then((module) => ({ default: module.RecordsPage })))
export const SettingsPage = lazy(() => import('../pages/admin/SettingsPage').then((module) => ({ default: module.SettingsPage })))
export const RolesPage = lazy(() => import('../pages/admin/RolesPage').then((module) => ({ default: module.RolesPage })))
export const ActivityPage = lazy(() => import('../pages/admin/ActivityPage').then((module) => ({ default: module.ActivityPage })))
export const PropuestasTurnosPage = lazy(() => import('../pages/admin/PropuestasTurnosPage').then((module) => ({ default: module.PropuestasTurnosPage })))
export const NotFoundPage = lazy(() => import('../pages/errors/NotFoundPage').then((module) => ({ default: module.NotFoundPage })))

export function RouteLoading() {
  return <div className="route-loading" role="status"><span className="loading-spinner" />Cargando el espacio…</div>
}

export function DeferredPage({ children }: { children: ReactNode }) {
  return <Suspense fallback={<RouteLoading />}>{children}</Suspense>
}
