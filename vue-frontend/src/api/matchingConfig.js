let _cfgPromise = null

export async function loadMatchingConfig(forceReload = false) {
  if (!forceReload && _cfgPromise) return _cfgPromise
  _cfgPromise = _load()
  return _cfgPromise
}

async function req(url) {
  const res = await fetch(url, {
    headers: { Accept: 'application/vnd.api+json' },
    credentials: 'omit',
  })
  if (!res.ok) {
    const body = await res.text().catch(() => '')
    const err = new Error(`JSON:API ${res.status} ${res.statusText} – ${body.slice(0, 300)}`)
    err.status = res.status
    err.body = body
    err.url = url
    throw err
  }
  return res.json()
}

async function _load() {
  const tries = [
    '/jsonapi/node/matching_config?filter[pub][condition][path]=status&filter[pub][condition][value]=1&sort=-changed&page[limit]=1',
    '/jsonapi/node/matching_config?filter[pub][condition][path]=status&filter[pub][condition][value]=1&page[limit]=1',
    '/jsonapi/node/matching_config?page[limit]=1&sort=-changed',
    '/jsonapi/node/matching_config?page[limit]=1',
  ]
  let lastErr
  for (const url of tries) {
    try {
      const { data } = await req(url)
      if (Array.isArray(data) && data.length) {
        const item = data[0]
        const a = item.attributes ?? {}
        return {
          id: item.id,
          title: a.title ?? 'Matching',
          numWuensche: Number(a.field_num_wuensche ?? 3),
          numZuteilung: Number(a.field_num_zuteilung ?? 3),
        }
      }
    } catch (e) {
      lastErr = e
    }
  }
  console.warn('[matchingConfig] Fallback wegen Fehler:', lastErr?.message)
  return { title: 'Matching (Fallback)', numWuensche: 3, numZuteilung: 3 }
}
