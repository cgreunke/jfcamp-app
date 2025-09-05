# JF Startercamp – Kapazität erhöhen, testen & bewerten

Diese Anleitung beschreibt, wie du die **Systemkapazität** schrittweise erhöhst, **Lasttests** reproduzierbar durchführst und auf Basis der Messwerte entscheidest, welche **Parameter** (Nginx, Apache/MPM, Postgres) du einstellst. Außerdem: was die **Teilnehmer** in der Praxis erleben (429 vs. 5xx vs. Timeouts) und wie du vor dem Event ein **skalierbares Profil** ausrollst.

> Architektur (Kurz):  
> Nginx (vue-prod, statisches Frontend + API-Proxy) → Apache (Drupal unter mpm_prefork + OPcache) → Postgres

---

## 0) Voraussetzungen & Grundideen

- **Backpressure-Prinzip**: lieber **429 (zu viele Anfragen)** als 5xx/Timeouts. Dadurch „warten“ die Clients (automatischer Backoff) statt Fehlermeldungen zu sehen.  
- **Environment-Trennung**:
  - **Repo** enthält die **Standard-Parameter** (CPX11-Profil).
  - **server-spezifisches** gehört in `settings.local.php` (nicht ins Repo).
  - Für Event-Skalierung nutze **einen eigenen Tag** (z. B. `v1.1.5-event-cpx31`).
- **Metriken**:
  - **p95(200)**: 95-Perzentil der Laufzeit **erfolgreicher** Requests (wichtigste Zahl).
  - **200-Quote**, **429-Quote**, **5xx-Quote**.
- **Ziele** (Richtwerte):
  - **5xx = 0 %**.
  - **p95(200) ≤ ~1200 ms** unter erwarteter Peak-Last.
  - **429-Quote** je nach Limit bewusst vorhanden (Backpressure), aber nicht „alles“.
  - CPU darf unter Peak kurz „hoch“ gehen – **Abbruch/Timeouts** sind das rote Tuch.

---

## 1) Konfigurations-Stellschrauben

### 1.1 Nginx (vue-prod) – Rate Limiting (pro IP)
Datei: `nginx/vue-site.conf`
```nginx
limit_req_zone $binary_remote_addr zone=api_per_ip:10m rate=10r/s;

server {
  listen 80;
  server_name _;

  root /usr/share/nginx/html;
  index index.html;

  location ^~ /api/ {
    limit_req zone=api_per_ip burst=20 nodelay;
    limit_req_status 429;

    proxy_pass http://drupal:80;
    proxy_http_version 1.1;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 60s;
    proxy_send_timeout 60s;
    proxy_redirect off;
  }

  location = /api { return 301 /api/; }
  location /assets/ { try_files $uri =404; }
  location / { try_files $uri $uri/ /index.html; }
}
```

**Knöpfe**:
- `rate=` (Requests/s/IP) – **Durchsatzregler**.  
- `burst=` (Puffer) – **Spitzen glätten**.  
- `nodelay` – lässt Burst sofort zu (ansonsten „tröpfelt“ Nginx).

### 1.2 Apache (Drupal) – mpm_prefork & Timeouts
Datei: `drupal/apache/perf.conf`  
> **Keine** Inline-Kommentare hinter Direktiven (Apache bricht sonst ab).

```apache
<IfModule mpm_prefork_module>
  MaxRequestWorkers 20
  MaxConnectionsPerChild 1000
  StartServers 2
  MinSpareServers 2
  MaxSpareServers 4
</IfModule>

KeepAlive On
MaxKeepAliveRequests 100
KeepAliveTimeout 5

Timeout 60
RequestReadTimeout header=20-40,MinRate=500 body=20,MinRate=500
```

**Knopf**:
- `MaxRequestWorkers` = parallele PHP-Prozesse. Höher = mehr Durchsatz, aber mehr CPU-Druck.

### 1.3 Drupal – Reverse Proxy IPs (nur Server)
Datei (nur auf Server): `drupal/web/sites/default/settings.local.php`
```php
<?php
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_trusted_headers'] = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_ALL;
$settings['reverse_proxy_addresses'] = [
  'IP_VON_vue-prod',
  'IP_VON_caddy', // falls vorhanden
];
```
IPs ermitteln:
```bash
docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' vue-prod
docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' caddy 2>/dev/null || true
```

### 1.4 Postgres – nur bei größeren Instanzen anheben
(Erst sinnvoll bei CPX31/41.)
- `shared_buffers`, `effective_cache_size`, `work_mem`, `max_connections` (per `ALTER SYSTEM SET ...`), dann Restart.

---

## 2) Standard-Profile je Hetzner-Instanz (Empfehlung)

| Host | Apache `MaxRequestWorkers` | Nginx `rate/burst` | Postgres grob |
|------|-----------------------------|--------------------|----------------|
| **CPX11** (2 vCPU) | **20** | **10 r/s, burst 20** | Default |
| **CPX21** (3 vCPU) | 24–28 | 12–16 r/s, burst 24–32 | moderat |
| **CPX31** (4 vCPU) | **32** (später 48) | **20 r/s, burst 40** | 2 GB / 6 GB / 16 MB / 100 |
| **CPX41** (8 vCPU) | **64** (48–64) | **30 r/s, burst 60** | 4 GB / 12 GB / 32 MB / 150 |

> Diese Zahlen sind konservative Startwerte. „Süßspot“ immer per k6 messen.

---

## 3) Git-Vorgehen (Drift vermeiden)

- **Repo**: CPX11-Profil als Default (z. B. `v1.1.4`).  
- **Event**: neuen Tag mit angehobenen Werten (z. B. `v1.1.5-event-cpx31`).  
- **settings.local.php** bleibt **nur auf dem Server**.  
- **Deploy**:
  ```bash
  # Tag ausrollen
  git fetch --all --tags
  git checkout <TAG>
  docker compose -f docker-compose.yml -f docker-compose.prod.yml build drupal
  docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d drupal vue-prod
  docker exec -it vue-prod nginx -t && docker exec -it vue-prod nginx -s reload
  docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal apache2ctl -t
  ```

---

## 4) Lasttests – reproduzierbar fahren

### 4.1 Testskripte & Daten
- `codes.txt` – z. B. `TEST-001` bis `TEST-211` (eine Zeile pro Code)
- `workshops.txt` – Workshop-Titel (eine Zeile pro Titel)
- `k6-wunsch-simple.js` – Skript mit Trends/Rates (liegt im Repo; enthält `latency_200_ms`)

### 4.2 k6 starten (Container)
```bash
NET=$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{printf "%s
" $k}}{{end}}' vue-prod | head -n1)
cd /opt/jfcamp-app

docker run --rm -it --user 0:0 --network "$NET"   -v "$PWD":/scripts -w /scripts   -e BASE_URL="http://vue-prod"   -e CODES_PATH="/scripts/codes.txt"   -e WORKSHOPS_PATH="/scripts/workshops.txt"   -e MAX_WISHES="3"   grafana/k6 run --vus 20 --duration 60s   --summary-export /scripts/summary.json   --summary-trend-stats "avg,med,min,max,p(90),p(95)"   k6-wunsch-simple.js
```

### 4.3 Kennzahlen extrahieren (robust mit `jq`)
```bash
# Eine Zeile: p95(200) + Quoten
docker run --rm -v "$PWD":/w ghcr.io/jqlang/jq -r '
  def p95_200:
    (.metrics["latency_200_ms"].values["p(95)"] // .metrics["latency_200_ms"]["p(95)"] // "n/a");
  def pct(name):
    (((.metrics[name].value // ((.metrics[name].passes // 0) / (.metrics.iterations.count // 1)) // 0) * 100) | round);
  "p95(200)=\(p95_200) ms | 200=\(pct("status_200"))% | 429=\(pct("status_429"))% | 5xx=\(pct("status_5xx"))%"
' /w/summary.json
```

> Optional: Durchsatz (Requests/s):  
> `docker run --rm -v "$PWD":/w ghcr.io/jqlang/jq -r '.metrics.http_reqs.rate' /w/summary.json`

---

## 5) Schrittweise Kapazität erhöhen – Plan

### Schritt A: **Baseline messen** (z. B. CPX11)
1. Deploy Standard-Profil (CPX11: MPM=20, Nginx 10/20).  
2. `20 VUs / 60s` fahren → **Erwartung**: 5xx = 0, p95(200) ≲ 1.2 s, 200-Quote evtl. niedrig (Bewusst: Backpressure).  
3. Wenn ok: **40 VUs / 60s**.  
   - Falls 5xx>0 oder p95(200) > ~2 s → zurück, limit strenger lassen.

### Schritt B: **Nginx leicht lockern** (wenn du mehr 200er möchtest)
- `rate` von 10 → 12 r/s, `burst` 20 → 24, reload Nginx.  
- Wieder `20 VUs / 60s`.  
  - Ziel: 200-Quote steigt (z. B. 20–30 %), p95(200) stabil, 5xx = 0.  
- Wenn stabil: **40 VUs / 60s** wiederholen.

### Schritt C: **Apache erhöhen** (nur wenn CPU/Drupal der limitierende Faktor)
- MPM z. B. von 20 → 24/28 (CPX21) oder 32/48 (CPX31).  
- **Immer nur eine Stellschraube pro Runde ändern** und messen.

### Schritt D: **Postgres** (erst ab CPX31 relevant)
- Buffers/Cache/WorkMem hoch → Restart → messen.  
- Ziel: weniger Server-seitige Wartezeiten bei hoher aktiver Parallelität.

---

## 6) Bewertung & Entscheidungen

**Entscheidungsbaum (vereinfachte Heuristik):**

- **5xx > 0** → **sofort** Nginx-Rate/Burst **senken** oder MPM **senken** (oder Host hochskalieren).  
- **p95(200) > 2000 ms** → ein Schritt zurück (Limiter etwas strenger / MPM zurück); ggf. Host hochskalieren.  
- **200-Quote sehr niedrig** (≪ 10 %) aber p95(200) sehr gut & 5xx=0 → du **kannst** Nginx Rate/Burst **leicht erhöhen** (kleine Schritte, erneut messen).  
- **CPU dauerhaft 100–200 % (CPX11)** trotz Backpressure, aber p95(200) ok & 5xx=0 → noch im Rahmen; für’s Event **Host vertical skalieren** und Profil mit höherem MPM/Nginx ausrollen.

**Typische Sweet-Spots:**
- **CPX11**: MPM=20, Nginx 10–12 r/s, Burst 20–24 → viele 429 (gewollt), 5xx=0, p95(200) ~0.5–1.2 s.  
- **CPX31 Event**: MPM 32–48, Nginx 20 r/s, Burst 40 → deutlich mehr 200er bei stabiler p95(200).

---

## 7) Teilnehmer-Erlebnis (UX) – was sehen sie?

- **200 (OK)**: Wunsch gespeichert / Seite lädt zügig → **super**.  
- **429 (Too Many Requests)**: Frontend macht **Automatik-Retry** (Backoff + Jitter). Nutzer sieht **Spinner/Ladebalken**, **keine** Fehlermeldung. Gefühl: *„Dauert kurz, geht aber.“*  
- **5xx / Timeouts**: Harte Fehler/Abbrüche → **mieses Erlebnis**.  
- **Ziel**: Lieber **einige 429** (mit weichem Warten) als **5xx**.

---

## 8) Event-Vorgehen (skalieren & zurückskalieren)

1. **Vor dem Event** (z. B. CPX31):
   - Neuen Tag: `v1.1.5-event-cpx31` mit
     - Apache `MaxRequestWorkers 32` (später 48, wenn stabil),
     - Nginx `rate 20 r/s, burst 40`,
     - Postgres (z. B.) `shared_buffers=2GB`, `effective_cache_size=6GB`, `work_mem=16MB`, `max_connections=100`.
   - Deploy Tag, kurzer Lasttest (20 → 40 VUs).

2. **Während des Events**:
   - Monitoring: 5xx = 0, p95(200) ≲ 1.5 s.
   - Im Zweifel: `rate`/`burst` **etwas senken** (Nginx Reload ist billig) oder MPM reduzieren.

3. **Nach dem Event**:
   - Zurück auf **Stable-Tag** (z. B. `v1.1.4`) oder neuen Stable.  
   - Optional Host wieder **herunterskalieren** (CPX11/21).

---

## 9) Troubleshooting & Checks

- Apache-Konfig ok?
  ```bash
  docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal apache2ctl -t
  docker compose -f docker-compose.yml -f docker-compose.prod.yml exec drupal apache2ctl -M | grep mpm
  ```
- Nginx reload:
  ```bash
  docker exec -it vue-prod nginx -t && docker exec -it vue-prod nginx -s reload
  ```
- Upstream aus vue-prod prüfen:
  ```bash
  docker exec -it vue-prod sh -lc 'wget -S -O- http://drupal/api/wunsch?code=TEST-001 | head'
  ```

---

## 10) Dokumentation & Reproduzierbarkeit

- **CHANGELOG**: jeden Kapazitäts-Schritt kurz notieren (Zahlen + Messergebnis).  
- **Event-Tag**: klare Commit-Message (welche Parameter, warum).  
- **README_ops.md** (optional): 1-Seiten-Checkliste für Deploy/Resize/Tests.

---

### TL;DR – Minimalplan für jetzt

1) **CPX11** lassen, Profil: MPM=20, Nginx 10/20.  
2) `20 VUs / 60s` → p95(200) & Quoten prüfen.  
3) Wenn du mehr 200er willst: **Nginx 12/24**, reload, erneut messen.  
4) **40 VUs / 60s**: prüfen, ob 5xx=0 und p95(200) ≤ ~1.2–1.5 s bleibt.  
5) Für’s **Event**: **CPX31-Tag** mit MPM 32–48, Nginx 20/40, PG-Tuning.  
6) Nach dem Event: zurück auf Stable-Tag.
