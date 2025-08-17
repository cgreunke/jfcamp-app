// src/api/workshops.js

async function req(url) {
  const res = await fetch(url, {
    headers: { Accept: 'application/vnd.api+json' },
    credentials: 'omit',
  })
  if (!res.ok) {
    const body = await res.text().catch(() => '')
    const err = new Error(`Workshops HTTP ${res.status} ${res.statusText} – ${body.slice(0, 300)}`)
    err.status = res.status
    err.body = body
    err.url = url
    throw err
  }
  return res.json()
}

// Liefert veröffentlichte Workshops als flache Items (mit Fallback-Strategie).
export async function fetchWorkshops() {
  const tries = [
    // vollen Wunsch (mit ext_id) …
    '/jsonapi/node/workshop?filter[status]=1&fields[node--workshop]=title,field_ext_id&sort=title&page[limit]=500',
    // falls ext_id-Feld (noch) fehlt …
    '/jsonapi/node/workshop?filter[status]=1&fields[node--workshop]=title&sort=title&page[limit]=500',
    // ohne fields (Server wählt Standardfelder)
    '/jsonapi/node/workshop?filter[status]=1&sort=title&page[limit]=500',
    // ohne Filter (zur Not alles)
    '/jsonapi/node/workshop?sort=title&page[limit]=500',
    '/jsonapi/node/workshop?page[limit]=500',
  ]

  let lastErr
  for (const url of tries) {
    try {
      const { data } = await req(url)
      return (data ?? []).map(d => ({
        id: d.id,                              // JSON:API UUID (für Auswahl & POST)
        title: d.attributes?.title ?? '(ohne Titel)',
        // extId ist „nice to have“ – kann fehlen, ohne das UI zu blockieren:
        extId: d.attributes?.field_ext_id ?? null,
      }))
    } catch (e) {
      lastErr = e
      // versuche nächste Variante
    }
  }
  throw lastErr || new Error('Workshops: Keine Variante erfolgreich')
}
