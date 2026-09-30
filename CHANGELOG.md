# 📦 Changelog – JF Startercamp App

Alle relevanten Änderungen und Releases im Überblick.  
Versionierung folgt **SemVer** (MAJOR.MINOR.PATCH).

---
## [1.3.0] - 2026-09-30

### Security
- **Drupal Core 11.4.7**: Sicherheitsupdate von 11.2.3 → 11.4.7 (kritische Patches)
- Alle Drupal-Dependencies aktualisiert

### Changed
- **Gin Admin Theme**: 3.0.0 → 5.x (Kompatibilität mit Drupal 11.4)
- **Admin Toolbar**: 3.6.2 → 3.6.3

### Added
- **Corporate Design Update**:
  - Neue Primärfarbe: `#232f5e` (dunkles Blau)
  - Neue Logos: HRN-Logo, Happy-Human_jf.png
  - Design-Anpassungen in Vue Frontend (overrides.css, Vuetify-Config)
- Vue Production Build-Prozess optimiert (statisches Nginx-Serve)

### Fixed
- Vue Frontend dist-Build mit aktuellen Design-Änderungen
- Docker Compose Konfiguration für PROD-Umgebung


## [1.2.0] - 2025-09-05
### Baseline
- CPX11-Profil fixiert: **Nginx 12/24**, **MPM 20**.
- Repro Lasttest (20 VUs / 60s) mit Backpressure:
  - p95(200) ≈ 1008 ms, 200=0 %, 429=0 %, 5xx=0 % (Messdatei: docs/capacity/summary.cpx11.v1.2.0.json).
- CAPACITY_GUIDELINE.md hinzugefügt (Vorgehen für Skalierung & Tests).

## [1.1.4] - 2025-09-05
### Changed 
- Apache-Backpressure (MaxRequestWorkers) und Timeouts.

## [1.1.1] - 2025-09-05
### Added
- API-Drossel: Flood-Limits für POST /api/wunsch (pro IP + pro Teilnehmer-Code) → 429 + Retry-After.
- Frontend: automatischer Retry/Backoff bei 429/503/502/504.
- Web: Apache-Backpressure (MaxRequestWorkers) und Timeouts.
- PHP: OPcache aktiviert/konfiguriert; Assertions für PHP 8.4 deaktiviert.

### Fixed
- Stabilität unter Peak-Last (Warten statt 502/Timeouts).


## [1.1.0] - 2025-08-25

### Added
- **Matching-Service:**
  - Neue Strategien: `fair` (Mehr-Runden mit Deckel & Benachteiligten-Prio) und `solver` (leximin-orientiert).
  - Erweiterte Metriken: `gini_dissatisfaction`, `jain_index`, `top1_coverage`, `no_topk_rate`.
  - Flexible Gewichtung: manuell per JSON oder automatisch via `weights_mode` (`linear`/`geometric`).
- **Drupal Dashboard (Matching):**
  - Parameter-Form mit Inline-Hilfetexten und Tooltips für alle Matching-Optionen.
  - Dry-Run mit frei einstellbaren Parametern (Strategy, Objective, Seeds, Deckel, Alpha, Gewichte).
  - Letzter Dry-Run speichert `last_params`; „Run“ nutzt exakt diese Parameter → reproduzierbar.
- **Client/Controller:**
  - `MatchingClient` unterstützt Payload-POSTs für `/matching/dry-run`.
  - `MatchingRunController` zieht `last_params` aus State.
  - Dashboard zeigt Kennzahlen (Happy-Index, Median, Gini, Jain, Coverage) übersichtlich an.

### Changed
- `docker-compose.dev.yml`: Anpassungen für neuen Matching-Service.
- Nur noch Kernfelder (`field_num_wuensche`, `field_zuteilung`, Slot-Zeiten) in `matching_config` relevant – restliche Tuning-Parameter laufen über das Dashboard.

### Notes
- DEV/PROD-Kompatibilität bleibt bestehen.
- Empfehlung: alte Felder in `matching_config` können nach Tests entfernt werden (Config-Export/Import).loc

---

## [1.0.1] - 2025-08-24
### Fixed
- Footer überdeckte Inhalte auf Formularseiten – Vuetify-Footer nicht mehr als `app`, zusätzliche Abstände in `<v-main>`.

### Added
- Rechtstexte: **Impressum** und **Datenschutzerklärung** (LV Berlin‑Brandenburg KdöR) als schlanke Vue‑Views.

---

## [1.0.0] - 2025-08-24

### Added
- **CSV-Import** speichert jetzt auch optionale Felder `field_ext_id`, `field_room` und `field_kurzbeschreibung`.
- **Admin-Kategorie „JF Startercamp“** im Drupal-Backend, bündelt:
  - Matching-Einstellungen
  - Matching-Dashboard
  - CSV-Import
- **Frontend (Wünsche):** Workshops im Dropdown nach `ext_id` aufsteigend sortiert; Workshops ohne `ext_id` werden alphabetisch nach Titel angehängt.

### Changed
- Workshop-Labels im Frontend einheitlich als `EXT_ID · Titel`.

### Fixed
- CSV-Import robust gegenüber Header-Varianten (`ext_id` oder `field_ext_id` usw.).
- Konsistente Menülinks für alle Custom-Module.

### Notes
- Erster stabiler Release (**v1.0.0**) – die App ist funktionsfähig im geplanten MVP-Umfang:
  - Wünsche abgeben
  - Matching auslösen
  - Zuweisungen mit Zeit & Raum anzeigen

---

## [0.2.0] - 2025-08-23

### Added
- **Seite „Meine Wünsche“** (`/meine-wuensche`) mit Code-Eingabe und Anzeige der gespeicherten Wünsche.
- **Dezente, responsive Top-Navigation** (mobil per Drawer).
- **API:** `GET /api/slots` liefert Slot-Zeiten (`index`, `start`, `end`) aus der `matching_config`.
- **API:** `GET /api/zuweisungen?code=…` liefert `workshop.{ext_id,title,room}` + `slot_index`.
- **API:** `GET /api/config` liefert Workshops inkl. `ext_id` (für Dropdown „Wxx · Titel“).
- **API:** `GET /api/wunsch?code=…` (Wünsche lesen) – mit `ext_id` je Wunsch.
- **Frontend (Wünsche):** dynamische Dropdowns (keine Doppelwahl), Anzeige „Wxx · Titel“.
- **Frontend (Meine Workshops):** zeigt je Slot **Zeit** (aus `/api/slots`), **Raum** (vom Workshop) und **„Wxx · Titel“**.

### Changed
- Navigation in die App-Bar integriert; Buttons kompakter & dezenter.

### Fixed
- `WunschController::read()` – `referencedEntities()` korrekt aufgerufen (`->` statt `.`), 500er beim Lesen behoben.
- `SlotsController` robuster gegenüber Feldvarianten (u. a. `field_num_zuteilung`), Zeiten auf `HH:MM` normalisiert.

### Notes
- Keine Breaking Changes; bestehende Endpoints bleiben erhalten, wurden erweitert.

---

## [0.1.0] – 2025-08-21

### Added
- Erste stabile Baseline mit Config-Management.
- DEV & PROD Setup via Docker Compose.
- Init-Scripts (`init-drupal.sh`, `ensure-bundles.php`, `jf-roles.sh`).
- Env-Beispiele für DEV & PROD.
- README mit Setup-Anleitung.

### Notes
- API-User wird über Config angelegt (DEV/PROD).
- DEV = bind mount, PROD = Volume.
