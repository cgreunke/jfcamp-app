export async function absendenWunsch(code, teilnehmerId, wuensche) {
  const rel = {
    field_teilnehmer: {
      data: { type: 'node--teilnehmer', id: teilnehmerId }
    }
  }
  wuensche.forEach((w, i) => {
    if (w?.id) {
      rel[`field_wunsch_${i + 1}`] = { data: { type: 'node--workshop', id: w.id } }
    }
  })

  const body = {
    data: {
      type: 'node--wunsch',
      attributes: { title: `Wunsch von ${code}` },
      relationships: rel
    }
  }

  const res = await fetch('http://localhost:8080/jsonapi/node/wunsch', {
    method: 'POST',
    headers: { 'Content-Type': 'application/vnd.api+json' },
    body: JSON.stringify(body)
  })
  if (!res.ok) throw new Error(await res.text())
}
