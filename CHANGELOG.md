# 📦 Changelog – JF Startercamp App

Alle relevanten Änderungen und Releases im Überblick.  
Versionierung folgt **SemVer** (MAJOR.MINOR.PATCH).

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
