// Teilnehmer-ID (UUID) per Code (eigene API)
export async function fetchTeilnehmerIdByCode(code) {
  const cleaned = String(code || '').trim()
  if (!cleaned) return null

  const res = await fetch('/jfcamp/teilnehmer-id', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'omit',
    body: JSON.stringify({ code: cleaned }),
  })

  if (res.status === 404) return null
  if (!res.ok) throw new Error(`Teilnehmer-ID HTTP ${res.status}`)

  const json = await res.json().catch(() => ({}))
  return json?.ok ? json.id : null
}
