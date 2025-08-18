# JFCamp App

Containerisierte Anwendung für das JugendFEIER-Camp mit **Drupal (Headless)**, **Vue 3 + Vite** und einem optionalen **Matching-Service (Python)**. 
Teilnehmer geben im Frontend ihre Workshop-Wünsche ab; Drupal speichert Inhalte und stellt **JSON:API** + eine kleine **Custom-API** bereit. 
CSV-Import für Workshops/Teilnehmer/Wünsche ist integriert.

---

## Architektur

```
[ Vue Frontend ] ──(REST)──▶ [ Drupal JSON:API + Custom API ] ──▶ [ Postgres ]
                                 │
                                 ├── Admin-CSV-Import (im Drupal-Backend)
                                 └── (optional) ──▶ [ Matching-Service (Python) ]
```

- **Vue**: Wunschformular ohne Klarnamen, Anmeldung via Teilnehmer‑Code.
- **Drupal**: Content-Model (*Teilnehmer, Workshop, Wunsch, Matching-Config*), speichert Daten in **Postgres**, liefert JSON:API und Custom-Endpoints.
- **Matching (optional)**: Python holt Daten via JSON:API (Basic Auth) und schreibt Zuteilungen zurück.
- **Adminer**: DB-GUI für lokale Entwicklung.
- **CSV-Import**: Backend-Formular zum Importieren von Teilnehmern/Workshops/Wünschen.

---

## Verzeichnisstruktur (vereinfachte Ansicht)

```
jfcamp-app/
├─ docker-compose.yml
├─ csv-examples/                    # Beispiel-CSV-Dateien (Teilnehmer/Workshops/Wünsche)
├─ drupal/
│  ├─ Dockerfile
│  ├─ start-drupal.sh               # installiert/konfiguriert Drupal + legt Bundles/Felder/Displays an
│  └─ web/
│     ├─ modules/
│     │  └─ custom/
│     │     └─ jfcamp_api/
│     │        ├─ jfcamp_api.info.yml
│     │        ├─ jfcamp_api.routing.yml
│     │        ├─ jfcamp_api.links.menu.yml        # Menüpunkt für CSV-Import
│     │        ├─ src/
│     │        │  ├─ Controller/WunschController.php
│     │        │  └─ Form/CsvImportForm.php        # CSV-Import-Formular
│     │        └─ scripts/
│     │           └─ ensure_displays.php           # setzt Form-/View-Displays (Drupal 11-kompatibel)
│     └─ sites/default/
│        ├─ settings.php
│        ├─ cors.local.yml                         # CORS für das Vue-Dev-Frontend
│        └─ (settings.local.php)                   # lokal (gitignored, optional)
├─ vue-frontend/
│  ├─ .env.example
│  ├─ .env.development                             # lokal, gitignored
│  ├─ vite.config.js
│  └─ src/
│     ├─ api/
│     │  ├─ matchingConfig.js
│     │  ├─ workshops.js
│     │  ├─ teilnehmer.js
│     │  └─ wunsch.js
│     └─ components/
│        └─ WunschForm.vue
└─ matching/ (optional, Python)
   ├─ Dockerfile
   ├─ requirements.txt
   ├─ matching_server.py
   └─ README.md
```

---

## Schnellstart

### Voraussetzungen
- Docker & Docker Compose
- Node 18+ (für lokales Vite-Dev, falls außerhalb des Containers genutzt)

### Starten
```bash
docker compose up -d --build
```

**Lokale Endpoints (Standard-Ports):**
- **Drupal**: `http://localhost:8080`
- **Vue Dev-Server**: `http://localhost:5173`
- **Adminer (DB GUI)**: `http://localhost:8081`
- **Matching-Service** (optional): `http://localhost:5001` (Proxy auf Gunicorn:5000)

> Tipp: Logs ansehen: `docker compose logs -f drupal` bzw. `... matching`

---

## Drupal – Automatisierte Einrichtung

Die Datei **`drupal/start-drupal.sh`** übernimmt beim Containerstart:

1. **Warten auf Postgres** (psql vorhanden).
2. **Installation** von Drupal (Standard-Profil) auf **Deutsch** (`--locale=de`).
3. Aktiviert **jsonapi**, **basic_auth**, **dblog**, **field_ui**; setzt `jsonapi.settings: read_only = 0`.
4. Legt Inhaltstypen + Felder **idempotent** an:
   - **workshop**: `field_maximale_plaetze` (Integer), `field_ext_id` (optional)
   - **teilnehmer**: `field_code`, `field_vorname`, `field_name`, `field_regionalverband`, `field_zugewiesen` (Ref → *workshop*, mehrfach)
   - **wunsch**: `field_teilnehmer` (Ref → *teilnehmer*), `field_wuensche` (Ref → *workshop*, mehrfach, Reihenfolge = Priorität)
   - **matching_config**: `field_num_wuensche`, `field_num_zuteilung` (z. B. 5 / 3)
5. Setzt **Form-/View-Displays** über `scripts/ensure_displays.php` (Drupal‑11‑kompatibel).
6. Erstellt **API-Rolle** (`api_writer`) mit Rechten:
   - `access content`
   - `edit any teilnehmer content`
   - `access user profiles` (optional)
7. Erstellt **API-User** (`apiuser`/`apipassword`) und weist Rolle zu.
8. Vergibt Recht **„import jfcamp csv“** an **administrator** und **api_writer**.
9. Löscht/füllt Caches.

> Ergebnis: **Felder sind im Backend-Formular sichtbar**, JSON:API ist schreibbar, und der CSV‑Import steht bereit.

---

## CSV-Import (Backend)

- Menü: **Konfiguration → Entwicklung → JFCamp CSV‑Import**  
  (Route: `/admin/config/jfcamp-import`)
- Unterstützt **Teilnehmer**, **Workshops** und **Wünsche** (Upsert).  
- Beispiel-Dateien: im Ordner **`csv-examples/`** (im Repo).

### CSV-Formate (Kurzform)
**Teilnehmer (`teilnehmer.csv`)**
```
code,vorname,name,regionalverband
TST001,Max,Schneider,Berlin
...
```

**Workshops (`workshops.csv`)**
```
titel,max_plaetze,ext_id
Hip-Hop,12,WS001
...
```

**Wünsche (`wuensche.csv`)** — referenziert Teilnehmer per Code, Workshops per Titel oder ext_id
```
code,w1,w2,w3,w4,w5
TST001,Hip-Hop,Theater,Klettern, ...
...
```

> Der Import ist idempotent (Upsert) und kürzt die Anzahl der Wünsche auf `matching_config.field_num_wuensche`.

---

## Custom-API (für das Frontend)

Modul **`jfcamp_api`** stellt bereit:

### 1) Teilnehmer-ID anhand Code
```
POST /jfcamp/teilnehmer-id
Content-Type: application/json

{ "code": "ABC123" }
```
Antwort:
```json
{ "ok": true, "id": "UUID-DES-TEILNEHMERS" }
```

### 2) Wunsch abgeben (Upsert)
```
POST /jfcamp/wunsch
Content-Type: application/json

{
  "code": "ABC123",
  "workshop_ids": ["<WS-UUID-1>", "<WS-UUID-2>", "..."]
}
```
- dedupliziert automatisch
- kürzt auf `field_num_wuensche`
- legt genau **einen** Wunsch-Node pro Teilnehmer an/aktualisiert ihn

Fehlerfälle: `400` (Validierung), `403` (Code ungültig), `500` (fehlende Felder).  
Routen sind für anonyme Nutzer freigeschaltet (`_access: TRUE`).

---

## Vue-Frontend

### Env-Variablen (Vite)
`vue-frontend/.env.example`
```env
# Wird im Code via import.meta.env.VITE_DRUPAL_BASE gelesen
VITE_DRUPAL_BASE=http://localhost:8080
```

> **.env.development** (lokal, **gitignored**) überschreibt `.env` im Dev-Mode und **wird automatisch verwendet**.  
> Nur Variablen mit **`VITE_`**-Präfix sind im Frontend verfügbar.

### API-Calls (bereitgestellt)
- `src/api/matchingConfig.js`: lädt die aktuelle Matching-Konfiguration per JSON:API (published, newest)
- `src/api/workshops.js`: lädt veröffentlichte Workshops
- `src/api/teilnehmer.js`: findet Teilnehmer‑UUID per `field_code` (JSON:API)
- `src/api/wunsch.js`: postet an **Custom-API** `/jfcamp/wunsch` (kein CSRF nötig)

### Wunschformular
`src/components/WunschForm.vue`
- Zeigt **N** Dropdowns gemäß `field_num_wuensche`
- Verhindert doppelte Auswahl
- Validiert „Code vorhanden“ und „mind. 1 Workshop“
- Funktioniert mit CORS (**`cors.local.yml`**) oder Dev‑Proxy in `vite.config.js`

---

## (Optional) Matching-Service (Python)

- Holt **Teilnehmer**, **Workshops**, **Wünsche** via JSON:API (Basic Auth: `apiuser:apipassword`)
- Berechnet Zuteilungen (greedy; Algorithmus frei erweiterbar)
- Schreibt Ergebnis als Referenzen in `field_zugewiesen` beim **Teilnehmer**

**Konfiguration (ENV im Compose):**
```
DRUPAL_URL=http://drupal/jsonapi
# optional: DRUPAL_TOKEN (nicht genutzt, da Basic Auth)
```

**Endpoints**
- `GET  /health` – Healthcheck
- `POST /matching/dry-run` – Matching simulieren, Ergebnis nicht schreiben
- `POST /matching/run` – Matching durchführen und Zuteilungen PATCHen

**Beispiele**
```bash
# Dry-Run (host):
curl -X POST http://localhost:5001/matching/dry-run | jq

# Schreiben (host):
curl -X POST http://localhost:5001/matching/run | jq
```

---

## CORS (lokal)

`drupal/web/sites/default/cors.local.yml`
```yaml
cors.config:
  enabled: true
  allowedHeaders: ['x-csrf-token','content-type','accept','origin','authorization']
  allowedMethods: ['GET','POST','PATCH','DELETE','OPTIONS']
  allowedOrigins: ['http://localhost:5173']
  exposedHeaders: false
  maxAge: 1000
  supportsCredentials: true
```
> Nach Änderungen: `drush cr`

---

## Troubleshooting

- **Felder im Formular nicht sichtbar**  
  Displays setzen:  
  `drush php:script web/modules/custom/jfcamp_api/scripts/ensure_displays.php && drush cr`

- **JSON:API PATCH = 405 (Method Not Allowed)**  
  `drush cset -y jsonapi.settings read_only 0` und Rolle hat `edit any teilnehmer content`.

- **JSON:API 500**  
  `vendor/bin/drush ws --severity=3 --count=50`, dann `drush cr`.  
  Felder/Content-Typen & `jfcamp_api` prüfen.

- **CORS-Probleme**  
  `cors.local.yml` prüfen (Origin/Header/Methods, `supportsCredentials: true`) / Dev‑Proxy verwenden.

- **private:// nicht eingerichtet**  
  `settings.php` + Ordnerrechte (siehe Startscript) und `drush cr`.

- **Route nicht gefunden (/jfcamp/...)**  
  `drush cr`, dann `drush r:list | grep jfcamp_api`.

---

## Aktueller Stand

- ✅ **Felder** sind im Backend sichtbar (Form-/View-Displays gesetzt).
- ✅ **CSV-Import** funktioniert (Beispiele unter `csv-examples/`).
- ✅ **Frontend-Wunschformular** funktioniert end‑to‑end gegen Custom-API.
- ✅ **Matching-Service Dry-Run** liefert sinnvolle Vorschau; **Run** schreibt Zuteilungen.

---

## Lizenz

Internes Projekt (Lizenz nach Bedarf ergänzen).
