import { createBrowserRouter } from 'react-router-dom'
import { RequireAuthentication } from '../features/auth/components/RequireAuthentication'
import { AuthPage } from '../pages/auth/AuthPage'
import {
  ActivityPage,
  AppShell,
  AudienciasPage,
  CaseDetailPage,
  DashboardPage,
  DeferredPage,
  ExpedientesPage,
  NotFoundPage,
  PropuestasTurnosPage,
  RecordsPage,
  RolesPage,
  SimulationDetailPage,
  SettingsPage,
} from './lazy-pages'

export const router = createBrowserRouter([
  { path: '/login', element: <AuthPage key="login" mode="login" /> },
  { path: '/registro', element: <AuthPage key="register" mode="register" /> },
  { path: '/recuperar-acceso', element: <AuthPage key="recover" mode="recover" /> },
  { path: '/restablecer-contrasena/:token', element: <AuthPage key="reset" mode="reset" /> },
  {
    element: <RequireAuthentication />,
    children: [
      {
        path: '/',
        element: <DeferredPage><AppShell /></DeferredPage>,
        children: [
          { index: true, element: <DeferredPage><DashboardPage /></DeferredPage> },
          { path: 'expedientes', element: <DeferredPage><ExpedientesPage /></DeferredPage> },
          ...(['usuarios', 'documentos', 'biblioteca'] as const).map((section) => ({
            path: section,
            element: <DeferredPage><RecordsPage key={section} section={section} /></DeferredPage>,
          })),
          { path: 'audiencias', element: <DeferredPage><AudienciasPage /></DeferredPage> },
          { path: 'expedientes/:id', element: <DeferredPage><CaseDetailPage /></DeferredPage> },
          { path: 'simulaciones/:id', element: <DeferredPage><SimulationDetailPage /></DeferredPage> },
          { path: 'configuracion', element: <DeferredPage><SettingsPage /></DeferredPage> },
          { path: 'configuracion/turnos', element: <DeferredPage><PropuestasTurnosPage /></DeferredPage> },
          { path: 'roles', element: <DeferredPage><RolesPage /></DeferredPage> },
          { path: 'actividad', element: <DeferredPage><ActivityPage /></DeferredPage> },
          { path: '*', element: <DeferredPage><NotFoundPage /></DeferredPage> },
        ],
      },
    ],
  },
])
