// src/api/client.js
const API_BASE = import.meta.env.VITE_API_BASE || '/api'

async function http(method, path, body) {
  const res = await fetch(`${API_BASE}${path}`, {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  })
  const ct = res.headers.get('content-type') || ''
  const isJson = ct.includes('application/json')
  const data = isJson ? await res.json() : await res.text()

  if (!res.ok) {
    const message = isJson ? (data.error || JSON.stringify(data)) : (data || res.statusText)
    throw new Error(message || `HTTP ${res.status}`)
  }
  return data
}

export const api = {
  getConfig: () => http('GET', '/config'),
  postWunsch: (payload) => http('POST', '/wunsch', payload),
  getZuweisungen: (codeOrParams) => {
    const code = typeof codeOrParams === 'string' ? codeOrParams : codeOrParams?.code
    return http('GET', `/zuweisungen?code=${encodeURIComponent(code || '')}`)
  },
  getSlots: () => http('GET', '/slots'),
}
