import { createBrowserRouter } from 'react-router-dom'
import { AppShell } from '../layouts/AppShell'
import { DashboardPage } from '../pages/dashboard/DashboardPage'
import { NotFoundPage } from '../pages/errors/NotFoundPage'
import { AuthPage } from '../pages/auth/AuthPage'
import { RequireAuthentication } from '../features/auth/components/RequireAuthentication'
import { RecordsPage } from '../pages/admin/RecordsPage'
import { CaseDetailPage } from '../pages/admin/CaseDetailPage'
import { SettingsPage } from '../pages/admin/SettingsPage'
import { RolesPage } from '../pages/admin/RolesPage'
import { ActivityPage } from '../pages/admin/ActivityPage'

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
        Component: AppShell,
        children: [
          { index: true, Component: DashboardPage },
          ...(['expedientes', 'usuarios', 'documentos', 'biblioteca', 'audiencias'] as const).map((section) => ({ path: section, element: <RecordsPage key={section} section={section} /> })),
          { path: 'expedientes/:id', Component: CaseDetailPage },
          { path: 'configuracion', Component: SettingsPage },
          { path: 'roles', Component: RolesPage },
          { path: 'actividad', Component: ActivityPage },
          { path: '*', Component: NotFoundPage },
        ],
      },
    ],
  },
])
