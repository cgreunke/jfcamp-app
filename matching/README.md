# JF Camp – Matching-Service

Der Matching-Service berechnet die Workshop-Zuteilungen für das JugendFEIER-Startercamp.

Er liest **Teilnehmende**, **Workshops**, **Wünsche** und die grundlegende Matching-Konfiguration aus Drupal und erzeugt daraus eine valide Zuteilung über die konfigurierten Slots.

## Ziel des Matchings

Die aktuelle Matching-Logik verfolgt diese Prioritäten:

1. Jede Person erhält die konfigurierte Anzahl an Workshop-Zuteilungen.
2. Kapazitäten und weitere harte Bedingungen werden eingehalten.
3. Möglichst viele und möglichst hoch priorisierte Wünsche werden erfüllt.
4. Füll-Zuteilungen werden erst verwendet, wenn die Wunschoptimierung ausgeschöpft ist.
5. Wenn nicht alle Wünsche gleichzeitig erfüllbar sind, soll die Verteilung fair bleiben.

Die produktive Zuteilung darf keinen Workshop innerhalb derselben Person doppelt enthalten.

## Datenbasis

Aus Drupal werden insbesondere verwendet:

- `teilnehmer`
- `workshop`
- `wunsch`
- `matching_config`

Ein Wunsch-Node verweist auf genau einen Teilnehmer und enthält in `field_wuensche` die geordnete Wunschliste.

Die produktive Zuteilung eines Teilnehmers liegt in:

```text
field_zugewiesen
```

Die Delta-Reihenfolge des Feldes entspricht der Slot-Reihenfolge.

## Wichtige Endpunkte

Der Matching-Service stellt weiterhin technische Endpunkte für Status und Berechnung bereit, darunter:

- `GET /matching/health`
- `GET /matching/stats`
- `POST /matching/dry-run`

Ein Dry-Run verändert den produktiven Drupal-Zuteilungsstand nicht.

## Matching-Runs in Drupal

Die eigentliche operative Steuerung erfolgt über Drupal.

Ein Run durchläuft vereinfacht diesen Ablauf:

```text
Input erfassen
    ↓
Matching berechnen
    ↓
Run als Snapshot speichern
    ↓
Run prüfen
    ↓
optional: Apply
```

Abgeschlossene Runs werden in `jfcamp_matching_run` gespeichert.

Ein Run enthält unter anderem:

- Status
- verwendete Konfiguration
- Zusammenfassung
- vollständigen Snapshot
- Ergebnis-Hash
- Input-Fingerprint
- Apply-Status
- Zeitpunkte und Benutzerinformationen

## Snapshot und Apply

Ein abgeschlossener Run ist ein historischer, unveränderlicher Snapshot.

Wichtig:

**Ein berechneter Run verändert die produktiven Zuteilungen nicht.**

Erst **Apply** schreibt den gespeicherten Snapshot in die produktiven `field_zugewiesen`-Werte.

Dadurch sind Berechnung und produktiver Stand bewusst getrennt.

## Manuelle Änderungen nach dem Matching

Nach einem Apply kann die Camp-Leitung:

- Wünsche nachträglich ändern
- einzelne Zuteilungen ändern
- kapazitätssichere Tausche durchführen

Diese Änderungen verändern den produktiven Stand, aber nicht den gespeicherten Matching-Snapshot.

Deshalb gilt:

> Ein späteres Apply eines älteren oder bereits berechneten Runs kann manuelle Änderungen überschreiben.

Vor Apply muss deshalb immer geprüft werden, ob der aktuelle produktive Stand bereits manuell angepasst wurde.

## Kapazitäten

Das Matching muss Workshop-Kapazitäten pro Slot einhalten.

Auch die nachträgliche manuelle Bearbeitung in Drupal verhindert Überbuchungen:

- normale Umbuchungen sind nur bei freier Zielkapazität möglich
- volle Workshops können nur über einen validierten 1:1-Tausch erreicht werden

## Konfiguration

Die grundlegende Matching-Konfiguration liegt in Drupal im Inhaltstyp `matching_config`.

Relevant sind insbesondere:

- Anzahl der berücksichtigten Wünsche
- Anzahl der Zuteilungen/Slots
- Slot-Zeiten für die Darstellung

Historische Algorithmus-Parameter und mehrere auswählbare Matching-Strategien sind nicht mehr Teil des normalen operativen Workflows.

## Betrieb

### Healthcheck

```bash
curl http://localhost:5001/matching/health
```

### DEV

Der Matching-Service wird über Docker Compose gemeinsam mit der übrigen Anwendung betrieben.

Änderungen am Python-Matching-Code benötigen in der lokalen Entwicklungsumgebung in der Regel einen Rebuild des Matching-Containers.

## Sicherheit

Die Matching-Oberfläche in Drupal ist auf entsprechend berechtigte Benutzer beschränkt.

Die Rolle **Camp-Leitung** darf Matching-Runs starten und anwenden.

Die Rolle **Teamer*in** besitzt keinen Matching-Zugriff.
