# JFCamp App

Containerisierte Anwendung für das JugendFEIER-Camp mit **Drupal (Headless)**, **Vue 3 + Vite** und einem optionalen **Matching-Service (Python)**.  
Teilnehmer geben im Frontend ihre Workshop-Wünsche ab; Drupal speichert Inhalte und stellt JSON:API + eine kleine **Custom-API** bereit.  
Das Matching-Script verteilt Teilnehmer fair auf Workshop-Slots.

---

## Architektur

```
[ Vue Frontend ]  <->  [ Drupal JSON:API + Custom-API ]  <->  [ Matching-Service (Python) ]
          |                        |
          |                        +-- [ Postgres + Adminer ]
          |
       (Nginx Proxy)
```

**Dienste:**
- **Drupal**: Headless CMS, Content-Typen für Teilnehmer, Workshops, Wünsche, Matching Config.  
- **Matching**: Python/Flask-Service, führt das Matching durch (nach Prioritäten und Kapazitäten).  
- **Vue**: Frontend (Vue 3 + Vite), konsumiert JSON:API und Matching-Endpunkte.  
- **Postgres**: Datenbank für Drupal.  
- **Adminer**: GUI zum DB-Management.  

---

## Inhaltstypen in Drupal

- **Workshop**
  - `title` (Name des Workshops)
  - `field_maximale_plaetze` (Kapazität pro Slot)
  - optional: `field_ext_id`

- **Teilnehmer**
  - `field_code` (eindeutiger Teilnehmer-Code)
  - `field_vorname`, `field_name`
  - `field_regionalverband`
  - `field_zugewiesen` (Referenzen zu Workshops nach Matching)

- **Wunsch**
  - `field_teilnehmer` (Referenz auf Teilnehmer)
  - `field_wuensche` (geordnete Liste gewünschter Workshops, Priorität 1–N)

- **Matching Config**
  - `field_num_wuensche` (maximale Wünsche pro TN, z. B. 5)
  - `field_num_zuteilung` (Anzahl Slots, z. B. 3)

---

## CSV-Import (im Backend)

Unter **/admin/config/jfcamp/csvimport** gibt es ein UI zum Hochladen.  
Unterstützte Formate (UTF-8, Komma oder Semikolon):

- Workshops:  
  ```
  title,max
  HipHop Tanzworkshop,40
  Graffiti,10
  ...
  ```

- Teilnehmer:  
  ```
  code,vorname,nachname,regionalverband
  T001,Max,Muster,Barnim
  T002,Lisa,Lustig,Oberhavel
  ...
  ```

- Wünsche:  
  ```
  code,w1,w2,w3,w4,w5
  T001,HipHop Tanzworkshop,Graffiti,Spoken Word,Batiken,Schauspiel
  T002,Graffiti,Social Media,Mini Quiz-Show,HipHop Tanzworkshop,Ultimate Frisbee
  ...
  ```

Hinweise:
- Wünsche können per **Workshop-UUID** oder **exaktem Titel** eingetragen werden.  
- Pro Teilnehmer existiert genau **ein Wunsch-Node**.  
- Wünsche werden auf die in `matching_config` hinterlegte Anzahl gekürzt.

---

## Matching-Service

**Endpoints:**
- `POST /matching/dry-run` → Simulation, Rückgabe einer **Summary** mit Statistik
- `POST /matching/run` → Führt Matching aus, speichert Zuweisungen in Drupal

**Beispiel Dry-Run:**
```powershell
Invoke-RestMethod -Method Post -Uri http://localhost:5001/matching/dry-run | Select-Object -ExpandProperty summary
```

Ergebnisse:
- `all_filled_to_slots` → alle TN haben alle Slots gefüllt
- `participants_no_wishes` → wie viele TN keine Wünsche abgegeben haben
- `per_priority_fulfilled` → wie viele Zuteilungen je Priorität erfüllt wurden
- `filler_assignments` → automatisch verteilte Plätze, wenn keine Wünsche vorhanden
- `capacity_total` → Summe der Kapazitäten × Slots
- `capacity_remaining_total` → Restkapazität nach Matching

---

## Nützliche Drush-Befehle (im Drupal-Container)

```bash
# Cache leeren
docker exec -it drupal bash -lc '/opt/drupal/vendor/bin/drush cr'

# Alle Workshops löschen
docker exec -it drupal bash -lc '/opt/drupal/vendor/bin/drush entity:delete node --bundle=workshop -y'

# Alle Teilnehmer löschen
docker exec -it drupal bash -lc '/opt/drupal/vendor/bin/drush entity:delete node --bundle=teilnehmer -y'

# Alle Wünsche löschen
docker exec -it drupal bash -lc '/opt/drupal/vendor/bin/drush entity:delete node --bundle=wunsch -y'
```

---

## Testdaten

Zum Testen können über CSV ca. **250 Teilnehmer** und alle **Workshops (mit Kapazitäten)** importiert werden.  
Für Wünsche gibt es entweder:
- Zufalls-CSV (`wuensche.csv`), die allen Teilnehmern automatisch 5 Workshops zuordnet.  
- oder automatischen Insert per Script (erstellt Wunsch-Nodes für alle ohne Wünsche).

---

## Config Management

- Alle aktiven Drupal-Konfigurationen werden im Repo gespeichert: `drupal/config/sync/`
- Export:  
  ```bash
  docker exec -it drupal vendor/bin/drush cex -y
  ```
- Import (z. B. auf Prod):  
  ```bash
  docker exec -it drupal vendor/bin/drush cim -y
  docker exec -it drupal vendor/bin/drush cr
  ```

---

## ToDos / Next Steps

- Auswertung im Backend:
  - Liste „Teilnehmer ohne Wünsche“
  - Export: Workshops je Slot mit zugeteilten TN
  - Export: Übersicht je Regionalverband mit allen zugewiesenen Workshops
- Matching-Algorithmus weiter optimieren:
  - faire Verteilung bei knappen Kapazitäten
  - Reporting, welche Wünsche wie oft erfüllt wurden
