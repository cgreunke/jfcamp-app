# 📦 Changelog – JF Startercamp App

Alle relevanten Änderungen und Releases im Überblick.  
Versionierung folgt **SemVer** (MAJOR.MINOR.PATCH).

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
