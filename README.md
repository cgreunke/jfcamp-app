# JF Camp App

Die **JF Camp App** ist eine containerisierte Webanwendung für die Organisation des **JugendFEIER-Startercamps**.  
Sie kombiniert **Drupal (Headless CMS)**, ein **Vue 3 Frontend** und einen optionalen **Python-Matching-Service**.  
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

---

## 📂 Projektstruktur

```
jfcamp-app/
├── csv-examples/
├── drupal/
│   ├── config/             # Konfiguration (Drush cex/cim)
│   ├── modules/custom/     # Custom-Module (z.B. jfcamp_public_api)
│   ├── scripts/            # Setup-/Helper-Skripte
│   ├── web/                # Docroot (Core, Contrib, Themes)
│   └── Dockerfile
├── matching/
│   ├── matching_server.py  # Flask-Service
│   ├── requirements.txt
│   └── Dockerfile
├── nginx/
│   └── vue-site.conf
├── vue-frontend/
│   ├── src/                # Vue3 Frontend
│   ├── vite.config.js
│   └── Dockerfile
├── docker-compose.yml          # Basis
├── docker-compose.dev.yml      # DEV-Overrides (Hybrid-Mount)
├── docker-compose.prod.yml     # PROD-Overrides
├── .env.development            # Lokale DEV-ENV
├── .env.production             # PROD-ENV
└── README.md
```

---

## ⚙️ Komponenten

- **Drupal**  
  - Läuft im Container unter `/opt/drupal`  
  - JSON:API + Custom API Modul `jfcamp_public_api` (für `/api/wunsch`, `/api/zuweisungen`)  
  - Config-Management über `drupal/config/sync`  
  - Skripte: `init-drupal.sh`, `start-drupal.sh`, `ensure-bundles.php`, `jf-roles.sh`  

- **Vue 3 Frontend (Vite)**  
  - DEV: Hot-Reload Port `5173`  
  - PROD: Build → `dist/` → via Nginx  

- **Matching-Service (Python/Flask)**  
  - Endpunkte: `/matching/*`  
  - Holt Config + Teilnehmer/Wünsche aus Drupal  
  - Auth via API-User  

- **Postgres**  
  - Persistenz in Docker-Volumes  

---

## 🧰 Compose-Modi

- `docker-compose.yml` → Basis  
- `docker-compose.dev.yml` → DEV (Hybrid-Mount, bessere Performance auf macOS)  
- `docker-compose.prod.yml` → PROD (nur Volumes, kein Auto-Init, Config-Import via Drush)  

---

## 🔐 ENV-Dateien

- `.env.development` → lokale Entwicklung  
- `.env.production` → Server/Prod, kopiert aus Beispiel  

---

## 🖥️ Workflows

### DEV
```bash
# Reset
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v --remove-orphans
docker image prune -f

# Start
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build

# Einmaliges Init
docker compose exec drupal bash /opt/drupal/scripts/init-drupal.sh
```

### PROD
```bash
( cd vue-frontend && npm ci && npm run build )
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# Config importieren
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal vendor/bin/drush cim -y
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal vendor/bin/drush cr -y
```

---

## 🔄 Änderungen

- **Drupal Config** → DEV: `drush cex -y` committen → PROD: `drush cim -y`  
- **Code (Module, Vue, Matching)** → commit + deploy, ggf. Cache leeren  
- **API-User** muss einmalig via `jf-roles.sh` in PROD angelegt werden  
