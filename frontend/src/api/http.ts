import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios'

const baseURL = import.meta.env.VITE_API_URL || '/api'

const http = axios.create({
  baseURL,
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

let sanctumCsrfPromise: Promise<void> | null = null

function sanctumCsrfUrl(): string {
  const configured = import.meta.env.VITE_API_URL as string | undefined
  if (!configured) {
    return `${window.location.origin}/sanctum/csrf-cookie`
  }
  const root = configured.replace(/\/api\/?$/, '')

  return `${root}/sanctum/csrf-cookie`
}

function fetchSanctumCsrfCookie(): Promise<void> {
  return axios
    .get(sanctumCsrfUrl(), {
      withCredentials: true,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    .then(() => undefined)
}

function ensureSanctumCsrfForMutatingRequest(): Promise<void> {
  if (!sanctumCsrfPromise) {
    sanctumCsrfPromise = fetchSanctumCsrfCookie().catch((e) => {
      sanctumCsrfPromise = null
      throw e
    })
  }

  return sanctumCsrfPromise
}

http.interceptors.request.use(async (config) => {
  const method = (config.method ?? 'get').toLowerCase()
  if (['post', 'put', 'patch', 'delete'].includes(method)) {
    await ensureSanctumCsrfForMutatingRequest()
  }

  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

http.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const original = error.config as (InternalAxiosRequestConfig & { _retry?: boolean })
    if (error.response?.status === 419 && original && !original._retry) {
      original._retry = true
      sanctumCsrfPromise = null
      await fetchSanctumCsrfCookie()
      return http(original)
    }

    return Promise.reject(error)
  },
)

export default http
