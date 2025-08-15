export async function fetchTeilnehmerIdByCode(code) {
  const url = `http://localhost:8080/jsonapi/node/teilnehmer?filter[field_code]=${encodeURIComponent(code)}`
  const res = await fetch(url)
  if (!res.ok) throw new Error('Teilnehmer konnte nicht geladen werden')
  const data = await res.json()
  return data.data?.[0]?.id || null
}
