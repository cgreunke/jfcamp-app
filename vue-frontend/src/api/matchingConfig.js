export async function fetchMatchingConfig() {
  const res = await fetch('http://localhost:8080/jsonapi/node/matching_config')
  if (!res.ok) throw new Error('Matching-Konfiguration konnte nicht geladen werden')
  const data = await res.json()
  return data?.data?.[0]?.attributes || null
}
