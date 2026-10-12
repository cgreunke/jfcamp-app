# Release-Checkliste v1.4.0

## 1. Ausgangslage

Geplanter Release:

```text
v1.4.0
```

Begründung: Seit 1.3.0 wurden neue operative Funktionen ergänzt, ohne die grundlegende Kompatibilität als Major-Break zu verändern.

Hinweis: Im Changelog existiert `1.3.0`, im aktuellen Git-Tag-Listing fehlt jedoch ein `v1.3.0`-Tag. Keinen historischen Tag nachträglich setzen, solange der exakte Release-Commit nicht eindeutig feststeht.

## 2. Dokumentation

Für v1.4.0 aktualisieren:

- `README.md`
- `CHANGELOG.md`
- `matching/README.md`

## 3. Lokaler Preflight

```bash
git status --short
docker compose -f docker-compose.yml -f docker-compose.dev.yml   exec drupal vendor/bin/drush updatedb:status
```

Erwartet:

- sauberer Git-Arbeitsbaum
- keine offenen Drupal-Datenbankupdates

Geprüfter Datenstand vom 12.10.2026:

```text
Teilnehmende: 283
Wunsch-Nodes: 274
Zuteilungen: 849
unvollständige Teilnehmende: 0
Kapazität gesamt: 909
belegt: 849
frei: 60
überbuchte Slots: 0
```

Diese Zahlen sind ein Smoke-Test für den aktuellen Datenstand, keine allgemeine Anwendungskonfiguration.

## 4. Dokumentationsänderungen einspielen

Nach dem Kopieren der neuen Dokumentation:

```bash
git diff --check
git status --short
git diff --stat
```

Inhaltlich prüfen:

```bash
git diff -- README.md CHANGELOG.md matching/README.md
```

## 5. Release-Commit

```bash
git add README.md CHANGELOG.md matching/README.md
git diff --cached --check
git diff --cached --stat
git commit -m "Prepare v1.4.0 release"
```

Danach:

```bash
git status --short
```

## 6. Tag

Erst nach bestandenem Preflight:

```bash
git tag -a v1.4.0 -m "JF Startercamp App v1.4.0"
git show v1.4.0 --stat
```

Remote:

```bash
git push origin main
git push origin v1.4.0
```

## 7. Produktionsbackup

Vor dem Deployment:

- Datenbank sichern
- persistente Drupal-Dateien sichern
- aktuelle Produktionsrevision notieren
- aktuellen Container-/Image-Stand notieren

## 8. Deployment

Grundreihenfolge:

1. Release `v1.4.0` auschecken/deployen
2. Container bzw. Images aktualisieren
3. erforderliche Custom-Module sicherstellen
4. Drupal-Datenbankupdates ausführen
5. Cache leeren
6. nur den vorgesehenen Config-Import ausführen

Drupal:

```bash
vendor/bin/drush updb -y
vendor/bin/drush cr
```

## 9. Smoke-Tests Produktion

### Camp-Leitung

Prüfen:

- Login
- `/admin/jfcamp`
- Teilnehmerübersicht
- Teilnehmerdetailseite
- Wunschbearbeitung
- manuelle Zuteilungsänderung
- Tauschworkflow
- Workshop-Kapazitäten
- Änderungsprotokoll
- Teamverwaltung
- Matching-Seite

### Teamer*in

Prüfen:

- mobile Startseite
- nur eigene Regionalverbände
- „ohne Wünsche“
- Zuteilungen
- keine Bearbeitungsaktionen
- direkter Zugriff auf fremde Teilnehmer wird verweigert

## 10. Matching-Sicherheit

Beim Smoke-Test **kein Apply nur zum Ausprobieren** ausführen.

Ein Matching-Run verändert den produktiven Stand erst durch Apply.

Apply schreibt den gespeicherten Snapshot in die produktiven `field_zugewiesen`-Werte und kann daher spätere manuelle Änderungen überschreiben.

## 11. Abschluss

Nach erfolgreichem Deployment dokumentieren:

- deployed Tag
- deployed Commit
- Backup-Zeitpunkt
- Ergebnis der Smoke-Tests
- ggf. Release-URL
