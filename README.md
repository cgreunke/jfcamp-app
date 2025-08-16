# JFCamp App

Die JFCamp App ist eine containerisierte Anwendung bestehend aus drei Hauptdiensten:

- **Drupal**: Headless CMS für Verwaltung von Inhalten, Teilnehmerdaten und Workshopeinstellungen. Bereitgestellt mit JSON:API.
- **Matching**: Python/Flask-Service, der die Daten aus Drupal abfragt und für Matching-Logik (z. B. Zuweisung von Teilnehmern zu Workshops) aufbereitet.
- **Vue**: Frontend (Vue 3 + Vite), welches die Benutzeroberfläche bereitstellt und über die Matching-API und JSON:API von Drupal Daten konsumiert.

Zusätzlich laufen folgende Hilfsdienste:
- **Postgres**: Datenbank für Drupal
- **Adminer**: GUI zum Datenbank-Management

---

## Architektur

```
[ Vue Frontend ]  <---->  [ Matching Service ]  <---->  [ Drupal JSON:API ]  <---->  [ Postgres DB ]
```

- Das Vue-Frontend ruft Matching-Endpunkte ab und zeigt die Daten an.
- Matching greift auf die Drupal JSON:API zu, um Konfigurationen, Teilnehmer und Workshops abzuholen.
- Drupal speichert die Daten in Postgres.
- Adminer kann genutzt werden, um direkt auf die Postgres-Datenbank zuzugreifen.

---

## Setup

### Voraussetzungen
- Docker
- Docker Compose

### Start
```bash
docker compose up --build
```

### Services
- Drupal: http://localhost:8080
- Vue Dev Server: http://localhost:5173
- Matching Service: http://localhost:5000
- Adminer: http://localhost:8081 (Login: Postgres-DB, User/PW siehe docker-compose.yml)

---

## Entwicklungshinweise

- **Datenbank-Credentials** sind in `docker-compose.override.yml` bzw. `.env` definiert und dienen nur der lokalen Entwicklung.  
- In Produktion sollten Secrets über sichere Mechanismen (z. B. Docker Secrets oder Vault) gehandhabt werden.
- Änderungen an den Drupal-Inhaltstypen sollten über ein Script oder Konfigurations-Export versioniert werden, damit sie reproduzierbar sind.

---

## Aktueller Stand (16.08.2025)

- Alle Container starten fehlerfrei (Drupal, Matching, Vue, Postgres, Adminer).
- Collation-Warnungen von Postgres können aktuell ignoriert werden (nur Hinweis auf Version-Unterschied).
- Vue ruft bereits Daten über Matching ab, welches korrekt auf Drupal zugreift.

