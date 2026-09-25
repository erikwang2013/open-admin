> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
>
> [中文](../CLAUDE.md) | [English](CLAUDE.en.md) | [한국어](CLAUDE.ko.md) | [Русский](CLAUDE.ru.md) | [Deutsch](CLAUDE.de.md) | [Français](CLAUDE.fr.md) | [Español](CLAUDE.es.md) | [Português](CLAUDE.pt.md) | [हिन्दी](CLAUDE.hi.md) | [العربية](CLAUDE.ar.md) | [বাংলা](CLAUDE.bn.md) | [Bahasa Indonesia](CLAUDE.id.md) | [日本語](CLAUDE.ja.md)

# Open Admin (open-admin)

Système de panneau d'administration full-stack basé sur webman v2 + Flutter.

## Mention de copyright

```
Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
```

> **Non modifiable, non supprimable, irréversible.** Tous les nouveaux fichiers doivent inclure la mention de copyright ci-dessus comme en-tête de fichier.

## Fonctionnalités

| Domaine | Fonctionnalité |
|----|------|
| Authentification | Connexion/rafraîchissement/déconnexion + captcha à clic + verrouillage du compte + limitation des sessions |
| Tableau de bord | Statistiques en temps réel/tendances/répartition/journaux (cache Redis 5 min) |
| Utilisateurs | CRUD + suppression groupée/activation-désactivation + import Excel |
| Rôles et permissions | CRUD + arbre des permissions + autorisation RBAC method.path |
| Configuration système | CRUD de paires clé-valeur |
| Audit des opérations | Consultation des journaux + détection automatique de la source sur 8 plates-formes |
| Fichiers | Upload + export Excel/PDF (masquage des données sensibles) |
| Sécurité | 18 couches de défense en profondeur (XSS/injection SQL/CSRF/limitation de débit/CSP...) |
| Exploitation | Health check/métriques Prometheus/documentation API/security.txt + Docker + CI/CD |

## Mascotte du projet · Xiao An

Rover gardien en forme de bouclier « Xiao An » (小安), tiré de « **安**全 » (sécurité) et de « 管理后**台** » (panneau d'administration), posté aux deux points de contrôle « protection » et « authentification » de la chaîne de middlewares.

- **Source unique de style** : `public/img/pet.svg` (SVG pur, sans script ni dépendance externe, avec repli `prefers-reduced-motion`). Modifier ce fichier met à jour simultanément la page d'accueil du site, l'assistant d'installation et l'icône du navigateur ; **ne pas en dupliquer une seconde copie**.
- **Emplacements déjà intégrés** :
  - Page d'accueil `GET /` → `app/view/index/view.html` (route en tête de `config/route.php`, sans authentification)
  - 4 pages de l'assistant d'installation → injectées uniformément par `InstallController::layout()`
  - Icône du site → `<link rel="icon" type="image/svg+xml" href="/img/pet.svg">` (page d'accueil + assistant d'installation + `apps/flutter/web/index.html`)
- **Charte de couleurs** : couleur principale `#1677FF`, antenne chaude `#FA8C16`, vert de validation `#52C41A` ; toile `240 × 320`.
- **Diagrammes** : `docs/diagrams/architecture.svg` (architecture système), `features.svg` (conception fonctionnelle), `lifecycle.svg` (cycle de vie) — également des SVG écrits à la main, partageant la même palette que la mascotte ; référencés directement dans le README et la documentation.

## Pile technique

### Backend
- PHP 8.3+, webman v2 (workerman/webman)
- Base de données : MySQL 8.0+, préfixe de table `erik_`
- Clé primaire : BIGINT non auto-incrémentée, générée par `erikwang2013/snowflake-php`
- Chiffrement/déchiffrement des ID au niveau API : `erikwang2013/hashids`
- Authentification JWT : `erikwang2013/jwt-webman`
- Chiffrement/déchiffrement des données sensibles API : `erikwang2013/encryption`
- Chiffrement/déchiffrement des champs sensibles en base de données : `erikwang2013/encryptable`
- Synchronisation et requêtes ES : `erikwang2013/webman-scout`
- Drapeaux nationaux : `erikwang2013/season`

### Frontend
- Flutter 3.x, répertoire source `apps/flutter/`
- Le Web est conçu comme un panneau d'administration PC (pas un style d'app mobile)
- Prise en charge des versions client et administrateur
- HarmonyOS ArkTS, répertoire source `apps/harmonyos/`

## Structure du projet

```
open-admin/
├── app/
│   ├── admin/controller/       # Contrôleurs du panneau d'administration (14)
│   │   ├── BaseController.php      # Contrôleur de base
│   │   ├── DashboardController.php # Tableau de bord (cache Redis)
│   │   ├── UserController.php      # CRUD utilisateurs + opérations groupées
│   │   ├── RoleController.php      # CRUD rôles
│   │   ├── PermissionController.php# CRUD permissions
│   │   ├── ConfigController.php    # CRUD configuration système
│   │   ├── LogController.php       # Consultation des journaux d'opérations
│   │   ├── ProfileController.php   # Espace personnel + déconnexion
│   │   ├── ExportController.php    # Export Excel/PDF
│   │   ├── ImportController.php    # Import d'utilisateurs Excel
│   │   ├── UploadController.php    # Upload de fichiers
│   │   ├── HealthController.php    # Health check
│   │   ├── DocsController.php      # Documentation OpenAPI
│   │   └── MetricsController.php   # Métriques de surveillance Prometheus
│   ├── api/v1/controller/      # Contrôleurs API v1 (distribution par préfixe d'URL /api/v1)
│   │   ├── CaptchaController.php
│   │   └── AuthController.php
│   ├── common/                 # Classes d'outils communes
│   │   ├── HashidsService.php
│   │   ├── SnowflakeService.php
│   │   └── EncryptionService.php
│   ├── common/                 # Définitions communes (avec définitions Apidoc)
│   ├── middleware/             # Middlewares (7)
│   │   ├── Cors.php            # CORS (global)
│   │   └── (migré vers le paquet erikwang2013/security-php)  # détection de 31 types d'attaques
│   │   ├── RateLimit.php       # Limitation de débit Redis (global, atomique en Lua)
│   │   ├── AdminAuth.php       # Authentification JWT + liste noire
│   │   ├── AdminPermission.php # Validation des permissions RBAC (cache Redis 60 s)
│   │   └── OperationLog.php    # Enregistrement automatique des journaux d'opérations (avec détection de la source)
│   ├── model/                  # Modèles de données
│   ├── view/index/view.html    # Modèle de la page d'accueil du site (GET /, mascotte + navigation)
│   ├── queue/                  # Tâches de file d'attente
│   └── process/                # Processus (Http, Monitor)
├── apps/
│   ├── flutter/                # Panneau d'administration Web Flutter
│   │   └── lib/app/
│   │       ├── pages/          # 6 pages complètes
│   │       │   ├── dashboard/  # Tableau de bord
│   │       │   ├── login/      # Connexion
│   │       │   ├── user/       # Gestion des utilisateurs
│   │       │   ├── role/       # Rôles et permissions
│   │       │   ├── config/     # Configuration système
│   │       │   ├── log/        # Journaux d'opérations
│   │       │   └── profile/    # Espace personnel
│   │       ├── services/       # ApiService + AuthService
│   │       ├── layouts/        # Mise en page responsive
│   │       └── theme/          # Thème Material 3
│   └── harmonyos/              # Client HarmonyOS
├── config/                     # Fichiers de configuration
│   ├── route.php               # Routage + stratégie de version API
│   └── middleware.php           # Enregistrement des middlewares globaux
├── database/
│   ├── install.sql             # Script d'installation complet (fusion de tous les SQL)
│   └── backup/                 # Scripts de sauvegarde de la base de données
│       ├── backup.sh           # mysqldump+gzip, conservation 30 jours
│       └── restore.sh          # Restauration interactive
├── docs/                       # Documentation
│   ├── ARCHITECTURE.md         # Diagrammes d'architecture Mermaid
│   ├── DESIGN.md               # Document de conception
│   ├── SECURITY.md             # Conception de l'architecture de sécurité
│   ├── API.md                  # Référence de l'API
│   ├── nginx-security.conf     # Configuration de sécurité Nginx de référence
│   ├── diagrams/               # Diagrammes
│   │   ├── architecture.svg    # Conception de l'architecture système (SVG écrit à la main)
│   │   ├── features.svg        # Conception fonctionnelle (SVG écrit à la main)
│   │   ├── lifecycle.svg       # Cycle de vie (SVG écrit à la main)
│   │   └── 01..12-*.md         # Diagrammes décomposés (Mermaid, 12 langues)
│   └── superpowers/            # Spécifications et plans
│       ├── specs/              # Spécifications de conception
│       └── plans/              # Plans d'implémentation
├── public/                     # Point d'entrée public
│   └── img/pet.svg             # Mascotte du projet « Xiao An » (SVG, également icône du site)
├── runtime/                    # Fichiers d'exécution
├── tests/                      # Tests
├── vendor/                     # Dépendances Composer
├── CLAUDE.md                   # Ce fichier
├── README.md                   # Documentation en chinois
├── docs/translations/          # Documentation multilingue (12 langues × README/CLAUDE)
│   ├── README.en.md            # Documentation en anglais
│   └── README.ko.md ... README.ja.md  # Autres langues (coréen/russe/allemand/français/espagnol/portugais/hindi/arabe/bengali/indonésien/japonais)
├── .env                        # Variables d'environnement (hors contrôle de version)
├── .env.example                # Modèle de variables d'environnement
├── .env.docker                 # Variables d'environnement Docker
├── composer.json               # Dépendances PHP
├── Dockerfile                  # Build Docker
├── docker-compose.yml          # Orchestration Docker
└── .github/
    └── workflows/
        └── ci.yml              # Pipeline CI/CD (syntaxe PHP + PHPUnit + analyse Flutter)
```

## Chaîne d'exécution des middlewares

```
Global :  Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → {middlewares de route}
/admin : Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → AdminAuth → AdminPermission → OperationLog → Controller
/api/v1 : Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → Controller (la version est portée par le préfixe d'URL)
/health : Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → Controller
```

> **Remarque** : les interfaces du panneau d'administration ne nécessitant pas de validation des permissions (comme la consultation de l'espace personnel) sont enregistrées hors du groupe `/admin`, avec uniquement le middleware `AdminAuth`. Les routes du groupe sont validées par `AdminPermission` selon les identifiants de permission au format `method.path`.
>
> **Préfixe Redis** : toutes les clés reçoivent automatiquement le préfixe `open-admin:`, personnalisable via `REDIS_PREFIX` dans `.env`.

## Renforcements de sécurité

- **Détection d'attaques** : paquet erikwang2013/security-php (31 détecteurs : XSS/injection SQL/injection de commandes/traversée de chemin/SSRF/XXE/JNDI/désérialisation/attaques JWT/CSRF/fuite de données sensibles, etc. + vérification des méthodes HTTP/limitation de la taille du corps de requête/vérification du Content-Type + liste noire par escalade d'attaques IP)
- **En-tête CSP** : Content-Security-Policy + X-Permitted-Cross-Domain-Policies injectés dans toutes les réponses
- **Verrouillage du compte** : 5 échecs de connexion consécutifs ⇒ verrouillage du compte de 15 minutes
- **Limitation des sessions concurrentes** : 3 jetons valides maximum par utilisateur, au-delà le jeton le plus ancien est ajouté à la liste noire
- **security.txt** : point de terminaison `/.well-known/security.txt` conforme RFC 9116
- **Configuration de sécurité Nginx** : `docs/nginx-security.conf`, référence de renforcement du proxy inverse

## Stratégie de version API

Le numéro de version est porté par le préfixe d'URL (`/api/v1/...`, `/api/v2/...`), et non par un en-tête de requête :

```bash
curl http://localhost:8787/api/v1/auth/login
```

Pour ajouter une version, créez simplement le répertoire `app/api/{version}/controller/` et enregistrez le groupe de routes correspondant dans `config/route.php`.

## Stratégie de limitation de débit

Fenêtre glissante Redis (atomique en Lua), défaut 60 requêtes/minute/IP/route :
- Connexion `/api/v1/auth/login` : 10 requêtes/minute
- En-têtes de réponse : `X-RateLimit-Limit/Remaining/Reset`, avec `Retry-After` en cas de dépassement

> Les clés de `RateLimit::$sensitive` doivent correspondre aux **chemins complets** de `config/route.php` (préfixe de version `/api/v{n}` inclus), sinon les routes sensibles retombent silencieusement sur la limite par défaut de 60 requêtes/minute.

## Normes de code

### PHP
- Les références aux fonctions/classes globales n'utilisent pas de `\` de tête, `use` est systématiquement utilisé
- Les fichiers de configuration doivent contenir des commentaires en chinois expliquant chaque élément
- Tous les nouveaux fichiers `.php` doivent commencer par la mention de copyright
- **Redis est accédé via la classe utilitaire `support\Redis`** (pool de connexions singleton, lit automatiquement `REDIS_HOST/PORT/PASSWORD/DB`), toutes les clés reçoivent automatiquement un préfixe (défaut `open-admin:`, configurable via `REDIS_PREFIX`)
- **Permissions de route** : les routes du groupe `/admin` nécessitent des permissions au format `method.path` (par exemple `get.admin/dashboard`) ; les routes sans validation de permission sont enregistrées hors du groupe avec uniquement le middleware `AdminAuth`
- **CORS** : lors de l'ajout d'un en-tête de requête, synchroniser le middleware `Cors.php` et l'en-tête `Access-Control-Allow-Headers` du fallback de `route.php`
- **Protection du super administrateur** : les méthodes `update`/`destroy` de `RoleController` interdisent toute opération sur le rôle `slug == 'super_admin'`
- webman convertit les warnings PHP en exceptions ; les propriétés/variables non définies provoquent une erreur 500

### Base de données
- Préfixe de table : `erik_`
- Clé primaire `id` : type BIGINT, non auto-incrémentée, générée par snowflake
- Les champs sensibles utilisent le trait `erikwang2013/encryptable` pour le chiffrement/déchiffrement automatique
- Les fichiers de migration utilisent le format SQL

### Flutter
- La mise en page Web utilise le style panneau d'administration PC (barre latérale + barre supérieure + zone de contenu)
- Gestion d'état GetX, **toutes les requêtes API doivent passer par le singleton `ApiService`** (Dio + intercepteur JWT), création d'instances Dio indépendantes ou baseUrl en dur interdites
- Persistance du jeton avec `shared_preferences`
- Points de rupture responsives : mobile (< 768 px) et desktop (>= 768 px)
- **L'en-tête de page Row doit utiliser `Wrap`**, pour éviter le débordement lors du déploiement de la barre latérale ; les ChoiceChip de filtre doivent être enveloppés dans `Obx` pour une mise à jour réactive
- **DataTable doit être enveloppé dans `SingleChildScrollView(scrollDirection: Axis.horizontal)`** pour éviter le débordement des colonnes
- Les pages autonomes (comme ProfilePage) doivent contenir un `Scaffold`, sinon les composants Material comme `TextField` signalent « No Material widget found »
- Lors du déploiement/repli de la barre latérale, `_showCollapsedContent` retarde la bascule du contenu pour éviter les débordements RenderFlex pendant l'animation

### HarmonyOS
- Utilisation du client HTTP natif `@ohos.net.http`
- Rafraîchissement transparent du jeton : à la réception d'un 401, appel automatique de `/api/v1/auth/refresh`
- En cas d'échec du rafraîchissement, redirection automatique vers la page de connexion

## Déploiement

### Docker Compose (recommandé en production)

Le `docker-compose.yml` à la racine du projet orchestre 5 services :

| Service | Description |
|------|------|
| `nginx` | Proxy inverse Nginx (80/443), service de fichiers statiques |
| `app` | Application webman PHP 8.3, build `Dockerfile` (avec OPcache) |
| `mysql` | MySQL 8.0, persistance par volume de données |
| `redis` | Redis 7 Alpine, cache/limitation de débit/session |
| `elasticsearch` | Elasticsearch 8.x, recherche plein texte |

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` définit le pipeline GitHub Actions :

- Vérification de la syntaxe PHP (`php -l`)
- Tests unitaires PHPUnit
- Analyse statique Flutter (`flutter analyze`)

### Sauvegarde de la base de données

`database/backup/backup.sh` — mysqldump + gzip, nettoyage automatique des sauvegardes de plus de 30 jours.
`database/backup/restore.sh` — restauration interactive, liste les sauvegardes disponibles au choix.

### Surveillance

Le point de terminaison `GET /metrics` (`MetricsController`) émet au format texte Prometheus, avec 5 métriques gauge :
- `openadmin_http_requests_total` — nombre total de requêtes
- `openadmin_active_users` — nombre d'utilisateurs actifs
- `openadmin_db_connection_status` — état de la connexion à la base de données (0/1)
- `openadmin_redis_connection_status` — état de la connexion Redis (0/1)
- `openadmin_memory_usage_bytes` — utilisation de la mémoire
