import axios from 'axios'
import { httpClient } from '../../../shared/api/http-client'
import type { ApiEnvelope, AuthenticatedUser } from '../model/auth-types'

export const currentUserQueryKey = ['auth', 'current-user'] as const

async function startCsrfSession() {
  await httpClient.get('/sanctum/csrf-cookie')
}

export async function getCurrentUser(): Promise<AuthenticatedUser | null> {
  try {
    const response = await httpClient.get<ApiEnvelope<AuthenticatedUser>>('/api/v1/me')
    return response.data.data
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 401) return null
    throw error
  }
}

export async function registerAccount(input: { name: string; email: string; password: string; password_confirmation: string }) {
  await startCsrfSession()
  const response = await httpClient.post<ApiEnvelope<AuthenticatedUser>>('/api/v1/register', input)
  return response.data.data
}

export async function signIn(input: { email: string; password: string }) {
  await startCsrfSession()
  const response = await httpClient.post<ApiEnvelope<AuthenticatedUser>>('/api/v1/login', input)
  return response.data.data
}

export async function signOut() {
  await httpClient.post('/api/v1/logout')
}

export async function requestPasswordReset(email: string) {
  await startCsrfSession()
  await httpClient.post('/api/v1/forgot-password', { email })
}

export async function resetPassword(input: { token: string; email: string; password: string; password_confirmation: string }) {
  await startCsrfSession()
  await httpClient.post('/api/v1/reset-password', input)
}

export function getAuthErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status
    const body = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const firstFieldError = Object.values(body?.errors ?? {}).flat()[0]

    if (status === 429) return 'Se hicieron demasiados intentos. Espera un momento y vuelve a probar.'
    if (status === 419) return 'La sesión de seguridad venció. Recarga la página e inténtalo de nuevo.'
    if (status && status >= 500) return 'El servicio no está disponible en este momento. Inténtalo más tarde.'
    if (firstFieldError) return firstFieldError
    if (body?.message) return body.message
    if (!error.response) return 'No pudimos conectar con JURISSIM. Revisa que el backend esté iniciado.'
  }

  return 'Ocurrió un error inesperado. Vuelve a intentarlo.'
}
