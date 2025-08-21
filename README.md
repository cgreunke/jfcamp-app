# JF Camp App

Die **JF Camp App** ist eine containerisierte Webanwendung für die Organisation des **JugendFEIER‑Startercamps**.  
Sie kombiniert **Drupal (Headless CMS)**, ein **Vue 3 Frontend** und einen optionalen **Python‑Matching‑Service**.  
Alle Komponenten laufen in **Docker‑Containern** und werden über `docker compose` gesteuert.

Ziel: **Anmeldung und Zuweisung von Teilnehmenden zu Workshops** effizient, fair und transparent.

---

## 🚀 Architektur

```
[ Vue Frontend ]  <--->  [ Drupal JSON:API ]  <--->  [ Matching-Service (Python) ]
       |                          |                            |
       v                          v                            v
     Browser   <-------------->  Nginx (optional) <--------> Postgres
```

---

## 📂 Projektstruktur

```
jfcamp-app/
├── csv-examples/
├── drupal/
│   ├── config/             # Config-Management (wird via drush cex/cim gefüllt)
│   ├── scripts/
│   │   ├── start-drupal.sh # Slim Start (Install + optional CIM)
│   │   ├── init-drupal.sh  # Einmalige Initialisierung in DEV
│   │   ├── ensure-bundles.php
│   │   └── jf-roles.sh     # Rollen, Rechte, API-User (aus ENV)
│   ├── web/                # Docroot (Core/Themes/Modules)
│   └── Dockerfile
├── matching/
│   ├── matching_server.py
│   ├── requirements.txt
│   └── Dockerfile
├── nginx/
│   └── vue-site.conf
├── vue-frontend/
│   ├── src/
│   ├── vite.config.js
│   └── Dockerfile
├── docker-compose.yml          # Basis (gemeinsam für DEV/PROD)
├── docker-compose.dev.yml      # DEV-Overrides (Hybrid-Mount für macOS-Speed)
├── docker-compose.prod.yml     # PROD-Overrides
├── .env.development            # DEV-ENV (lokal)
├── .env.production             # PROD-ENV (Server) – vom Beispiel kopieren
└── README.md
```

---

## ⚙️ Komponenten & Standards

- **Drupal**  
  - Composer‑Projekt unter `/opt/drupal` im Container  
  - JSON:API + Basic Auth  
  - Admin‑UX: Gin (Admin‑Theme), Admin Toolbar, r4032login  
  - **Config‑Management**: `drupal/config/sync`  
  - Skripte:
    - `start-drupal.sh`: nur Installation + optional `drush cim`
    - `init-drupal.sh`: DEV‑Einmal‑Setup (Module, Bundles/Felder, Rollen/Perms, API‑User, `drush cex`)
    - `ensure-bundles.php`: Content‑Types/Felder/Displays (idempotent)
    - `jf-roles.sh`: Rollen & Rechte, API‑User aus ENV

- **Vue 3 (Vite)**  
  - DEV: Hot‑Reload auf Port `5173`  
  - PROD: `npm run build` → statisches `dist/` via Nginx

- **Matching‑Service (Flask)**  
  - Endpunkte: `/matching/dry-run`, `/matching/run`, `/matching/stats`, `/health`  
  - Auth gegen Drupal via API‑User (ENV)

- **Postgres**  
  - Persistenz in Docker‑Volumes

---

## 🧰 Compose‑Dateien

- **Basis:** `docker-compose.yml`  
  Enthält alle Dienste + **Named Volume** `drupal_project:/opt/drupal` für das komplette Drupal‑Projekt.

- **DEV:** `docker-compose.dev.yml`  
  Setzt **Hybrid‑Mount** (schnell auf macOS):  
  Bind‑Mount **nur** für `drupal/config`, `drupal/scripts`, `drupal/web/modules/custom`, `drupal/web/themes/custom`.  
  Core/Contrib/Vendor/Files bleiben im Container‑Volume → **deutlich bessere I/O‑Performance**.

- **PROD:** `docker-compose.prod.yml`  
  Keine Host‑Mounts außer `vue-frontend/dist` + Nginx‑Config.  
  Drupal läuft vollständig aus dem Volume. Konfiguration via `drush cim`.

---

## 🔐 ENV‑Dateien

### `.env.development` (lokal)
Wichtige Keys:
- `DRUPAL_DB_*`
- `DRUPAL_SITE_NAME`, `DRUPAL_ADMIN_USER`, `DRUPAL_ADMIN_PASS`, `DRUPAL_ADMIN_MAIL`
- `CONFIG_SYNC_DIRECTORY=/opt/drupal/config/sync`
- `DRUPAL_AUTO_IMPORT_ON_START=1` (bequem im DEV)
- `DRUPAL_TRUSTED_HOSTS=^drupal$,^localhost$,^127\.0\.0\.1$`
- `MATCHING_BASE_URL=http://matching:5001`
- **API‑User** für den Matching‑Service: `DRUPAL_API_USER`, `DRUPAL_API_PASS`, `DRUPAL_API_MAIL`, `DRUPAL_API_ROLE`

### `.env.production` (Server)
Vom Beispiel **`.env.production.example`** kopieren und anpassen:
- sichere Passwörter!  
- korrekte `DRUPAL_TRUSTED_HOSTS` Regex für deine Domains  
- `DRUPAL_REVERSE_PROXY=1` + `DRUPAL_REVERSE_PROXY_ADDRESSES` wenn hinter LB/Proxy  
- `DRUPAL_AUTO_IMPORT_ON_START=0` (PROD)  
- `MATCHING_BASE_URL=http://matching:5001` (interner Service‑Name)  
- API‑User‑Daten (für `jf-roles.sh`, falls du ihn in PROD anlegen willst)

---

## 🖥️ Entwicklungsmodus (DEV)

> Einmal sauber aufsetzen und baseline‑Config exportieren.

### 0) Clean Reset (optional)
```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v --remove-orphans
docker image prune -f
```

### 1) Starten
```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose logs -f drupal
```

### 2) Einmalige Initialisierung
```bash
docker compose exec drupal bash /opt/drupal/scripts/init-drupal.sh
```
Tut:
- Composer‑Require (Gin, Admin Toolbar, r4032login, gin_toolbar)
- Core/Contrib‑Module aktivieren
- Gin als Admin‑Theme, r4032login, Admin Toolbar
- **Content‑Types/Felder/Displays** (`ensure-bundles.php`)
- **Rollen/Perms + API‑User** (`jf-roles.sh`, nimmt ENV)
- `drush cex -y` → Config landet in `drupal/config/sync`

### 3) Config committen
```bash
git add drupal/config
git commit -m "Baseline Config nach Init"
```

### 4) Quick‑Checks
```bash
# Drupal
docker compose exec drupal vendor/bin/drush status
curl -s http://localhost:8080/jsonapi | head

# Bundles/Felder
docker compose exec drupal vendor/bin/drush ev "print_r(array_keys(\Drupal\node\Entity\NodeType::loadMultiple()));"
docker compose exec drupal vendor/bin/drush ev "echo (Drupal\field\Entity\FieldConfig::loadByName('node','teilnehmer','field_zugewiesen') ? 'OK' : 'NO').PHP_EOL;"

# Rollen/Perms
docker compose exec drupal vendor/bin/drush role:perm:list team | grep -E 'import jfcamp csv|run jfcamp matching'
docker compose exec drupal vendor/bin/drush user:information "${DRUPAL_API_USER:-apiuser}"

# Matching
curl -s http://localhost:5001/health
```

---

## 🌐 Produktionsmodus (PROD)

> **Kein** `init-drupal.sh` in PROD verwenden. PROD wird über **Config‑Management** reproduziert.

### 0) Frontend bauen (CI oder lokal)
```bash
( cd vue-frontend && npm ci && npm run build )
```
→ erzeugt `vue-frontend/dist/`

### 1) Server vorbereiten
- Repo + `vue-frontend/dist` deployen
- `.env.production` vom Beispiel kopieren und ausfüllen

### 2) Container starten
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

### 3) Erstinstallation (nur bei frischer DB)
Wenn deine PROD‑DB leer ist, einmalig installieren (ENV wird genutzt):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal   vendor/bin/drush site:install minimal -y   --account-name="${DRUPAL_ADMIN_USER}"   --account-pass="${DRUPAL_ADMIN_PASS}"   --account-mail="${DRUPAL_ADMIN_MAIL}"   --site-name="${DRUPAL_SITE_NAME}"   --locale=de
```

> Alternativ macht das dein `start-drupal.sh` automatisch, falls noch **nicht** installiert und die ENV vorhanden ist.

### 4) Config importieren
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal vendor/bin/drush cim -y
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal vendor/bin/drush cr -y
```

### 5) API‑User & Rollen (nur wenn noch nicht vorhanden)
> Benutzer sind **Content**, nicht Config.  
> Deshalb wird der API‑User nicht durch `drush cim` angelegt.  
> Stattdessen legst du ihn **einmalig** via Skript an (nimmt Pass/Name/Mail aus ENV):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal bash /opt/drupal/scripts/jf-roles.sh
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal vendor/bin/drush cr -y
```

Danach bleibt der User in der PROD‑DB bestehen.

### 6) Smoke‑Tests
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal vendor/bin/drush status
curl -s http://localhost:8080/jsonapi | head     # (oder via Domain/Proxy)
curl -s http://localhost:5001/health
```

---

## 🔄 Änderungen & Deploy

- **Config‑Änderung in DEV** → `drush cex -y` → commit → PROD: `drush cim -y`  
- **Code‑Änderung (Custom‑Module/Themes/Matching/Vue)** → deployen, ggf. Container neu bauen, Cache leeren  
- **Neue Module/Felder**: in DEV ausführen (Composer/Drush) → `cex` → PROD: `cim`

---
