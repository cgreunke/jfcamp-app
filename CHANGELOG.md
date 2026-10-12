# 📦 Changelog – JF Startercamp App

Alle relevanten Änderungen und Releases im Überblick.  

Versionierung folgt **SemVer** (MAJOR.MINOR.PATCH).

---

## [1.5.0] - 2026-10-12

### Added

- Expliziter Produktions-Bootstrap über `scripts/bootstrap-production.sh`.
- Reproduzierbarer Drupal-Fresh-Install direkt aus der versionierten `config/sync`-Konfiguration.
- Persistente Produktions-Volumes nur für PostgreSQL-Daten, öffentliche/private Drupal-Dateien und Caddy-Daten.
- Eigener Queue-Worker auf Basis desselben unveränderlichen Drupal-Images.
- Produktions-Build des Vue-Frontends als statisches Nginx-Image.
- Caddy als einziger öffentlich erreichbarer Reverse Proxy für HTTPS.

### Changed

- Drupal-, Matching- und Vue-Anwendungscode wird für Produktion in Images gebaut statt aus dem Host-Dateisystem gemountet.
- Composer-Abhängigkeiten werden beim Image-Build aus dem Lockfile installiert und nicht mehr beim Containerstart.
- Der Drupal-Containerstart beschränkt sich auf Runtime-Vorbereitung und Apache-Start.
- Produktionsinstallation, Datenbankupdates und API-Benutzer-Provisioning wurden aus dem normalen Containerstart in den expliziten Bootstrap verschoben.
- Aktive Drupal-Konfiguration und `config/sync` wurden für reproduzierbare Fresh Installs abgeglichen.
- Rollen und Berechtigungen werden bei Fresh Installs aus Drupal-Config geladen statt durch Install-Hooks nachträglich verändert.
- Vue/Nginx-Konfiguration wurde in den Frontend-Build verschoben.
- Docker Compose trennt DEV-spezifische Dienste und Host-Ports von der Produktionsarchitektur.

### Removed

- Persistentes Volume für das komplette Drupal-Projektverzeichnis.
- Produktions-Bind-Mounts für Drupal-Code, Config, Vendor und Vue-Build.
- Automatische Composer-, Installations-, Config-Import- und Update-Logik beim Drupal-Containerstart.
- Fallback von `site:install --existing-config` auf eine Minimalinstallation.
- Veraltete Matching-Konfigurationsfelder für historische Strategien, Seeds, Slicing und Gewichtungsparameter.
- Alte produktive Vue-Nginx-Konfiguration unter `nginx/vue-site.conf`.

### Safety

- Der Bootstrap bricht bei fehlenden oder unveränderten Platzhalter-Secrets ab.
- Drupal wird nur dann installiert, wenn der Bootstrap-Status eindeutig eine nicht installierte Site bestätigt.
- Fehler beim Ermitteln des Drupal- oder Config-Status führen zum Abbruch.
- Config-Drift zwischen Datenbank und `config/sync` wird nicht stillschweigend akzeptiert.
- Der Matching-API-Benutzer erhält die versionierte `api_writer`-Rolle; Rollenrechte werden nicht beim Produktionsstart erweitert.

### Verification

- Vollständiger Clean-Room-Fresh-Install mit leerer PostgreSQL-Datenbank erfolgreich getestet.
- `site:install --existing-config` erfolgreich.
- Keine offenen Drupal-Datenbankupdates nach Installation.
- Kein Unterschied zwischen aktiver Drupal-Konfiguration und `config/sync`.
- Wiederholter Bootstrap auf bereits installierter Instanz erfolgreich getestet.
- Drupal, PostgreSQL und Matching im finalen Stack healthy; Queue-Worker und Vue laufen.
- `/jsonapi` und Vue-Startseite erfolgreich über Caddy geprüft.
- Nur Caddy veröffentlicht Host-Ports 80/443.
- Test-Container, Netzwerke und Volumes nach dem Clean-Room-Test vollständig entfernt.

### Notes

- Für diesen Release existiert derzeit keine laufende Produktionsinstanz, die migriert werden muss.
- Ziel für die nächste Camp-Produktion ist ein neuer Server: Repository klonen, `.env.production` erstellen und `scripts/bootstrap-production.sh` ausführen.
- Historische lokale Camp-Daten sind nicht Bestandteil des Produktions-Fresh-Installs.

---

## [1.4.0] - 2026-10-12

### Added

- **JF-Camp-Dashboard** unter `/admin/jfcamp` mit Betriebskennzahlen und Schnellzugriffen.
- **Teilnehmerübersicht** mit Suche, Filtern, Zuteilungen und flexiblem CSV-Export.
- Eigene **Teilnehmerdetailseite** für Wünsche und Slot-Zuteilungen.
- **Mobile Teamer*innen-Ansicht** für die eigenen Regionalverbände.
- Rolle **Teamer*in** mit serverseitig erzwungener Regionalbeschränkung.
- **Teamverwaltung** durch die Camp-Leitung inklusive Regionalverbands-Zuordnung.
- **Manuelle Wunschbearbeitung** durch die Camp-Leitung.
- **Manuelle Zuteilungsbearbeitung** mit Kapazitäts- und Konsistenzprüfung.
- **Kapazitätssicherer 1:1-Tausch** für volle Workshops ohne Überbuchung.
- **Änderungsprotokoll/Audit** für manuelle Zuteilungs- und Wunschänderungen.
- **Workshop- und Kapazitätsübersicht** je Slot.
- Eigene JF-Camp-Breadcrumbs, vereinheitlichte Admin-Routen und app-artigere Login-/Arbeitsoberfläche.

### Changed

- Rolle `team` wird in der Oberfläche als **Camp-Leitung** bezeichnet.
- Berechtigungen der Camp-Leitung wurden auf die tatsächlich benötigten JF-Camp-Funktionen reduziert.
- Teilnehmerzugriff wird auch bei direktem Node-Zugriff serverseitig nach Regionalverband abgesichert.
- Matching-Oberfläche und Matching-Modell wurden auf einen klaren operativen Workflow reduziert.
- Matching-Runs bleiben als unveränderliche Snapshots erhalten; produktive Änderungen erfolgen erst über **Apply**.
- Manuelle Änderungen arbeiten auf dem aktuellen produktiven Zuteilungsstand und verändern keinen Matching-Snapshot.
- JF-Camp-Routen wurden unter `/admin/jfcamp` vereinheitlicht.
- Teilnehmer-, Wunsch-, Zuteilungs- und Tauschformulare wurden für Desktop und Mobilgeräte vereinheitlicht.

### Removed

- Legacy-Matching-CSV-Exporte und zugehörige alte Routen/Controller.
- Historische allgemeine Drupal-Content-CRUD-Rechte der Camp-Leitung, die für den Campbetrieb nicht benötigt werden.

### Security

- Regionale Sichtbarkeit für Teamer*innen wird serverseitig durchgesetzt.
- Teamer*innen erhalten keine Rechte für Wunschbearbeitung, Zuteilungsänderungen, Matching, Audit, Kapazitäten oder Teamverwaltung.
- Manuelle Zuteilungen und Tausche verwenden Locking, Transaktionen, Kapazitätsprüfung und Persistenzprüfung.
- Volle Workshops können nicht direkt überbucht werden.

### Notes

- Produktive Zuteilungen liegen weiterhin in `teilnehmer.field_zugewiesen`; die Feldreihenfolge entspricht den Slots.
- Wunschänderungen lösen kein automatisches Re-Matching aus.
- Ein späteres Matching-Apply kann manuelle produktive Zuteilungen durch den gespeicherten Run-Snapshot ersetzen und muss deshalb bewusst ausgeführt werden.
- Vor dem Produktionsdeployment: vollständiges Backup, `updb`, Cache-Rebuild und Smoke-Tests.

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
