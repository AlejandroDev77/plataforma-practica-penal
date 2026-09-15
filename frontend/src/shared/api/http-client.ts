import axios from 'axios'
import { env } from '../config/env'

export const httpClient = axios.create({
  baseURL: env.VITE_API_URL,
  headers: {
    Accept: 'application/json',
  },
  withCredentials: true,
  withXSRFToken: true,
  timeout: 15_000,
})
