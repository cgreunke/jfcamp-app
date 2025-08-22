import { API_BASE } from '@/config/apiConfig'

export async function submitWishes(code, wuensche) {
  const res = await fetch(`${API_BASE}/api/wunsch`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code, wuensche }),
  })
  let data = null
  try { data = await res.json() } catch (_) {}
  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || `Fehler ${res.status}`)
  }
  return data // { ok: true }
}

export async function getAssignments(code) {
  const url = new URL(`${API_BASE}/api/zuweisungen`, window.location.origin)
  url.searchParams.set('code', code)
  const res = await fetch(url.toString())
  const data = await res.json().catch(() => null)
  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || `Fehler ${res.status}`)
  }
  return data.zuweisungen || []
}
