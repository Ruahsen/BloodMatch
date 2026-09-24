let csrfToken = null

async function ensureCsrf() {
  if (csrfToken) return csrfToken
  const res = await fetch('/api/csrf', { credentials: 'include' })
  if (!res.ok) throw new Error(`Could not fetch CSRF token (${res.status})`)
  const body = await res.json()
  csrfToken = body?.data?.csrf_token
  return csrfToken
}

export function clearCsrf() {
  csrfToken = null
}

async function request(path, options = {}) {
  const method = options.method || 'GET'

  if (method !== 'GET') {
    await ensureCsrf()
    options.headers = {
      'X-CSRF-Token': csrfToken,
      ...(options.headers || {})
    }
  }

  const res = await fetch(path, {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers || {})
    },
    ...options
  })

  const contentType = res.headers.get('content-type') || ''
  const body = contentType.includes('application/json') ? await res.json().catch(() => null) : null

  if (!res.ok) {
    const message = body?.error?.message || `Request failed (${res.status})`
    const error = new Error(message)
    error.status = res.status
    error.details = body?.error?.details || {}
    throw error
  }

  return body?.data ?? null
}

export const api = {
  get: (path) => request(path),
  post: (path, data) =>
    request(path, { method: 'POST', body: JSON.stringify(data ?? {}) }),
  put: (path, data) => request(path, { method: 'PUT', body: JSON.stringify(data ?? {}) }),
  delete: (path) => request(path, { method: 'DELETE' }),
  upload: async (path, file, fields = {}) => {
    await ensureCsrf()
    const form = new FormData()
    form.append('file', file)
    Object.entries(fields).forEach(([k, v]) => form.append(k, v))
    const res = await fetch(path, {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-CSRF-Token': csrfToken },
      body: form
    })
    const contentType = res.headers.get('content-type') || ''
    const body = contentType.includes('application/json') ? await res.json().catch(() => null) : null
    if (!res.ok) {
      const message = body?.error?.message || `Upload failed (${res.status})`
      const error = new Error(message)
      error.status = res.status
      error.details = body?.error?.details || {}
      throw error
    }
    return body?.data ?? null
  }
}
