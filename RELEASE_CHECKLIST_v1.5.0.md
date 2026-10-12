# Release-Checkliste v1.5.0

## 1. Ziel des Releases

Geplanter Release: `v1.5.0`

v1.5.0 macht die Anwendung reproduzierbar auf einem neuen Produktionsserver installierbar.

Schwerpunkte:

- vollständige Drupal-Config für Fresh Installs
- unveränderliche Produktions-Images
- expliziter Produktions-Bootstrap
- klare Trennung von DEV und PROD
- reproduzierbare Installation ohne historische Camp-Daten

Für diesen Release existiert keine laufende Produktionsinstanz, die migriert werden muss.

## 2. Produktionsziel

Vorgesehener Ablauf:

```text
frischer Linux-Server
        ↓
git clone / checkout v1.5.0
        ↓
cp .env.production.example .env.production
        ↓
produktive Secrets und Domain eintragen
        ↓
sudo ./scripts/bootstrap-production.sh
        ↓
Camp-Leitung einrichten
        ↓
neue Workshops und Teilnehmende importieren
        ↓
Campbetrieb
```

## 3. Persistente Produktionsdaten

Persistiert werden nur:

- `db_data`
- `drupal_files`
- `drupal_private`
- `caddy_data`
- `caddy_config`

Nicht persistent bzw. nicht vom Host gemountet werden Drupal-Code, Composer `vendor`, `config/sync`, Custom-Module, Themes, Vue-Build und Matching-Code.

## 4. Clean-Room-Verifikation

Erfolgreich geprüft:

- Produktions-Images bauen
- PostgreSQL healthy
- `site:install --existing-config`
- Drupal vollständig installiert
- keine offenen Datenbankupdates
- Matching-API-Benutzer angelegt
- Rolle `api_writer` zugewiesen
- Drupal-Config entspricht `config/sync`
- Drupal healthy
- Matching healthy
- Queue-Worker läuft
- Vue läuft
- Caddy läuft
- `/jsonapi` über HTTPS erreichbar
- Vue-Startseite über HTTPS erreichbar
- nur Caddy veröffentlicht Ports 80/443
- erneuter Bootstrap auf installierter Site erfolgreich
- Clean-Room-Container, Netzwerk und Volumes anschließend entfernt

## 5. Fresh-Install-Sicherheit

Der Bootstrap muss unter anderem abbrechen bei:

- fehlender `.env.production`
- fehlenden Pflichtvariablen
- unveränderten `PLEASE_CHANGE_ME...`-Werten
- ungültiger Compose-Konfiguration
- nicht erreichbarer Datenbank
- Fehler beim Ermitteln des Drupal-Installationsstatus
- unerwartetem Drupal-Bootstrap-Status
- Fehlern bei Datenbankupdates
- fehlender `api_writer`-Rolle
- Fehler beim Ermitteln des Config-Status
- Config-Drift zwischen Datenbank und `config/sync`

Es gibt keinen automatischen Fallback auf eine Minimalinstallation.

## 6. Preflight

```bash
git diff --check
git status --short
git diff --stat

bash -n scripts/bootstrap-production.sh
bash -n drupal/scripts/start-drupal.sh
bash -n drupal/scripts/queue-worker.sh
bash -n drupal/scripts/jf-roles.sh
```

`.env.production` darf nicht versioniert sein.

## 7. Release-Commit

```bash
git add README.md CHANGELOG.md RELEASE_CHECKLIST_v1.5.0.md
git diff --cached --check
git diff --cached --stat
git commit -m "Prepare v1.5.0 release"
```

Danach muss `git status --short` leer sein.

## 8. Tag

```bash
git tag -a v1.5.0 -m "JF Startercamp App v1.5.0"
git show v1.5.0 --stat
```

## 9. Push

```bash
git push origin main
git push origin v1.5.0
```

## 10. Zukünftiger Produktionsserver

```bash
cp .env.production.example .env.production
```

Produktive Werte eintragen und anschließend:

```bash
sudo ./scripts/bootstrap-production.sh
```

## 11. Smoke-Tests

Mindestens prüfen:

- HTTPS und Zertifikat
- Vue-Startseite
- Drupal-Login
- `/admin/jfcamp`
- Camp-Leitung
- Teamer*in und Regionalbeschränkung
- CSV-Import
- Teilnehmerübersicht und Detailseite
- Wünsche
- manuelle Zuteilungen
- Tauschworkflow
- Kapazitätsübersicht
- Audit
- Teamverwaltung
- Matching-Status
- keine überbuchten Workshop-Slots

Beim Smoke-Test kein Matching-Apply nur zu Testzwecken durchführen.

## 12. Produktionsdaten

Historische lokale Camp-Daten werden nicht auf die neue Produktion übernommen.

Der neue Produktionsstand wird aus den Daten des kommenden Camps aufgebaut.

## 13. Abschluss

Nach erfolgreicher zukünftiger Produktionsinstallation dokumentieren:

- installierter Tag
- installierter Commit
- Server-/Domain-Bezug
- Zeitpunkt des Fresh Installs
- Ergebnis der Smoke-Tests
