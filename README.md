# JF Startercamp App

Die **JF Startercamp App** unterstützt die Organisation des **JugendFEIER-Startercamps** – von Workshop-Wünschen über das Matching bis zur operativen Arbeit der Camp-Leitung und der Teamer*innen.

Die Anwendung besteht aus:

- **Drupal** als Datenbasis und operative Verwaltungsoberfläche
- **Vue 3** für die teilnehmerbezogenen Frontend-Funktionen
- **Python-Matching-Service** für die Workshop-Zuteilung
- **PostgreSQL** als Datenbank
- **Docker Compose** für Entwicklung und Produktion

## Architektur

```text
[ Vue Frontend ]
       |
       v
[ Drupal + JF-Camp-Administration ] <----> [ Matching-Service ]
       |
       v
[ PostgreSQL ]
```

## Drupal / JF-Camp-Administration

Der zentrale Arbeitsbereich befindet sich unter:

```text
/admin/jfcamp
```

Wichtige Custom-Module:

- `jfcamp_admin` – Dashboard, Navigation, Breadcrumbs und Admin-UI
- `jfcamp_operations` – Teilnehmende, Wünsche, Zuteilungen, Audit und Kapazitäten
- `jfcamp_matching` – Matching-Runs, Snapshots und Apply/Reset
- `jfcamp_team` – Teamer*innen-Verwaltung
- `jfcamp_api` – interne API-Funktionen
- `jfcamp_public_api` – Public API für das Vue-Frontend

Zentrale Inhaltstypen:

- `teilnehmer`
- `workshop`
- `wunsch`
- `matching_config`

Die produktiven Workshop-Zuteilungen werden am Teilnehmer in `field_zugewiesen` gespeichert. Die Reihenfolge der Werte entspricht den Workshop-Slots.

## Rollen

### Camp-Leitung

Die Drupal-Rolle `team` wird in der Oberfläche als **Camp-Leitung** angezeigt.

Sie kann unter anderem:

- alle Teilnehmenden und Regionalverbände sehen
- Wünsche bearbeiten
- Zuteilungen manuell ändern
- kapazitätssichere Tausche durchführen
- Matching-Runs starten und anwenden
- Workshop-Kapazitäten prüfen
- das Änderungsprotokoll einsehen
- CSV-Daten importieren
- Teamer*innen verwalten

### Teamer*in

Die Rolle `teamer` erhält eine reduzierte, mobile Arbeitsoberfläche.

Teamer*innen können:

- nur Teilnehmende ihrer zugewiesenen Regionalverbände sehen
- erkennen, wer noch keine Wünsche abgegeben hat
- Workshop-Zuteilungen ihrer Teilnehmenden nachschlagen

Teamer*innen können keine Wünsche oder Zuteilungen verändern und haben keinen Zugriff auf Matching, Audit, Kapazitäten oder Teamverwaltung.

## Teilnehmerübersicht und Detailansicht

Die Teilnehmerübersicht bietet:

- Suche nach Name und Code
- Filter
- Zuteilungen je Slot
- flexible CSV-Exporte
- regionale Einschränkung für Teamer*innen

Die eigene Teilnehmerdetailseite zeigt:

- Stammdaten
- Wunschreihenfolge
- Zuteilungen je Slot
- operative Aktionen für die Camp-Leitung

Direkte Teilnehmerzugriffe werden serverseitig nach Regionalverband abgesichert.

## Wünsche bearbeiten

Die Camp-Leitung kann die Workshop-Wünsche eines Teilnehmers nachträglich bearbeiten.

Dabei gilt:

- Wunschreihenfolge bleibt eindeutig
- doppelte Workshops werden verhindert
- Wunschänderungen verändern bestehende Zuteilungen **nicht automatisch**
- Änderungen werden protokolliert

## Manuelle Zuteilungen

Die Camp-Leitung kann einzelne Slot-Zuteilungen ändern.

Vor dem Speichern werden unter anderem geprüft:

- gültiger veröffentlichter Workshop
- gültiger Slot
- vollständiger Teilnehmer-Zuteilungsstand
- keine doppelte Workshop-Zuteilung für dieselbe Person
- verfügbare Kapazität im Zielslot

Änderung und Audit-Eintrag werden transaktional gespeichert.

## Volle Workshops und Tausche

Volle Workshops bleiben sichtbar, können aber nicht direkt überbucht werden.

Stattdessen steht ein **kapazitätssicherer 1:1-Tausch** zur Verfügung:

1. Teilnehmer A soll in einen vollen Workshop wechseln.
2. Eine kompatible Person B aus diesem Workshop und Slot wird ausgewählt.
3. Beide neuen Zuteilungen werden gemeinsam validiert.
4. Beide Änderungen werden innerhalb einer Transaktion gespeichert.
5. Bei einem Fehler wird der komplette Vorgang zurückgerollt.

Dadurch wird keine Überbuchung zugelassen.

## Matching

Der Matching-Service erstellt die Workshop-Zuteilungen auf Basis von Teilnehmenden, Workshops, Wünschen und Kapazitäten.

Die aktuelle Zielsetzung ist:

- alle Teilnehmenden erhalten die konfigurierte Anzahl an Slots
- möglichst viele und möglichst hoch priorisierte Wünsche werden erfüllt
- Kapazitäten und weitere harte Bedingungen werden eingehalten
- Füll-Zuteilungen werden erst nach der Wunschoptimierung verwendet
- das Ergebnis soll bei Zielkonflikten fair bleiben

Ein abgeschlossener Matching-Run wird in Drupal als unveränderlicher Snapshot gespeichert.

Wichtig:

```text
Matching berechnen
        ↓
gespeicherter Snapshot
        ↓
Apply
        ↓
produktive Zuteilungen in field_zugewiesen
```

Ein berechneter Run überschreibt die produktiven Zuteilungen **nicht automatisch**.

Erst **Apply** schreibt den gespeicherten Snapshot in den produktiven Zuteilungsstand.

Manuelle Änderungen nach einem Apply verändern den produktiven Stand, nicht den historischen Matching-Snapshot. Ein späteres erneutes Apply kann solche manuellen Änderungen wieder durch den Snapshot ersetzen und muss deshalb bewusst ausgeführt werden.

Weitere Details: [matching/README.md](matching/README.md)

## Änderungsprotokoll

Manuelle Änderungen werden protokolliert.

Das Audit enthält unter anderem:

- Benutzer
- Zeitpunkt
- Teilnehmer
- Slot bzw. Wunschänderung
- vorheriger und neuer Workshop
- Bezug zur Matching-Basis, soweit vorhanden

## Workshops und Kapazitäten

Die Camp-Leitung kann die Belegung je Workshop und Slot prüfen.

Die Übersicht zeigt unter anderem:

- Kapazität
- aktuelle Belegung
- freie Plätze
- volle Slots
- Überbuchungen

## Teamverwaltung

Die Camp-Leitung kann Teamer*innen direkt in der JF-Camp-Oberfläche verwalten.

Möglich sind:

- Benutzername
- Startpasswort bei Anlage
- Aktiv/Gesperrt
- Zuweisung zu einem oder mehreren Regionalverbänden

E-Mail-Adressen sind für diesen Workflow nicht erforderlich.

## Vue-Frontend

Das Vue-Frontend dient den teilnehmerbezogenen Funktionen, insbesondere:

- Wünsche abgeben
- eigene Wünsche anzeigen
- eigene Workshop-Zuteilungen anzeigen

In DEV läuft das Frontend über Vite. In PROD wird ein statisches Build über Nginx ausgeliefert.

## Entwicklung und Betrieb

### DEV starten

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

### PROD starten

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

### Drush in DEV

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml \
  exec drupal vendor/bin/drush <command>
```

### Drupal-Updates

Nach einem Deployment:

```bash
vendor/bin/drush updb -y
vendor/bin/drush cr
```

Die Befehle müssen im Drupal-Container bzw. im dafür vorgesehenen Produktionskontext ausgeführt werden.

## Config-Management

- DEV: `drush cex -y`
- PROD: `drush cim -y`
- Secrets gehören nicht ins Repository.

## Release

Aktueller Release-Zweig: **v1.4.0**

Details siehe [CHANGELOG.md](CHANGELOG.md).

## Smoke-Tests nach Deployment

Mindestens prüfen:

1. Login
2. `/admin/jfcamp`
3. Teilnehmerübersicht
4. Teilnehmerdetailseite
5. Teamer*innen-Sicht und Regionalbeschränkung
6. Wunschbearbeitung durch Camp-Leitung
7. manuelle Zuteilungsänderung
8. Tauschworkflow bei vollem Workshop
9. Workshop-Kapazitäten
10. Änderungsprotokoll
11. Teamverwaltung
12. Matching-Status
13. keine überbuchten Workshop-Slots
