> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
>
> [中文](../CLAUDE.md) | [English](CLAUDE.en.md) | [한국어](CLAUDE.ko.md) | [Русский](CLAUDE.ru.md) | [Deutsch](CLAUDE.de.md) | [Français](CLAUDE.fr.md) | [Español](CLAUDE.es.md) | [Português](CLAUDE.pt.md) | [हिन्दी](CLAUDE.hi.md) | [العربية](CLAUDE.ar.md) | [বাংলা](CLAUDE.bn.md) | [Bahasa Indonesia](CLAUDE.id.md) | [日本語](CLAUDE.ja.md)

# Offenes Admin-Panel (open-admin)

Ein Full-Stack-Administrations-Backend auf Basis von webman v2 + Flutter.

## Copyright-Hinweis

```
Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
```

> **Nicht änderbar, nicht entfernbar, nicht umkehrbar.** Alle neuen Dateien müssen den obigen Copyright-Hinweis als Datei-Header-Kommentar enthalten.

## Funktionsübersicht

| Bereich | Funktion |
|----|------|
| Authentifizierung | Login/Erneuerung/Logout + Klick-Captcha + Kontosperrung + Sitzungsbegrenzung |
| Dashboard | Echtzeit-Statistiken/Trends/Verteilung/Protokoll (Redis-5m-Cache) |
| Benutzer | CRUD + Massenlöschung/Aktivieren-Deaktivieren + Excel-Import |
| Rollen & Berechtigungen | CRUD + Berechtigungsbaum + RBAC-method.path-Autorisierung |
| Systemkonfiguration | Schlüssel-Wert-CRUD |
| Aktions-Audit | Protokollabfrage + automatische Quellenerkennung von 8 Plattformen |
| Dateien | Upload + Excel/PDF-Export (Maskierung sensibler Daten) |
| Sicherheit | 18-stufige Tiefenverteidigung (XSS/SQL-Injection/CSRF/Rate-Limiting/CSP...) |
| Betrieb | Health Check/Prometheus-Metriken/API-Dokumentation/security.txt + Docker + CI/CD |

## Projekt-Maskottchen · Xiao An

Der schildförmige Wachroboter „Xiao An" (小安), abgeleitet aus „**安**全" (Sicherheit) und „管理后**台**" (Admin-Panel), bewacht die beiden Stationen „Schutz" und „Authentifizierung" der Middleware-Kette.

- **Einzige Stilquelle**: `public/img/pet.svg` (reines SVG, keine Skripte/keine externen Abhängigkeiten, inkl. `prefers-reduced-motion`-Fallback). Eine Änderung an dieser Datei aktualisiert gleichzeitig die Startseite, den Installationsassistenten und das Browser-Icon — **keine zweite Kopie anlegen**.
- **Bereits eingebundene Stellen**:
  - Startseite `GET /` → `app/view/index/view.html` (Route oben in `config/route.php`, ohne Authentifizierung)
  - 4 Seiten des Installationsassistenten → einheitlich injiziert über `InstallController::layout()`
  - Site-Icon → `<link rel="icon" type="image/svg+xml" href="/img/pet.svg">` (Startseite + Installationsassistent + `apps/flutter/web/index.html`)
- **Farbschema**: Hauptfarbe `#1677FF`, warme Antenne `#FA8C16`, Prüfgrün `#52C41A`; Zeichenfläche `240 × 320`.
- **Design-Diagramme**: `docs/diagrams/architecture.svg` (Systemarchitektur), `features.svg` (Funktionsdesign), `lifecycle.svg` (Lebenszyklus) — ebenfalls handgeschriebene SVGs im selben Farbschema wie das Maskottchen; werden in README und Dokumentation direkt referenziert.

## Technologie-Stack

### Backend
- PHP 8.3+, webman v2 (workerman/webman)
- Datenbank: MySQL 8.0+, Tabellenpräfix `erik_`
- Primärschlüssel: BIGINT ohne Auto-Increment, erzeugt von `erikwang2013/snowflake-php`
- API-Ebenen-ID-Ver-/entschlüsselung: `erikwang2013/hashids`
- JWT-Authentifizierung: `erikwang2013/jwt-webman`
- Ver-/Entschlüsselung sensibler API-Daten: `erikwang2013/encryption`
- Ver-/Entschlüsselung sensibler Datenbankfelder: `erikwang2013/encryptable`
- ES-Synchronisation und -Abfrage: `erikwang2013/webman-scout`
- Länder-Flaggen: `erikwang2013/season`

### Frontend
- Flutter 3.x, Quellverzeichnis `apps/flutter/`
- Web-Version im PC-Admin-Stil gestaltet (nicht Mobile-App-Stil)
- Unterstützt Client und Admin-Endgerät
- HarmonyOS ArkTS, Quellverzeichnis `apps/harmonyos/`

## Projektstruktur

```
open-admin/
├── app/
│   ├── admin/controller/       # Admin-Controller (14)
│   │   ├── BaseController.php      # Basis-Controller
│   │   ├── DashboardController.php # Dashboard (Redis-Cache)
│   │   ├── UserController.php      # Benutzer-CRUD + Massenoperationen
│   │   ├── RoleController.php      # Rollen-CRUD
│   │   ├── PermissionController.php# Berechtigungs-CRUD
│   │   ├── ConfigController.php    # Systemkonfigurations-CRUD
│   │   ├── LogController.php       # Aktionsprotokoll-Abfrage
│   │   ├── ProfileController.php   # Persönlicher Bereich + Logout
│   │   ├── ExportController.php    # Excel/PDF-Export
│   │   ├── ImportController.php    # Excel-Benutzerimport
│   │   ├── UploadController.php    # Datei-Upload
│   │   ├── HealthController.php    # Health Check
│   │   ├── DocsController.php      # OpenAPI-Dokumentation
│   │   └── MetricsController.php   # Prometheus-Monitoring-Metriken
│   ├── api/v1/controller/      # API-v1-Controller (Verteilung über URL-Präfix /api/v1)
│   │   ├── CaptchaController.php
│   │   └── AuthController.php
│   ├── common/                 # Gemeinsame Werkzeugklassen
│   │   ├── HashidsService.php
│   │   ├── SnowflakeService.php
│   │   └── EncryptionService.php
│   ├── common/                 # Gemeinsame Definitionen (inkl. Apidoc Definitions)
│   ├── middleware/             # Middleware (7)
│   │   ├── Cors.php            # Cross-Origin (global)
│   │   └── (migriert in das Paket erikwang2013/security-php)  # 31 Angriffserkennungen
│   │   ├── RateLimit.php       # Redis-Rate-Limiting (global, atomar per Lua)
│   │   ├── AdminAuth.php       # JWT-Authentifizierung + Blacklist
│   │   ├── AdminPermission.php # RBAC-Berechtigungsprüfung (Redis-60s-Cache)
│   │   └── OperationLog.php    # Automatische Aktionsprotokoll-Aufzeichnung (inkl. Quellenerkennung)
│   ├── model/                  # Datenmodelle
│   ├── view/index/view.html    # Startseiten-Template (GET /, Projekt-Maskottchen + Einstiegsnavigation)
│   ├── queue/                  # Queue-Tasks
│   └── process/                # Prozesse (Http, Monitor)
├── apps/
│   ├── flutter/                # Flutter-Web-Admin-Panel
│   │   └── lib/app/
│   │       ├── pages/          # 6 vollständige Seiten
│   │       │   ├── dashboard/  # Dashboard
│   │       │   ├── login/      # Login
│   │       │   ├── user/       # Benutzerverwaltung
│   │       │   ├── role/       # Rollen & Berechtigungen
│   │       │   ├── config/     # Systemkonfiguration
│   │       │   ├── log/        # Aktionsprotokoll
│   │       │   └── profile/    # Persönlicher Bereich
│   │       ├── services/       # ApiService + AuthService
│   │       ├── layouts/        # Responsives Layout
│   │       └── theme/          # Material-3-Theme
│   └── harmonyos/              # HarmonyOS-Client
├── config/                     # Konfigurationsdateien
│   ├── route.php               # Routing + API-Versionsstrategie
│   └── middleware.php           # Registrierung globaler Middleware
├── database/
│   ├── install.sql             # Vollständiges Installationsskript (kombinierte SQLs)
│   └── backup/                 # Datenbank-Backupskripte
│       ├── backup.sh           # mysqldump+gzip, 30 Tage Aufbewahrung
│       └── restore.sh          # Interaktive Wiederherstellung
├── docs/                       # Dokumentation
│   ├── ARCHITECTURE.md         # Mermaid-Architekturdiagramme
│   ├── DESIGN.md               # Design-Dokument
│   ├── SECURITY.md             # Sicherheitsarchitektur-Design
│   ├── API.md                  # API-Referenzdokumentation
│   ├── nginx-security.conf     # Nginx-Sicherheitsreferenz
│   ├── diagrams/               # Diagramme
│   │   ├── architecture.svg    # Systemarchitektur-Design (handgeschriebenes SVG)
│   │   ├── features.svg        # Funktionsdesign (handgeschriebenes SVG)
│   │   ├── lifecycle.svg       # Lebenszyklusdiagramm (handgeschriebenes SVG)
│   │   └── 01..12-*.md         # Zerlegte Architekturdiagramme (Mermaid, 12 Sprachen)
│   └── superpowers/            # Konventionen & Pläne
│       ├── specs/              # Design-Spezifikationen
│       └── plans/              # Implementierungspläne
├── public/                     # Öffentlicher Einstiegspunkt
│   └── img/pet.svg             # Projekt-Maskottchen „Xiao An" (SVG, dient zugleich als Site-Icon)
├── runtime/                    # Laufzeitdateien
├── tests/                      # Tests
├── vendor/                     # Composer-Abhängigkeiten
├── CLAUDE.md                   # Diese Datei
├── README.md                   # Chinesische Anleitung
├── docs/translations/          # Mehrsprachige Dokumentation (12 Sprachen × README/CLAUDE)
│   ├── README.en.md            # Englische Anleitung
│   └── README.ko.md ... README.ja.md  # Weitere Anleitungen (Kor/Russ/Deutsch/Franz/Span/Portug/Hindi/Arabisch/Bengali/Indonesisch/Japanisch)
├── .env                        # Umgebungsvariablen (nicht versioniert)
├── .env.example                # Umgebungsvariablen-Vorlage
├── .env.docker                 # Docker-Umgebungsvariablen
├── composer.json               # PHP-Abhängigkeiten
├── Dockerfile                  # Docker-Build
├── docker-compose.yml          # Docker-Orchestrierung
└── .github/
    └── workflows/
        └── ci.yml              # CI/CD-Pipeline (PHP-Syntax+PHPUnit+Flutter analyze)
```

## Middleware-Ausführungskette

```
Global:  Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → {Routen-Middleware}
/admin: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → AdminAuth → AdminPermission → OperationLog → Controller
/api/v1: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → Controller (Version im URL-Präfix)
/health: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → Controller
```

> **Hinweis**: Admin-Endpunkte ohne Berechtigungsprüfung (z. B. Anzeige des persönlichen Bereichs) werden außerhalb der `/admin`-Gruppe separat registriert und erhalten nur die `AdminAuth`-Middleware. Routen innerhalb der Gruppe werden von `AdminPermission` gegen Berechtigungs-Kennungen im Format `method.path` geprüft.
>
> **Redis-Präfix**: Allen Keys wird automatisch das Präfix `open-admin:` vorangestellt; über `REDIS_PREFIX` in `.env` konfigurierbar.

## Sicherheitsverbesserungen

- **Angriffserkennung**: Paket erikwang2013/security-php (31 Detektoren: XSS/SQL-Injection/Befehlsinjektion/Pfad-Traversal/SSRF/XXE/JNDI/Deserialisierung/JWT-Angriffe/CSRF/Datenschutzlecks usw. + HTTP-Methodenprüfung/Request-Body-Größenbegrenzung/Content-Type-Prüfung + IP-Angriffs-Eskalations-Blacklist)
- **CSP-Header**: Content-Security-Policy + X-Permitted-Cross-Domain-Policies werden allen Antworten injiziert
- **Kontosperrung**: 5 aufeinanderfolgende fehlgeschlagene Logins → Konto für 15 Minuten gesperrt
- **Begrenzung paralleler Sitzungen**: Maximal 3 gültige Tokens pro Benutzer; bei Überschreitung wird das älteste Token geblacklistet
- **security.txt**: `/.well-known/security.txt`-Endpunkt nach RFC 9116
- **Nginx-Sicherheitskonfiguration**: `docs/nginx-security.conf` als Härtungsreferenz für den Reverse-Proxy

## API-Versionsstrategie

Die Versionsnummer erscheint im URL-Präfix (`/api/v1/...`, `/api/v2/...`), nicht in einem Request-Header:

```bash
curl http://localhost:8787/api/v1/auth/login
```

Für eine neue Version muss lediglich das Verzeichnis `app/api/{version}/controller/` erstellt und die entsprechende Routengruppe in `config/route.php` registriert werden.

## Rate-Limiting-Strategie

Redis-Gleitfenster (atomar per Lua), Standard 60/Minute/IP/Route:
- Login `/api/v1/auth/login`: 10/Minute
- Response-Header: `X-RateLimit-Limit/Remaining/Reset`, bei Überschreitung zusätzlich `Retry-After`

> Die Keys in `RateLimit::$sensitive` müssen mit den **vollständigen Pfaden** in `config/route.php` übereinstimmen (inkl. Versionspräfix `/api/v{n}`), sonst fallen sensible Routen stillschweigend auf die Standardgrenze von 60/Minute zurück.

## Code-Konventionen

### PHP
- Globale Funktionen/Klassen ohne vorangestelltes `\`, per `use` importieren
- Konfigurationsdateien müssen chinesische Kommentare mit der Bedeutung jedes Konfigurationspunkts enthalten
- Alle neuen `.php`-Dateien müssen oben den Copyright-Hinweis enthalten
- **Redis wird über die Werkzeugklasse `support\Redis` zugegriffen** (Singleton-Connection-Pool, liest automatisch die Umgebungsvariablen `REDIS_HOST/PORT/PASSWORD/DB`); alle Keys erhalten automatisch ein Präfix (Standard `open-admin:`, über `REDIS_PREFIX` konfigurierbar)
- **Routenberechtigung**: Routen innerhalb der `/admin`-Gruppe benötigen Berechtigungen im Format `method.path` (z. B. `get.admin/dashboard`); Routen ohne Berechtigungsprüfung werden außerhalb der Gruppe nur mit der `AdminAuth`-Middleware registriert
- **CORS**: Beim Hinzufügen neuer Request-Header müssen die `Cors.php`-Middleware und der `Access-Control-Allow-Headers`-Fallback in `route.php` synchron aktualisiert werden
- **Superadministrator-Schutz**: Die Methoden `update`/`destroy` von `RoleController` dürfen keine Rollen mit `slug == 'super_admin'` bearbeiten
- webman wandelt PHP-Warnings in Exceptions um; undefinierte Eigenschaften/Variablen führen zu 500-Fehlern

### Datenbank
- Tabellenpräfix: `erik_`
- Primärschlüssel `id`: Typ BIGINT, ohne Auto-Increment, von Snowflake erzeugt
- Sensible Felder werden über das `erikwang2013/encryptable`-Trait automatisch ver-/entschlüsselt
- Migrationsdateien im SQL-Format

### Flutter
- Web-Layout im PC-Admin-Stil (Sidebar + Topbar + Inhaltsbereich)
- GetX-State-Management, **alle API-Requests müssen über den `ApiService`-Singleton laufen** (Dio + JWT-Interceptor); eigenständige Dio-Instanzen oder hartkodierte baseUrl sind verboten
- Token-Persistierung über `shared_preferences`
- Responsive Breakpoints: Mobil (< 768px) und Desktop (>= 768px)
- **Seiten-Header-Rows müssen `Wrap` verwenden**, um Überlauf beim Aufklappen der Sidebar zu verhindern; Filter-ChoiceChips müssen in `Obx` eingebettet sein, damit sie responsiv aktualisieren
- **DataTable muss in `SingleChildScrollView(scrollDirection: Axis.horizontal)` eingebettet werden**, um Spaltenüberlauf zu verhindern
- Eigenständige Seiten (z. B. ProfilePage) müssen ein `Scaffold` enthalten, sonst melden Material-Komponenten wie `TextField` "No Material widget found"
- Beim Auf-/Zuklappen der Sidebar `_showCollapsedContent` verwenden, um den Inhalt verzögert umzuschalten und RenderFlex-Überlauf während der Animation zu vermeiden

### HarmonyOS
- Natives HTTP-Client `@ohos.net.http`
- Nahtlose Token-Erneuerung: bei 401 automatisch `/api/v1/auth/refresh` aufrufen
- Bei fehlgeschlagener Erneuerung automatische Weiterleitung zur Login-Seite

## Deployment

### Docker Compose (für Produktion empfohlen)

Das `docker-compose.yml` im Projektstammverzeichnis orchestriert 5 Dienste:

| Dienst | Beschreibung |
|------|------|
| `nginx` | Nginx-Reverse-Proxy (80/443), statischer Dateiservice |
| `app` | webman-PHP-8.3-App, über `Dockerfile` gebaut (mit OPcache) |
| `mysql` | MySQL 8.0, persistente Datenvolumes |
| `redis` | Redis 7 Alpine, Cache/Rate-Limiting/Session |
| `elasticsearch` | Elasticsearch 8.x, Volltextsuche |

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` definiert die GitHub-Actions-Pipeline:

- PHP-Syntaxprüfung (`php -l`)
- PHPUnit-Unit-Tests
- Flutter-Statische-Analyse (`flutter analyze`)

### Datenbank-Backup

`database/backup/backup.sh` — mysqldump + gzip, löscht automatisch Backups älter als 30 Tage.
`database/backup/restore.sh` — interaktive Wiederherstellung, listet verfügbare Backups zur Auswahl auf.

### Monitoring

Der Endpunkt `GET /metrics` (`MetricsController`) liefert Prometheus text format mit 5 gauge-Metriken:
- `openadmin_http_requests_total` — Gesamtzahl der Requests
- `openadmin_active_users` — Anzahl aktiver Benutzer
- `openadmin_db_connection_status` — Datenbank-Verbindungsstatus (0/1)
- `openadmin_redis_connection_status` — Redis-Verbindungsstatus (0/1)
- `openadmin_memory_usage_bytes` — Speichernutzung
