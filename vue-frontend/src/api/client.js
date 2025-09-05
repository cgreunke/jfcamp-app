// src/api/client.js
const API_BASE = import.meta.env.VITE_API_BASE || '/api'

// Welche Statuscodes wir automatisch erneut versuchen:
const RETRY_STATUSES = new Set([429, 503, 502, 504])

function sleep(ms) { return new Promise(r => setTimeout(r, ms)) }

function parseRetryAfter(header) {
  if (!header) return 0
  const s = header.trim()
  const sec = Number(s)
  if (Number.isFinite(sec)) {
    return Math.max(0, sec * 1000)
  }
  const date = Date.parse(s)
  if (!Number.isNaN(date)) {
    const diff = date - Date.now()
    return Math.max(0, diff)
  }
  return 0
}

/**
 * HTTP-Wrapper mit Backoff.
 * @param {'GET'|'POST'|'PUT'|'PATCH'|'DELETE'} method
 * @param {string} path
 * @param {any} body
 * @param {{signal?: AbortSignal}} [opts]
 */
async function http(method, path, body, { signal } = {}) {
  const maxAttempts = 5
  let attempt = 0

  while (true) {
    attempt++
    let res

    try {
      res = await fetch(`${API_BASE}${path}`, {
        method,
        headers: body ? { 'Content-Type': 'application/json' } : undefined,
        body: body ? JSON.stringify(body) : undefined,
        credentials: 'same-origin',
        signal,
      })
    } catch (err) {
      // Netzwerkfehler: retry (außer explizit abgebrochen)
      if (err?.name === 'AbortError') throw err
      if (attempt < maxAttempts) {
        const base = Math.pow(2, attempt) * 250
        await sleep(base + Math.floor(Math.random() * 200))
        continue
      }
      throw err
    }

    const ct = res.headers.get('content-type') || ''
    const isJson = ct.includes('application/json')
    const payload = isJson ? await res.json().catch(() => ({})) : await res.text()

    if (res.ok) {
      return isJson ? payload : { ok: true, data: payload }
    }

    // Backpressure/temporäre Fehler: warten & erneut versuchen
    if (RETRY_STATUSES.has(res.status) && attempt < maxAttempts) {
      const retryAfterMs = parseRetryAfter(res.headers.get('retry-after'))
      const base = retryAfterMs || Math.pow(2, attempt) * 250
      const jitter = Math.floor(Math.random() * 200)
      await sleep(base + jitter)
      continue
    }

    // Fehler werfen (inkl. Nutzlast)
    const message = isJson
      ? (payload.error || payload.message || JSON.stringify(payload))
      : (payload || res.statusText)

    const error = new Error(`HTTP ${res.status}: ${message}`)
    error.status = res.status
    error.data = payload
    throw error
  }
}

export const api = {
  getConfig: (opts) => http('GET', '/config', undefined, opts),
  postWunsch: (payload, opts) => http('POST', '/wunsch', payload, opts),
  getZuweisungen: (codeOrParams, opts) => {
    const code = typeof codeOrParams === 'string' ? codeOrParams : codeOrParams?.code
    return http('GET', `/zuweisungen?code=${encodeURIComponent(code || '')}`, undefined, opts)
  },
  getSlots: (opts) => http('GET', '/slots', undefined, opts),
  getMyWishes: (code, opts) => http('GET', `/wunsch?code=${encodeURIComponent(code)}`, undefined, opts),
}

export default api
