# JF Camp – Matching-Service

Dieser Service liest **Teilnehmende**, **Workshops** und **Wünsche** aus Drupal (JSON:API) und erstellt pro Dry‑Run eine faire Zuteilung über **Slots** (z. B. 3 Slots). Berücksichtigt werden **Prioritäten** (Wünsche), Kapazitäten und optionale Anti‑Renner‑Regeln (*Slicing*). Ein **Happy‑Index** fasst die Erfüllungsqualität zusammen.

## TL;DR – So nutzt ihr’s

1. Drupal vorbereiten: `start-drupal.sh` im **Drupal‑Container** ausführen.  
   - Admin‑UX: Gin, Admin Toolbar, 403→Login, Frontpage→/admin/content  
   - Bundles/Felder: `workshop`, `teilnehmer`, `wunsch`, `matching_config`  
   - Rollen & Rechte: `team` (CRUD + CSV/Maching), `api_writer` (Service), `administrator`

2. Daten einspielen (CSV‑Importer oder manuell):  
   - **Workshops** mit `Maximale Plätze`  
   - **Teilnehmer:innen** mit `Code`, `Vorname`, `Nachname`, `Regionalverband`  
   - **Wünsche**: pro Teilnehmer genau **ein** `wunsch`‑Knoten, Feld `Wünsche` = geordnete Referenzen auf Workshops (Priorität 1..N)

3. Matching aufrufen:
   - `GET /matching/health` – Service up?
   - `GET /matching/stats` – Überblick: Kapazitäten, Wunschdichte, TopK‑Popularität.  
   - `POST /matching/dry-run` – Zuteilung simulieren (es wird **nichts** nach Drupal geschrieben).

## Endpunkte

- `GET /matching/health` – ok
- `GET /matching/debug` – Rohzähler, Sanity Checks
- `GET /matching/probe` – Stichprobe/IDs, Duplikate
- `GET /matching/probe/missing` – count fehlender Wunschlisten
- `GET /matching/stats` – **TopK‑Popularität**, Kapazitäten, Histogramme
- `POST /matching/dry-run` – Matching-Ergebnis inkl. **Happy‑Index**

## Konfiguration (Drupal: Inhaltstyp **matching_config**)

| Feld | Bedeutung |
|---|---|
| **Anzahl Wünsche** (`field_num_wuensche`) | Wie viele Wünsche (Prioritäten) je Person berücksichtigt werden. |
| **Anzahl Zuteilungen (Slots)** (`field_num_zuteilung`) | Anzahl Workshop‑Slots (z. B. 3). |
| **Seed** (`field_seed`) | Fixiert die zufällige Rotationsreihenfolge. Leer = echter Zufall. |
| **Top‑K = Slots** (`field_topk_equals_slots`) | Wenn aktiv, ist **TopK = Slots**, sonst `min(Slots, Anzahl Wünsche)`. |
| **Slicing Mode** (`field_slicing_mode`) | `off`, `relative` (z. B. 50 %/Slot), `fixed` (absolute Zahl pro Slot). Wirkt **nur** bei „Rennern“. |
| **Slicing Value** (`field_slicing_value`) | Prozent (relative) oder absolute Zahl (fixed). |
| **Gewichte Prio 1..5** (`field_weight_p1..p5`) | Bewertung pro Priorität für den **Happy‑Index**. |

## Matching-Logik

- **Ziel**: Jede Person erhält pro Slot **genau einen** Workshop, **ohne Wiederholung**. Kapazitäten werden eingehalten.
- **Prioritäten**: Wünsche werden in Reihenfolge 1..N versucht.
- **Balanced‑Rotation**: Pro Slot wird die Teilnehmerreihenfolge rotiert → fairere Verteilung.
- **Slicing**: „Renner“ (Top 20 % nach Nachfrage) werden pro Slot gedeckelt:
  - `off`: keine Deckelung  
  - `relative`: z. B. 50 % von Kapazität/Slot  
  - `fixed`: absolute Anzahl pro Slot  
- **Filler**: Wenn kein Wunsch greift, wird ein freier Workshop zugeteilt (aber nie doppelt für dieselbe Person).

## Happy‑Index

- Für Top‑K (meist = Slots) prüft der Service, ob die Slot‑Zuteilungen in den **Top‑K Wünschen** liegen.
- Je Treffer wird die jeweilige **Prioritäts‑Gewichtung** addiert (1:1.0, 2:0.8, 3:0.6, …).  
- Der **Happy‑Index** ist der Mittelwert über alle Teilnehmenden, normalisiert auf 0..1.

**Beispiel:** Slots=3, Gewichte (1:1.0, 2:0.8, 3:0.6).  
- Person A erhält Prio1, Prio2, Prio3 → Score = 1.0+0.8+0.6 = 2.4 → normiert (÷2.4) = 1.0  
- Person B erhält Prio1, Filler, Prio3 → Score = 1.0+0.0+0.6 = 1.6 → normiert ≈ 0.667

## Seed vs. Zufall

- **Zufall** wird für die faire **Reihenfolge** genutzt (Shuffling, Rotation).  
- **Seed** fixiert diese Zufallsquelle → das gleiche Input führt deterministisch zum gleichen Ergebnis.  
- Kein Seed = jedes Mal eine neue, valide Verteilung (gleich gute Qualität, aber andere Zuordnung).

## Tipps zur Qualität

- Wenn es **wenige Renner** gibt, aktiviere **Slicing (relative 50 %)** und prüfe `/matching/dry-run` → Happy‑Index sollte steigen.  
- Achte darauf, dass **alle** Teilnehmenden **genügend** Wünsche eingeben (idealerweise ≥ Slots).  
- Verteile Kapazitäten möglichst proportional zur Popularität (siehe `/matching/stats` → Popularität & Kapazität).

## Environment

- `DRUPAL_URL` (z. B. `http://drupal/jsonapi`)
- `DRUPAL_LANGS` (z. B. `de,en`)
- `PUBLISHED_ONLY` (`1|0`)
- `PAGE_CHUNK` (Default 100)
- `MATCHING_SEED` (Fallback, wenn in matching_config kein Seed)

## Sicherheit / Rollen

- **administrator**: Vollzugriff
- **team**: CRUD auf Kernobjekte + CSV‑Import & Matching starten
- **api_writer**: Technischer Nutzer für Serviceintegration (minimal erforderliche Rechte)

