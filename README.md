# JF Camp App

Die **JF Camp App** ist eine containerisierte Webanwendung für die Organisation des **JugendFEIER-Camps**.  
Sie kombiniert **Drupal (Headless CMS)**, ein **Vue 3 Frontend** und einen optionalen **Python-Matching-Service**.  
Alle Komponenten laufen in **Docker-Containern** und werden über `docker-compose` gesteuert.  

Ziel ist es, die **Anmeldung und Zuweisung von Teilnehmenden zu Workshops** möglichst effizient, fair und transparent zu gestalten.

---

## 🚀 Architekturüberblick

```
[ Vue Frontend ]  <--->  [ Drupal JSON:API ]  <--->  [ Matching-Service (Python) ]
       |                          |                            |
       v                          v                            v
     Browser   <-------------->  Nginx Proxy  <-------------> Datenbank
```

Die App besteht aus mehreren Modulen:

1. **Drupal (Backend & API)**
   - Headless CMS, das als zentrale Datenquelle dient.
   - Bereitstellung von Inhalten (Workshops, Teilnehmer, Wünsche).
   - JSON:API für den Datenaustausch.
   - Custom-Module:
     - CSV-Import (Teilnehmer, Workshops, Wünsche).
     - Verwaltung von Matching-Ergebnissen.
   - Container im Ordner `/drupal`.

2. **Vue Frontend**
   - Single Page Application mit **Vue 3 + Vite**.
   - Ermöglicht den Teilnehmenden die Eingabe und Verwaltung ihrer **Workshop-Wünsche**.
   - Kommuniziert ausschließlich über die JSON:API von Drupal.
   - Container im Ordner `/vue-frontend`.

3. **Matching-Service (Python + Flask)**
   - Implementiert in `/matching/matching_server.py`.
   - Aufgabe: Zuweisung der Teilnehmer zu Workshops anhand von:
     - **Wünschen & Prioritäten** der Teilnehmer.
     - **Kapazitäten** der Workshops.
     - Ziel: möglichst viele Wünsche erfüllen, faire Verteilung.
   - REST-API mit Endpunkten:
     - `/matching/dry-run` → Testlauf ohne Speicherung.
     - `/matching/run` → echte Zuweisung und Rückspeicherung nach Drupal.
   - Container im Ordner `/matching`.

4. **Nginx**
   - Reverse Proxy.
   - Stellt das Vue-Frontend bereit.
   - Leitet Anfragen korrekt an Drupal oder den Matching-Service weiter.
   - Konfiguration unter `/nginx/vue-site.conf`.

5. **Docker Compose**
   - Steuerung der gesamten Infrastruktur.
   - Services: `drupal`, `vue-frontend`, `matching`, `nginx`, `postgres` (DB).
   - Konfigurationsdateien: `docker-compose.yml`, `docker-compose.override.yml`.

---

## 📊 Datenfluss

1. **CSV-Import**
   - Admins importieren Teilnehmer- und Workshopdaten nach Drupal.
   - Beispiele für CSV-Dateien: `/csv-examples`.

2. **Anmeldung & Wünsche**
   - Teilnehmende melden sich über das **Vue-Frontend** an.
   - Workshop-Wünsche werden über die **JSON:API** in Drupal gespeichert.

3. **Matching**
   - Matching-Service ruft Teilnehmer, Wünsche und Kapazitäten aus Drupal ab.
   - Führt einen **fairen Verteilungsalgorithmus** aus.
   - Ergebnisse werden zurück in Drupal gespeichert.

4. **Verwaltung**
   - Admins sehen Ergebnisse und Reports in Drupal.
   - Exportfunktionen verfügbar.

---

## ⚙️ Technische Details

### Drupal
- Composer-basiertes Setup (`composer.json`).
- Konfigurationsskripte für Bundles & Felder (`/drupal/scripts`).
- Startskript `start-drupal.sh` automatisiert Installation und Basiskonfiguration.
- Rollen & Berechtigungen für CSV-Import und Matching.

### Vue Frontend
- Moderne SPA mit Vue 3.
- Build- und Dev-Setup über Vite (`vite.config.js`).
- `.env.example` für lokale Konfiguration.
- API-Kommunikation mit Axios/Fetch.

### Matching-Service
- Flask-Anwendung (`matching_server.py`).
- REST-Endpunkte für Matching-Läufe.
- Konfigurierbar per `.env` (z. B. Sprache, Timeout, Seed).
- Abbildung der Matching-Logik:
  - Berücksichtigung von **Prio 1–3 Wünschen**.
  - **Kapazitätsgrenzen** der Workshops.
  - Gleichverteilung & Fairness.

### Nginx
- Konfiguriert als Reverse Proxy.
- Verteilt Anfragen an das Frontend, Drupal oder den Matching-Service.
- SSL/HTTPS kann eingebunden werden.

### Docker Compose
- Einheitliches Setup für Entwicklung & Produktion.
- Nutzung von Volumes für Persistenz (z. B. Datenbank, Drupal-Dateien).
- Einfache Befehle:
  - `docker-compose up -d` → Start
  - `docker-compose down` → Stoppen
  - `docker-compose build` → Neu bauen

---

## 📂 Projektstruktur

```
jfcamp-app/
├── csv-examples/           # Beispiel-CSV-Dateien für Import
├── drupal/                 # Drupal Headless CMS
│   ├── config/             # Drupal-Konfiguration
│   ├── scripts/            # Setup- und Utility-Skripte
│   ├── web/                # Webroot (Drupal Core)
│   └── start-drupal.sh     # Startskript
├── matching/               # Python Matching-Service
│   ├── matching_server.py  # Hauptservice (Flask)
│   ├── requirements.txt    # Python-Abhängigkeiten
│   └── Dockerfile
├── nginx/                  # Proxy-Konfiguration
│   └── vue-site.conf
├── vue-frontend/           # Vue 3 Frontend
│   ├── src/                # Quellcode
│   ├── vite.config.js      # Build-Setup
│   └── Dockerfile
├── docker-compose.yml      # Haupt-Compose-Setup
├── docker-compose.override.yml
├── .gitignore
└── README.md
```

---

## ✅ Vorteile für die Teamarbeit

- **Klare Modularisierung**: Frontend, Backend und Matching-Service getrennt.
- **Docker-basiert**: Einheitliche Umgebung für alle Entwickler.
- **Datenimport flexibel**: CSV-Dateien für schnellen Start.
- **Erweiterbar**: Matching-Logik unabhängig optimierbar.
- **Transparenz**: Alle Schritte (Import → Wünsche → Matching → Ergebnisse) nachvollziehbar.

---

## 🔜 Nächste Schritte für das Team

- Matching-Algorithmus weiter verfeinern (Fairness, Zufallsfaktoren, Prioritäten).
- Frontend-UI für Eltern und Teilnehmende verbessern.
- Admin-Dashboard in Drupal erweitern.
- CI/CD-Pipeline für automatisiertes Deployment einrichten.

---
