# JF Camp App

Die **JF Camp App** ist eine containerisierte Webanwendung für die Organisation des **JugendFEIER-Camps**.  
Sie kombiniert **Drupal (Headless CMS)**, ein **Vue 3 Frontend** und einen optionalen **Python-Matching-Service**.  
Alle Komponenten laufen in **Docker-Containern** und werden über `docker compose` gesteuert.  

Ziel ist es, die **Anmeldung und Zuweisung von Teilnehmenden zu Workshops** möglichst effizient, fair und transparent zu gestalten.

---

## 🚀 Architekturüberblick

```
[ Vue Frontend ]  <--->  [ Drupal JSON:API ]  <--->  [ Matching-Service (Python) ]
       |                          |                            |
       v                          v                            v
     Browser   <-------------->  Nginx Proxy  <-------------> Datenbank
```

---

## 📂 Projektstruktur

```
jfcamp-app/
├── csv-examples/           # Beispiel-CSV-Dateien für Import
├── drupal/                 # Drupal Headless CMS
│   ├── config/             # Drupal-Konfiguration (Config-Management)
│   ├── scripts/            # Setup- und Utility-Skripte (z. B. Rollen/Berechtigungen)
│   ├── web/                # Webroot (Drupal Core)
│   └── start-drupal.sh     # Startskript für Erstinstallation
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
├── docker-compose.yml          # Basis-Setup (Produktion)
├── docker-compose.dev.yml      # Overrides für Entwicklung
├── .gitignore
└── README.md
```

---

## ⚙️ Komponenten

### 1. Drupal (Backend & API)
- Composer-basiertes Setup (`composer.json`).
- Headless CMS als zentrale Datenquelle.
- **Custom-Module**:
  - `jfcamp_api` → CSV-Import (Teilnehmer, Workshops, Wünsche).
  - `jfcamp_matching` → Dashboard für Matching, Statistiken und Exporte.
- **Config-Management**: Alle Inhalte (Felder, Rollen, Moduleinstellungen) liegen in `/drupal/config/sync`.
- Utility-Skripte:
  - `start-drupal.sh` → automatisiert Installation & Basiskonfiguration.
  - `scripts/jf-roles.sh` → legt Rollen & Berechtigungen an.

### 2. Vue Frontend
- Vue 3 + Vite SPA.
- Kommuniziert ausschließlich über die JSON:API von Drupal.
- API-URL via `.env.*` konfigurierbar.
- Dev-Server (`vite`) und Docker-Container vorhanden.

### 3. Matching-Service
- Flask-App (`matching_server.py`).
- Endpunkte:
  - `/matching/dry-run` (Simulation)
  - `/matching/run` (echte Zuweisung)
  - `/matching/stats` (Statistiken & Happy Index)
- Konfiguration über `.env` möglich (Sprache, Timeout, Seed).

### 4. Nginx
- Reverse Proxy.
- Verteilt Requests an Frontend, Drupal oder Matching-Service.
- Produktionstauglich, SSL/HTTPS einbindbar.

### 5. Postgres
- Zentrale Datenbank für Drupal.
- Persistenz über Docker-Volume.

---

## 📊 Datenfluss

1. **CSV-Import** → Admins importieren Teilnehmer- und Workshopdaten nach Drupal.
2. **Anmeldung** → Teilnehmende geben Workshop-Wünsche im Vue-Frontend ein.
3. **Matching** → Python-Service berechnet faire Zuteilungen anhand der Wünsche & Kapazitäten.
4. **Dashboard** → Admins sehen Ergebnisse, Reports, Exporte und können Zuteilungen zurücksetzen.

---

## 🔧 Setup & Installation

### 1. Repository klonen
```bash
git clone https://github.com/cgreunke/jfcamp-app.git
cd jfcamp-app
```

### 2. Environment-Dateien erstellen
```bash
cp .env.example .env
cp drupal/.env.example drupal/.env.development
```
→ Variablen (Passwörter, Mails, URLs) anpassen.

---

## 🖥 Entwicklung (DEV)

### Start
```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build -d
```

### Services
- Drupal: http://localhost:8080
- Vue Dev Server: http://localhost:5173
- Matching API: http://localhost:5001
- Adminer: http://localhost:8081 (DB-UI)

### Initiale Einrichtung
1. Datenbank ggf. droppen:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.dev.yml exec drupal ./vendor/bin/drush sql:drop -y
   ```
2. Drupal mit Config installieren:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.dev.yml exec drupal ./vendor/bin/drush site:install -y minimal
   docker compose -f docker-compose.yml -f docker-compose.dev.yml exec drupal ./vendor/bin/drush cim -y
   docker compose -f docker-compose.yml -f docker-compose.dev.yml exec drupal ./vendor/bin/drush cr -y
   ```
3. Rollen & Berechtigungen setzen:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.dev.yml exec drupal bash /opt/drupal/scripts/jf-roles.sh
   ```

---

## 🌐 Produktion (PROD)

### Build & Start
```bash
docker compose -f docker-compose.yml up --build -d
```

### Besonderheiten
- Frontend wird im Container gebaut und über Nginx ausgeliefert.
- Kein separater Dev-Server.
- Drupal-Datenbank & Dateien liegen in Volumes.
- Für SSL kann Nginx erweitert werden (`nginx/vue-site.conf`).

---

## ✅ Vorteile für das Team

- Einheitliches Setup für **Dev & Prod**.
- Vollständiges **Config-Management**: Felder, Module, Rollen.
- **Skripte** für initiale Rollen & Berechtigungen.
- Modular: Frontend, Backend, Matching-Service klar getrennt.
- Docker: überall gleich lauffähig.

---

## 🔜 Nächste Schritte

- Matching-Algorithmus optimieren.
- Frontend-UX für Eltern/Teilnehmende verbessern.
- Weitere Exporte/Reports für Admins.
- CI/CD für automatisches Deployment.
