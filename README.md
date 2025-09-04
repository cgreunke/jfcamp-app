# JF Startercamp App

Die **JF Startercamp App** ist eine containerisierte Webanwendung zur Organisation des **JugendFEIER-Startercamps**.  
Sie kombiniert **Drupal (Headless CMS)**, ein **Vue 3 Frontend** und einen **Python-Matching-Service**.  
Alle Komponenten laufen in **Docker-Containern** und werden über `docker compose` gesteuert.

Ziel: **Anmeldung und Zuweisung von Teilnehmenden zu Workshops** effizient, fair und transparent.

---

## 🚀 Architektur

```
[ Vue Frontend ]  <--->  [ Drupal JSON:API & Custom Public API ]  <--->  [ Matching-Service (Python) ]
       |                          |                                    |
       v                          v                                    v
     Browser   <-------------->  Nginx (optional) <----------------> Postgres
```

- **Drupal (Headless CMS)**  
  - Content-Typen: `teilnehmer`, `workshop`, `wunsch`, `zuweisung`, `matching_config`
  - Public API (Custom-Module `jfcamp_public_api`) für Wünsche, Slots und Zuweisungen
  - Admin-Bereich „JF Startercamp“ mit Matching-Dashboard, CSV-Import & Config

- **Vue 3 Frontend**  
  - DEV via Vite (`:5173`) mit Hot-Reload  
  - PROD als statisches Build via Nginx  
  - Views: Wünsche abgeben, Meine Wünsche, Meine Workshops

- **Matching-Service (Flask/Python)**  
  - Endpunkte:  
    - `POST /matching/dry-run` – Testlauf mit Parametern  
    - `GET /matching/stats` – Nachfrage & Kapazitäten  
    - Healthchecks (`/matching/health`)  
  - Strategien:  
    - **greedy** – maximale Durchschnittszufriedenheit  
    - **fair** – Mehr-Runden-Verfahren mit Renner-Deckel + Benachteiligten-Prio  
    - **solver** – leximin-orientierte Heuristik, maximiert Minimum-Zufriedenheit  
  - Metriken: Happy-Index, Min/Median, Gini, Jain, Top-k-Coverage, uvm.

---

## ⚙️ Matching-Konfiguration

- In Drupal `matching_config`-Nodes werden **nur** die Basiswerte gepflegt:
  - `field_num_wuensche` – maximale Anzahl Wünsche
  - `field_zuteilung` – Anzahl Slots/Zuteilungen pro TN
  - `field_slot_start` / `field_slot_end` – Zeiten (informativ fürs Frontend)

- Alle **Algorithmus-Parameter** (`strategy`, `objective`, `alpha_fairness`, `round_cap_pct`, `weights`, …)  
  werden **ad-hoc im Matching-Dashboard** gesetzt.

- Der letzte Dry-Run speichert die verwendeten Parameter (`last_params`) im Drupal-State →  
  Ein „Run“ verwendet genau diese Parameter → **reproduzierbar**.

---

## 📊 Matching-Dashboard

- Parameter-Form mit **Hilfetexten & Tooltips** (Strategie, Objective, Seeds, Deckel, Alpha, Gewichte …)  
- Ergebnis-Ansicht mit Kennzahlen:  
  - Happy-Index, Min/Median  
  - Gini-Index, Jain-Index  
  - Top-1-Coverage, No-Top-k-Rate  
  - Treffer pro Priorität, Zuteilungen pro Slot  
- Aktionen:  
  - Dry-Run mit gewählten Parametern  
  - Festschreiben (Batch-Apply)  
  - Zurücksetzen (Batch-Reset)  
- CSV-Exporte für Slots & Anwesenheitslisten

---

## 🛠️ Entwicklung & Betrieb

- **Docker Compose**  
  - `docker-compose.yml` – Basis Setup  
  - `docker-compose.dev.yml` – DEV-Overrides (Bind-Mounts, Hot-Reload)  
  - `docker-compose.prod.yml` – PROD-Overrides (Nginx, keine Voll-Mounts)  
- **Umgebungen**  
  - `.env.development`  
  - `.env.production`  
  - ⚠️ Keine Secrets ins Repo!

- **Config-Management (Drupal)**  
  - DEV: `drush cex -y` → Commit  
  - PROD: `drush cim -y`

---

## 📦 Release-Notes

### [1.1.0] – 2025-08-25
- **Matching:** neue Strategien (`fair`, `solver`) + erweiterte Kennzahlen  
- **Dashboard:** Parametrierung mit Hilfen/Tooltips, reproduzierbare Runs  
- **Konfig:** nur noch Kernfelder in `matching_config`, Rest ad-hoc pro Run  

Frühere Releases siehe [CHANGELOG.md](CHANGELOG.md).

---

## 🔧 Setup (Kurz)

```bash
# DEV starten
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build

# PROD starten
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Danach:
- Drupal: http://localhost:8080/
- Vue-Frontend (DEV): http://localhost:5173/
- Matching-Service: http://localhost:5001/matching/stats
