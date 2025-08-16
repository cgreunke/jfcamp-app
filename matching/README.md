# Matching-Service (Flask + Gunicorn)

Greedy-Zuteilung von Teilnehmer:innen auf Workshops basierend auf Wünschen in Drupal.

## Endpunkte
- `GET /health` — einfacher Healthcheck
- `POST /matching/run` (auch `GET` möglich) — führt das Matching aus und schreibt Zuteilungen zurück.

## Erwartete Drupal-Entitäten (JSON:API)
- `node--matching_config` mit:
  - `field_num_wuensche` (Anzahl Wunschfelder pro Teilnehmer)
  - `field_num_zuteilung` (max. Zuteilungen pro Teilnehmer)
- `node--wunsch` mit Relationships:
  - `field_teilnehmer` → `node--teilnehmer`
  - `field_wunsch_1..N` → `node--workshop`
- `node--workshop` mit Attribut:
  - `field_maximale_plaetze`
- `node--teilnehmer` mit Relationships:
  - `field_workshop_1..M` (Ergebnisfelder)

## Konfiguration (ENV)
- `DRUPAL_URL` (z. B. `http://drupal/jsonapi`)
- `DRUPAL_TOKEN` (optional, Bearer Token)

## Lokale Tests
```bash
curl -X GET  http://localhost:5001/health
curl -X POST http://localhost:5001/matching/run
