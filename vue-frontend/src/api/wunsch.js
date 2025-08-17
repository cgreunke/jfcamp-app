// Wünsche anonym über eigene API abgeben
export async function absendenWunsch({ code, teilnehmerId, workshopIds }) {
  // Hinweis: teilnehmerId wird serverseitig aus 'code' geprüft – wir senden sie nicht extra.
  const payload = {
    code: String(code || '').trim(),
    workshop_ids: (workshopIds || []).filter(Boolean).map(String),
  }

  const res = await fetch('/jfcamp/wunsch', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'omit',
    body: JSON.stringify(payload),
  })

  const text = await res.text()
  let json = {}
  try { json = JSON.parse(text) } catch {}

  if (!res.ok || !json.ok) {
    throw new Error(json?.error || `Wunsch HTTP ${res.status}`)
  }
  return json.wunsch_uuid || ''
}
