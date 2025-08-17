# JFCamp App

Containerisierte Anwendung für das JugendFEIER-Camp mit **Drupal (Headless)**, **Vue 3 + Vite** und einem optionalen **Matching-Service (Python)**. Teilnehmer geben im Frontend ihre Workshop-Wünsche ab; Drupal speichert Inhalte und stellt JSON:API + eine kleine **Custom-API** bereit.

---

## Architektur

```
[ Vue Frontend ]  ───(REST)──▶  [ Drupal JSON:API + Custom API ]  ───▶  [ Postgres ]
                                   │
                                   └──(optional)──▶ [ Matching-Service (Python) ]
```

- **Vue**: Wunschformular ohne Klarnamen, Anmeldung via Teilnehmer-Code.
- **Drupal**: Content-Model (Teilnehmer, Workshop, Wunsch, Matching-Config), speichert Daten in **Postgres**, liefert JSON:API und Custom-Endpoints.
- **Matching (optional)**: Python-Container holt Daten via JSON:API und schreibt Zuordnungen zurück.
- **Adminer**: DB-GUI für lokale Entwicklung.

---

## Verzeichnisstruktur (vereinfachte Ansicht)

```
jfcamp-app/
├─ docker-compose.yml
├─ .gitignore
├─ drupal/
│  ├─ custom/
│  │  └─ modules/
│  │     └─ jfcamp_api/
│  │        ├─ jfcamp_api.info.yml
│  │        ├─ jfcamp_api.routing.yml
│  │        └─ src/Controller/WunschController.php
│  ├─ web/
│  │  └─ sites/default/
│  │     ├─ settings.php
│  │     ├─ cors.local.yml              # CORS für das Vue-Dev-Frontend
│  │     └─ (settings.local.php)        # lokal (gitignored)
│  └─ (weitere Drupal-/Composer-Dateien)
├─ vue-frontend/
│  ├─ .env.example
│  ├─ .env.development                  # lokal, gitignored
│  ├─ vite.config.js
│  └─ src/
│     ├─ api/
│     │  ├─ matchingConfig.js
│     │  ├─ workshops.js
│     │  ├─ teilnehmer.js
│     │  └─ wunsch.js
│     └─ components/
│        └─ WunschForm.vue
└─ matching/ (optional, Python)
```

---

## Schnellstart

### Voraussetzungen
- Docker & Docker Compose
- Node 18+ (für lokales Vite-Dev, falls außerhalb des Containers genutzt)

### Starten
```bash
docker compose up --build
```

**Endpoints (lokal, Standard-Ports):**
- Drupal: `http://localhost:8080`
- Vue Dev-Server: `http://localhost:5173`
- Adminer (DB GUI): `http://localhost:8081`
- (optional) Matching-Service: z. B. `http://localhost:5000`

---

## Drupal – Ersteinrichtung (lokal)

> Diese Schritte sind in der Regel einmalig pro frischem Container/DB nötig.

### 1) `settings.local.php` anlegen (lokal, gitignored)
`drupal/web/sites/default/settings.local.php`
```php
<?php
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = '/opt/drupal/private';
$settings['file_temp_path'] = '/tmp';
```

### 2) Ordner & Rechte im Container setzen
```bash
docker compose exec drupal bash -lc '
  mkdir -p /opt/drupal/private /opt/drupal/web/sites/default/files &&
  chown -R www-data:www-data /opt/drupal/private /opt/drupal/web/sites/default/files &&
  chmod 0775 /opt/drupal/private /opt/drupal/web/sites/default/files &&
  vendor/bin/drush cr &&
  vendor/bin/drush ev "echo \"private path: \".(\\Drupal::service(\"file_system\")->realpath(\"private://\")?:\"<empty>\").PHP_EOL;"
'
```

### 3) Module aktivieren & Caches leeren
```bash
docker compose exec drupal bash -lc '
  vendor/bin/drush en jsonapi -y &&
  vendor/bin/drush en dblog -y &&
  vendor/bin/drush en jfcamp_api -y || true &&
  vendor/bin/drush cr
'
```

### 4) CORS für das Vue-Frontend freischalten
`drupal/web/sites/default/cors.local.yml`
```yaml
cors.config:
  enabled: true
  allowedHeaders: ['x-csrf-token','content-type','accept','origin']
  allowedMethods: ['GET','POST','PATCH','DELETE','OPTIONS']
  allowedOrigins: ['http://localhost:5173']
  exposedHeaders: false
  maxAge: 1000
  supportsCredentials: true
```

> Nach Änderung: `drush cr`.

---

## Content-Model in Drupal

**Inhaltstyp „Workshop“**
- `field_ext_id` (Text, optional)
- `field_maximale_plaetze` (Integer, optional)

**Inhaltstyp „Teilnehmer“**
- Titelbeschriftung: „Name“ (nur interne Hilfe)
- `field_code` (Text, **eindeutig**, für Login im Frontend)
- `field_vorname` (Text)
- `field_name` (Text)
- `field_regionalverband` (Text)
- `field_zugewiesen` (Entity Reference → *Workshop*, **mehrfach**), optional: Zuteilungsergebnis

**Inhaltstyp „Wunsch“**
- `field_teilnehmer` (Entity Reference → *Teilnehmer*, **einfach**, Pflicht)
- `field_wuensche` (Entity Reference → *Workshop*, **mehrfach**, Reihenfolge = Priorität)

**Inhaltstyp „matching_config“**
- `field_num_wuensche` (Integer, z. B. 3–5)
- `field_num_zuteilung` (Integer, z. B. 3)

> Stelle sicher, dass genau **ein veröffentlichter** `matching_config`-Node existiert (der neueste wird genommen).

---

## Custom-API (für das Frontend)

Modul **`jfcamp_api`** stellt zwei Endpunkte bereit:

### 1) Teilnehmer-ID anhand Code
```
POST /jfcamp/teilnehmer-id
Content-Type: application/json

{ "code": "ABC123" }
```
Antwort:
```json
{ "ok": true, "id": "UUID-DES-TEILNEHMERS" }
```
Fehlerfälle: 400 (kein Code), 404 (unbekannt)

### 2) Wunsch abgeben (Upsert)
```
POST /jfcamp/wunsch
Content-Type: application/json

{
  "code": "ABC123",
  "workshop_ids": ["<WS-UUID-1>", "<WS-UUID-2>", "..."]
}
```
- dedupliziert automatisch
- kürzt auf `field_num_wuensche` der veröffentlichten **matching_config**
- legt genau **einen** Wunsch-Node pro Teilnehmer an/aktualisiert ihn

Antwort:
```json
{ "ok": true, "wunsch_uuid": "<UUID-DES-WUNSCHES>" }
```
Fehlerfälle: 400 (Validierung), 403 (Code ungültig), 500 (fehlende Felder am Content-Typ)

> Die Routen sind für anonyme Nutzer freigeschaltet (`_access: TRUE`), d. h. kein Login nötig.

---

## Vue-Frontend

### Env-Variablen (Vite)
`vue-frontend/.env.example`
```env
# Wird im Code via import.meta.env.VITE_DRUPAL_BASE gelesen
VITE_DRUPAL_BASE=http://localhost:8080
```

> **.env.development** (lokal, **gitignored**) überschreibt `.env` im Dev-Mode und **wird automatisch verwendet**.
> Nur Variablen mit **`VITE_`**-Präfix sind im Frontend verfügbar.

### API-Calls (bereitgestellt)
- `src/api/matchingConfig.js`: lädt die aktuelle Matching-Konfiguration per JSON:API (published, newest)
- `src/api/workshops.js`: lädt veröffentlichte Workshops
- `src/api/teilnehmer.js`: findet Teilnehmer-UUID per `field_code` (JSON:API)
- `src/api/wunsch.js`: postet an **Custom-API** `/jfcamp/wunsch` (kein CSRF nötig)

### Wunschformular
`src/components/WunschForm.vue`
- Zeigt N Dropdowns gemäß `field_num_wuensche`
- Verhindert doppelte Auswahl
- Validiert „Code vorhanden“ und „mind. 1 Workshop“

> Falls CORS Probleme macht, wahlweise **Proxy** in `vite.config.js` setzen, der `/jsonapi` und `/jfcamp` an `VITE_DRUPAL_BASE` weiterleitet – oder CORS (s. oben) aktivieren.

---

## (Optional) Matching-Service (Python)

- Holt Teilnehmer, Workshops, Wünsche via JSON:API
- Berechnet Zuteilungen (Algorithmus frei)
- Schreibt Ergebnis als Referenzen in `field_zugewiesen` beim **Teilnehmer** oder als separaten Node/Entity
- Läuft als eigener Container; Konfig via Env (Base-URL, Auth/Keys falls nötig)

---

## Beispiel: Seed-Script (lokal)

Lege (falls gewünscht) `drupal/scripts/seed_jfcamp.php` an und führe es aus:
```php
<?php
use Drupal\node\Entity\Node;

function ensureWorkshop(string $title): void {
  $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
    ->condition('type','workshop')->condition('title',$title)->range(0,1)->execute();
  if ($ids) return;
  $n = Node::create(['type'=>'workshop','title'=>$title,'status'=>1]);
  $n->save();
}

function ensureTeilnehmer(string $code, string $vor, string $name, string $rv=''): void {
  $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
    ->condition('type','teilnehmer')->condition('field_code',$code)->range(0,1)->execute();
  if ($ids) return;
  $n = Node::create(['type'=>'teilnehmer','title'=>"$vor $name".($rv?" ($rv)":"") ,'status'=>1]);
  $n->set('field_code',$code);
  $n->set('field_vorname',$vor);
  $n->set('field_name',$name);
  $n->set('field_regionalverband',$rv);
  $n->save();
}

ensureWorkshop('Hip-Hop');
ensureWorkshop('Klettern');
ensureWorkshop('Theater');
ensureTeilnehmer('TEST123','Max','Mustermann','Berlin');

echo "Seed done.\n";
```

Ausführen:
```bash
docker compose exec drupal bash -lc 'vendor/bin/drush scr scripts/seed_jfcamp.php'
```

---

## Git-Workflow (Kurz)

Feature-Branch → PR/Merge nach `main`:
```bash
# im Feature-Branch (z. B. vue-form)
git add -A
git commit -m "Wunschformular + jfcamp_api Endpoints"
git push -u origin vue-form

# Merge in main (per PR oder lokal)
git checkout main
git pull
git merge --no-ff vue-form -m "Merge vue-form"
git push
```

**Env-Dateien ignorieren** (im Repo-Root `.gitignore`):
```gitignore
**/.env
**/.env.*
!**/.env.example
```
Falls bereits getrackt:
```bash
git ls-files | grep -E '\.env(\..+)?$' | xargs -I{} git rm --cached "{}"
git commit -m "Stop tracking env files"
```

---

## Troubleshooting

- **JSON:API 500**  
  `vendor/bin/drush ws --severity=3 --count=50` (Logs), `vendor/bin/drush cr`.  
  Prüfe Felder/Content-Typen und ob `jfcamp_api` aktiv ist.

- **CORS-Fehler im Frontend**  
  `cors.local.yml` prüfen (Origins/Headers/Methods, `supportsCredentials: true`), `drush cr`.  
  Alternativ: Dev-Proxy in Vite.

- **private:// nicht eingerichtet**  
  `settings.local.php` + Ordnerrechte (siehe oben) und `drush cr`.  
  Prüfen: `drush ev 'use Drupal\\Core\\Site\\Settings; var_dump(Settings::get("file_private_path"));'`

- **Route nicht gefunden (/jfcamp/...)**  
  `vendor/bin/drush cr`, `vendor/bin/drush r:list | grep jfcamp_api`  
  (Bei älteren Drush: `drush ev "print_r(array_keys(\\Drupal::service('router.builder')->getRouteCollection()->all()));"`)

---

## Stand

- Wunschformular funktioniert end-to-end gegen **Custom-API** (keine Klarnamen im UI).
- Anzahl der Wunsch-Dropdowns wird aus **matching_config** gelesen.
- Workshops kommen aus JSON:API (published).
- Private Filesystem ist für spätere CSV-Uploads vorbereitet (falls benötigt).

---

## Lizenz

Internes Projekt (Lizenz nach Bedarf ergänzen).
