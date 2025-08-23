# JF Startercamp App

Containerisierte Web-App zur Organisation des **JugendFEIER-Startercamps**.  
Headless-**Drupal** liefert Inhalte & Public-APIs, **Vue 3** (Vite + Vuetify) bildet das Frontend. Ein optionaler **Flask-Matching-Service** übernimmt das faire Matching. Betrieb via **Docker Compose** mit klarer DEV/PROD-Trennung.

Ziel (MVP): **Wünsche abgeben → Matching auslösen → Workshops mit Zeit & Raum anzeigen.**

---

## 🚀 Architektur

```
[ Vue (Vite) ]  <--->  [ Drupal JSON:API & Custom Public API ]  <--->  [ Matching-Service (Flask) ]
       |                           |                                   |
       v                           v                                   v
     Browser   <--------------->  Nginx (PROD)  <------------------> Postgres
```

**Entscheidungen (Stand 2025-08-23):**
- **Slots & Zeiten** liegen global in der Matching-Konfiguration (Node-Typ `matching_config`), **nicht** am Workshop.
- **Anzahl Slots** (`field_num_zuteilung` oder `field_num_zuweisungen`).
- **Räume/Ort** gehören **zum Workshop** → Feld `field_room` (Text).
- **Externe ID** pro Workshop → `field_ext_id` (z. B. „W01“).
- **Kurzbeschreibung** pro Workshop → `field_kurzbeschreibung` (vorbereitet; aktuell nicht im Frontend angezeigt).

---

## 📦 Projektstruktur (vereinfacht)

```
jfcamp-app/
├── drupal/
│   ├── config/sync/               # Drupal-Config (drush cex/cim)
│   ├── web/modules/custom/
│   │   ├── jfcamp_public_api/     # Öffentliche API (siehe Endpoints unten)
│   │   └── jfcamp_api/            # Admin (CSV-Import u. a.)
│   ├── scripts/                   # init-drupal.sh, ensure-bundles.php, jf-roles.sh
│   └── web/                       # Docroot
├── matching/                      # (optional) Flask-Service
├── nginx/                         # Nginx für PROD-Frontend
├── src/                           # Vue 3 Frontend (Vite, Vuetify 3.8.x)
│   ├── components/, pages/, views/, api/
│   └── main.js, router.js, ...
├── docker-compose.yml
├── docker-compose.dev.yml
├── docker-compose.prod.yml
├── .env.development
├── .env.production
└── README.md
```

> Hinweis: Frühere Repos nutzten ein Unterverzeichnis `vue-frontend/`. Aktuell liegt das Vue-Frontend direkt unter `src/`.

---

## ⚙️ Komponenten

- **Drupal (Headless CMS)**
  - JSON:API + **Custom Public API** (`jfcamp_public_api`)
  - Config-Management via `drupal/config/sync` (DEV: `drush cex`, PROD: `drush cim`)
  - Wichtige Node-Typen: `workshop`, `teilnehmer`, `wunsch`, `matching_config`
- **Vue 3** (Vite, Vuetify 3.8.x)
  - Seiten: „Wünsche abgeben“, „Meine Wünsche“, „Meine Workshops“
  - Dezente Top-Navigation, mobil als Drawer
- **Matching-Service (Flask)**
  - Endpunkte: `/matching/run|dry-run|stats|health` (optional für später)
- **Postgres**

---

## 🧰 Compose-Modi & Start

### Entwicklung (DEV)

```bash
# Reset (optional)
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v --remove-orphans
docker image prune -f

# Start
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build

# Drupal einmalig initialisieren (nur beim ersten Mal/Reset)
docker compose exec drupal bash /opt/drupal/scripts/init-drupal.sh

# Frontend (Host): Vite-Dev-Server mit Hot-Reload
npm ci
npm run dev          # läuft i. d. R. auf http://localhost:5173
```

### Produktion (PROD)

```bash
# Frontend builden
npm ci
npm run build        # erzeugt dist/

# Compose starten
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# Drupal Config importieren & Cache leeren
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal vendor/bin/drush cim -y
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal vendor/bin/drush cr -y
```

**Drush-Shortcuts (DEV):**
```bash
docker compose exec drupal drush cr -y       # Cache leeren
docker compose exec drupal drush cex -y      # Config export (ins Repo)
# (auf PROD): docker compose exec drupal drush cim -y
```

---

## 🌐 Public API (MVP)

| Endpoint | Methode | Beschreibung |
|---|---|---|
| `/api/wunsch` | **POST** | Wünsche speichern. Body: `{ code: string, wuensche: string[] }` – Werte sind Workshop-UUIDs (oder Titel). |
| `/api/wunsch?code=…` | **GET** | Wünsche lesen (priorisierte Liste). Antwort: `{ ok, wuensche: [{ id, ext_id, title }] }`. |
| `/api/zuweisungen?code=…` | **GET** | Zuweisungen eines Teilnehmers. Antwort: `{ ok, zuweisungen: [{ slot_index, workshop: { id, ext_id, title, room } }] }`. |
| `/api/config` | **GET** | Frontend-Config: `{ ok, max_wishes, workshops: [{ id, ext_id, title }] }`. |
| `/api/slots` | **GET** | Slot-Zeiten aus `matching_config`: `{ num_zuweisungen, slots: [{ index, start, end }] }`. |

**Hinweise:**
- `matching_config` verwendet je nach Instanz `field_num_zuteilung` **oder** `field_num_zuweisungen` für die Slot-Anzahl. Der Controller ist robust und leitet die Anzahl bei Bedarf aus den Zeitlisten ab.
- Zeiten sind `HH:MM` (24-h).

**Schnelltest per curl:**
```bash
BASE=http://localhost:8080
curl -s $BASE/api/config | jq .
curl -s "$BASE/api/wunsch?code=CODE100" | jq .
curl -s "$BASE/api/zuweisungen?code=CODE100" | jq .
curl -s $BASE/api/slots | jq .
```

---

## 🧑‍💻 Frontend (Auszüge)

- **Wünsche abgeben** (`WishForm.vue`): dynamische Dropdowns (keine Doppelwahl); Anzeige „**Wxx · Titel**“ mittels `ext_id` aus `/api/config`.
- **Meine Wünsche** (`MyWishesView.vue`): listet gespeicherte Wünsche „**Wxx · Titel**“.
- **Meine Workshops** (`WorkshopView.vue`): gruppiert nach Slot; zeigt **Zeit** (aus `/api/slots`), **Raum** (vom Workshop `field_room`) und „**Wxx · Titel**“. Kurzbeschreibung ist vorgesehen, wird derzeit **nicht** angezeigt.

---

## 📥 CSV-Import (Workshops)

**Admin-Pfad:** `/admin/config/jfcamp/csv-import` (Modul `jfcamp_api`).

**Empfehlungen:**
- Exportiere am besten „**CSV (Semikolon-getrennt)**“ oder wähle im Formular `;` als Trennzeichen.
- Minimal benötigte Spalten:  
  - `title` (Workshop-Titel)  
  - `field_ext_id` (Externe ID, z. B. `W01`) – wird bei Bedarf automatisch generiert  
  - `field_room` (Raum/Ort)  
  - optional `status` (=1 veröffentlicht)  
- Optional vorbereitet (derzeit nicht im FE):  
  - `field_kurzbeschreibung` (Text). **Achtung**, bei formatierten Textfeldern erwarten manche Importe `.../value` und `.../format` (z. B. `plain_text`).

**Troubleshooting:**  
Fehler *„array_combine(): keys und values müssen gleich lang sein“* → CSV-Trennzeichen stimmt nicht oder Zeilen enthalten „lose“ Kommas/Strichpunkte. Verwende die bereitgestellten Minimal-CSV-Vorlagen oder stelle das Trennzeichen passend ein.

**Alle Workshops löschen (Neuimport vorbereiten):**
```bash
docker compose exec drupal drush php:eval '
$ids=\Drupal::entityQuery("node")->accessCheck(FALSE)->condition("type","workshop")->execute();
if($ids){$st=\Drupal::entityTypeManager()->getStorage("node");$st->delete($st->loadMultiple($ids));echo "Gelöscht: ".count($ids)." Workshops\n";} else {echo "Keine Workshops gefunden\n";}
'
```

---

## 🧱 Datenmodell (Kernfelder)

**Workshop**
- `title` – Titel
- `field_ext_id` – Externe ID (z. B. W01)
- `field_room` – Raum/Ort
- `field_kurzbeschreibung` – Kurzbeschreibung (optional, aktuell nicht im FE)

**Teilnehmer**
- `field_code` – anonymer Code (z. B. CODE100)
- `field_zugewiesen` – Mehrfach-Referenz auf `workshop` (Delta = Slot-Index)

**Wunsch**
- `field_teilnehmer` – Referenz auf Teilnehmer
- `field_wuensche` – Mehrfach-Referenz auf `workshop` **oder** `field_wuensche_json` (Fallback)

**Matching-Konfiguration (`matching_config`)**
- `field_num_zuteilung` **oder** `field_num_zuweisungen` – Anzahl Slots
- `field_slot_start` (mehrwertig, `HH:MM`)
- `field_slot_end` (mehrwertig, `HH:MM`)

---

## 🔄 Config-Workflow

- **DEV**: Änderungen an Bundles/Feldern → `drush cex -y` → committen.
- **PROD**: deploy → `drush cim -y` → `drush cr -y`.

---

## 📝 Changelog & Versionierung

- Siehe `CHANGELOG.md`. Aktuelle Version: **v1.0.0** (2025-08-23).
- Taggen:
  ```bash
  git tag v0.2.0
  git push --tags
  ```

---

## 🤝 Lizenz & Beiträge

Interne Projektbasis. PRs/Issues welcome (Repo-Richtlinien beachten).
