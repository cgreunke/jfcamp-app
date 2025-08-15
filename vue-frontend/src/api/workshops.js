export async function fetchWorkshops() {
  const res = await fetch('http://localhost:8080/jsonapi/node/workshop')
  if (!res.ok) throw new Error('Workshops konnten nicht geladen werden')
  const data = await res.json()
  return data.data || []
}
