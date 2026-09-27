export interface AuthenticatedUser {
  id: number
  name: string
  email: string
  roles: string[]
}

export interface ApiEnvelope<T> {
  data: T
}
