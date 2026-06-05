// Single API client for the existing PHP endpoints (/api/user/*, /api/admin/*).
// Uses the session cookie (same-origin) and the CSRF token exposed by
// /api/user/me. No backend changes required.

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

function redirectToLogin(): never {
  window.location.href = '/login'
  // Halt the current flow; the navigation is async.
  throw new ApiError(401, 'Unauthenticated')
}

let csrfToken: string | null = null
let csrfPromise: Promise<string> | null = null

async function parseError(res: Response): Promise<string> {
  try {
    const body = await res.json()
    if (body && typeof body.error === 'string') return body.error
  } catch {
    /* ignore */
  }
  return `Request failed (${res.status})`
}

export async function apiGet<T = unknown>(path: string): Promise<T> {
  const res = await fetch(path, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  if (res.status === 401) redirectToLogin()
  if (!res.ok) throw new ApiError(res.status, await parseError(res))
  return res.json() as Promise<T>
}

/** Bootstrap (and cache) the session CSRF token from /api/user/me. */
export async function getCsrfToken(): Promise<string> {
  if (csrfToken) return csrfToken
  if (!csrfPromise) {
    csrfPromise = apiGet<{ csrf_token: string }>('/api/user/me')
      .then((me) => {
        csrfToken = me.csrf_token
        return csrfToken
      })
      .catch((e) => {
        csrfPromise = null
        throw e
      })
  }
  return csrfPromise
}

export function primeCsrfToken(token: string) {
  csrfToken = token
}

async function send<T>(path: string, method: string, body?: unknown): Promise<T> {
  const token = await getCsrfToken()
  const res = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-Token': token,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  if (res.status === 401) redirectToLogin()
  if (!res.ok) throw new ApiError(res.status, await parseError(res))
  // Some endpoints return empty bodies.
  const text = await res.text()
  return (text ? JSON.parse(text) : {}) as T
}

export const apiPost = <T = unknown>(path: string, body?: unknown) => send<T>(path, 'POST', body)
export const apiPut = <T = unknown>(path: string, body?: unknown) => send<T>(path, 'PUT', body)
export const apiDelete = <T = unknown>(path: string, body?: unknown) => send<T>(path, 'DELETE', body)

/** Multipart upload (import). CSRF travels as a form field, matching the PHP endpoint. */
export async function apiUpload<T = unknown>(path: string, form: FormData): Promise<T> {
  const token = await getCsrfToken()
  form.set('csrf_token', token)
  const res = await fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    body: form,
  })
  if (res.status === 401) redirectToLogin()
  if (!res.ok) throw new ApiError(res.status, await parseError(res))
  const text = await res.text()
  return (text ? JSON.parse(text) : {}) as T
}

/** Step-up reauthentication used by sensitive admin actions. */
export async function reauth(password: string): Promise<void> {
  await apiPost('/api/admin/reauth', { password })
}
