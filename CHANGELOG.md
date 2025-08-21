# 📦 Changelog – JF Startercamp App

Alle relevanten Änderungen und Releases im Überblick.  
Versionierung folgt **SemVer** (MAJOR.MINOR.PATCH).

---

## [v0.1.0] – 2025-08-21
### Added
- Erste stabile Baseline mit Config Management
- DEV & PROD Setup via Docker Compose
- Init-Scripts (`init-drupal.sh`, `ensure-bundles.php`, `jf-roles.sh`)
- Env-Beispiele für DEV & PROD
- README mit Setup-Anleitung

### Notes
- API-User wird nun über Config angelegt (DEV/PROD)
- DEV = bind mount, PROD = Volume
