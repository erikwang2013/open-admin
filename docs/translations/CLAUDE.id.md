> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
>
> [中文](../CLAUDE.md) | [English](CLAUDE.en.md) | [한국어](CLAUDE.ko.md) | [Русский](CLAUDE.ru.md) | [Deutsch](CLAUDE.de.md) | [Français](CLAUDE.fr.md) | [Español](CLAUDE.es.md) | [Português](CLAUDE.pt.md) | [हिन्दी](CLAUDE.hi.md) | [العربية](CLAUDE.ar.md) | [বাংলা](CLAUDE.bn.md) | [Bahasa Indonesia](CLAUDE.id.md) | [日本語](CLAUDE.ja.md)

# Panel Admin Terbuka (open-admin)

Sistem panel admin full-stack berbasis webman v2 + Flutter.

## Deklarasi Hak Cipta

```
Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
```

> **Tidak dapat dimodifikasi, tidak dapat dihapus, tidak dapat dibatalkan.** Semua file baru wajib menyertakan deklarasi hak cipta di atas sebagai komentar header file.

## Daftar Fitur

| Domain | Fitur |
|----|------|
| Autentikasi | Login/Refresh/Logout + captcha klik + penguncian akun + batasan sesi |
| Dasbor | Statistik real-time/tren/distribusi/log (cache Redis 5m) |
| Pengguna | CRUD + hapus massal/aktif-nonaktifkan + impor Excel |
| Peran & Hak Akses | CRUD + pohon hak akses + otorisasi RBAC method.path |
| Konfigurasi Sistem | CRUD pasangan kunci-nilai |
| Audit Operasi | Kueri log + deteksi otomatis sumber 8 platform |
| File | Upload + ekspor Excel/PDF (penyamaran data sensitif) |
| Keamanan | 18 lapis pertahanan berlapis (XSS/Injeksi SQL/CSRF/rate limit/CSP...) |
| Operasional | Health check/metrik Prometheus/dokumen API/security.txt + Docker + CI/CD |

## Hewan Peliharaan Proyek · Xiao An (小安)

Robot penjaga berbentuk perisai 「Xiao An (小安)」, diambil dari 「**安**全」(keamanan) dan 「管理后**台**」(panel admin), berjaga di dua pos pemeriksaan 「perlindungan」 dan 「autentikasi」 pada rantai middleware.

- **Sumber gaya tunggal**: `public/img/pet.svg` (SVG murni, tanpa skrip/tanpa dependensi eksternal, termasuk fallback `prefers-reduced-motion`). Memodifikasi file ini sekaligus memperbarui halaman utama situs, wizard instalasi, dan ikon browser, **jangan membuat salinan kedua**.
- **Lokasi yang sudah terpasang**:
  - Halaman utama situs `GET /` → `app/view/index/view.html` (rute di bagian atas `config/route.php`, tanpa autentikasi)
  - 4 halaman wizard instalasi → disuntikkan terpusat oleh `InstallController::layout()`
  - Ikon situs → `<link rel="icon" type="image/svg+xml" href="/img/pet.svg">` (halaman utama + wizard instalasi + `apps/flutter/web/index.html`)
- **Standar warna**: warna utama `#1677FF`, antena hangat `#FA8C16`, hijau verifikasi `#52C41A`; kanvas `240 × 320`.
- **Diagram desain**: `docs/diagrams/architecture.svg` (arsitektur sistem), `features.svg` (desain fitur), `lifecycle.svg` (siklus hidup) — semuanya SVG tulis tangan dengan palet warna yang sama seperti hewan peliharaan; direferensikan langsung di README dan dokumentasi.

## Tumpukan Teknologi

### Backend
- PHP 8.3+, webman v2 (workerman/webman)
- Basis data: MySQL 8.0+, prefiks tabel `erik_`
- Primary key: BIGINT non-auto-increment, dibuat oleh `erikwang2013/snowflake-php`
- Enkripsi/dekripsi ID lapisan API: `erikwang2013/hashids`
- Autentikasi JWT: `erikwang2013/jwt-webman`
- Enkripsi/dekripsi data sensitif API: `erikwang2013/encryption`
- Enkripsi/dekripsi bidang sensitif basis data: `erikwang2013/encryptable`
- Sinkronisasi & kueri ES: `erikwang2013/webman-scout`
- Bendera negara: `erikwang2013/season`

### Frontend
- Flutter 3.x, direktori sumber `apps/flutter/`
- Versi Web didesain bergaya panel admin PC (bukan gaya App seluler)
- Mendukung klien dan sisi admin
- HarmonyOS ArkTS, direktori sumber `apps/harmonyos/`

## Struktur Proyek

```
open-admin/
├── app/
│   ├── admin/controller/       # Kontroler sisi admin (14)
│   │   ├── BaseController.php      # Kontroler dasar
│   │   ├── DashboardController.php # Dasbor (cache Redis)
│   │   ├── UserController.php      # CRUD pengguna + operasi massal
│   │   ├── RoleController.php      # CRUD peran
│   │   ├── PermissionController.php# CRUD hak akses
│   │   ├── ConfigController.php    # CRUD konfigurasi sistem
│   │   ├── LogController.php       # Kueri log operasi
│   │   ├── ProfileController.php   # Pusat akun pribadi + logout
│   │   ├── ExportController.php    # Ekspor Excel/PDF
│   │   ├── ImportController.php    # Impor pengguna via Excel
│   │   ├── UploadController.php    # Upload file
│   │   ├── HealthController.php    # Pemeriksaan kesehatan
│   │   ├── DocsController.php      # Dokumen OpenAPI
│   │   └── MetricsController.php   # Metrik pemantauan Prometheus
│   ├── api/v1/controller/      # Kontroler API v1 (distribusi prefiks URL /api/v1)
│   │   ├── CaptchaController.php
│   │   └── AuthController.php
│   ├── common/                 # Kelas utilitas publik
│   │   ├── HashidsService.php
│   │   ├── SnowflakeService.php
│   │   └── EncryptionService.php
│   ├── common/                 # Definisi publik (termasuk Apidoc Definitions)
│   ├── middleware/             # Middleware (7)
│   │   ├── Cors.php            # Lintas domain (global)
│   │   └── (telah dimigrasikan ke paket erikwang2013/security-php)  # 31 jenis deteksi serangan
│   │   ├── RateLimit.php       # Rate limit Redis (global, atomik Lua)
│   │   ├── AdminAuth.php       # Autentikasi JWT + blacklist
│   │   ├── AdminPermission.php # Validasi hak akses RBAC (cache Redis 60s)
│   │   └── OperationLog.php    # Pencatatan log operasi otomatis (termasuk deteksi sumber)
│   ├── model/                  # Model data
│   ├── view/index/view.html    # Templat halaman utama situs (GET /, hewan peliharaan proyek + navigasi masuk)
│   ├── queue/                  # Tugas antrian
│   └── process/                # Proses (Http, Monitor)
├── apps/
│   ├── flutter/                # Panel admin Web Flutter
│   │   └── lib/app/
│   │       ├── pages/          # 6 halaman lengkap
│   │       │   ├── dashboard/  # Dasbor
│   │       │   ├── login/      # Login
│   │       │   ├── user/       # Manajemen pengguna
│   │       │   ├── role/       # Peran & hak akses
│   │       │   ├── config/     # Konfigurasi sistem
│   │       │   ├── log/        # Log operasi
│   │       │   └── profile/    # Pusat akun pribadi
│   │       ├── services/       # ApiService + AuthService
│   │       ├── layouts/        # Tata letak responsif
│   │       └── theme/          # Tema Material 3
│   └── harmonyos/              # Klien HarmonyOS
├── config/                     # File konfigurasi
│   ├── route.php               # Routing + kebijakan versi API
│   └── middleware.php           # Registrasi middleware global
├── database/
│   ├── install.sql             # Skrip instalasi lengkap (menggabungkan semua SQL)
│   └── backup/                 # Skrip backup basis data
│       ├── backup.sh           # mysqldump+gzip, retensi 30 hari
│       └── restore.sh          # Pemulihan interaktif
├── docs/                       # Dokumentasi
│   ├── ARCHITECTURE.md         # Diagram arsitektur Mermaid
│   ├── DESIGN.md               # Dokumen desain
│   ├── SECURITY.md             # Desain arsitektur keamanan
│   ├── API.md                  # Dokumen referensi API
│   ├── nginx-security.conf     # Konfigurasi referensi keamanan Nginx
│   ├── diagrams/               # Diagram
│   │   ├── architecture.svg    # Diagram desain arsitektur sistem (SVG tulis tangan)
│   │   ├── features.svg        # Diagram desain fitur (SVG tulis tangan)
│   │   ├── lifecycle.svg       # Diagram siklus hidup (SVG tulis tangan)
│   │   └── 01..12-*.md         # Diagram arsitektur terurai (Mermaid, 12 bahasa)
│   └── superpowers/            # Spesifikasi & rencana
│       ├── specs/              # Spesifikasi desain
│       └── plans/              # Rencana implementasi
├── public/                     # Titik masuk publik
│   └── img/pet.svg             # Hewan peliharaan proyek 「Xiao An (小安)」(SVG, sekaligus ikon situs)
├── runtime/                    # File runtime
├── tests/                      # Pengujian
├── vendor/                     # Dependensi Composer
├── CLAUDE.md                   # File ini
├── README.md                   # Dokumentasi bahasa Cina
├── README.en.md                # Dokumentasi bahasa Inggris
├── README.ko.md ... README.ja.md  # Dokumentasi multi-bahasa (Korea/Rusia/Jerman/Prancis/Spanyol/Portugis/Hindi/Arab/Bengali/Indonesia/Jepang)
├── .env                        # Variabel lingkungan (tidak masuk versi kontrol)
├── .env.example                # Template variabel lingkungan
├── .env.docker                 # Variabel lingkungan Docker
├── composer.json               # Dependensi PHP
├── Dockerfile                  # Build Docker
├── docker-compose.yml          # Orkestrasi Docker
└── .github/
    └── workflows/
        └── ci.yml              # Pipeline CI/CD (sintaks PHP+PHPUnit+Flutter analyze)
```

## Rantai Eksekusi Middleware

```
Global:  Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → {middleware rute}
/admin: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → AdminAuth → AdminPermission → OperationLog → Controller
/api/v1: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → Controller (versi tercermin pada prefiks URL)
/health: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → Controller
```

## Penguatan Keamanan

- **Deteksi serangan**: paket erikwang2013/security-php (31 jenis detektor: XSS/Injeksi SQL/Injeksi perintah/Path traversal/SSRF/XXE/JNDI/Deserialisasi/Serangan JWT/CSRF/Kebocoran data sensitif, dll. + validasi metode HTTP/batasan ukuran body permintaan/validasi Content-Type + blacklist eskalasi serangan IP)
- **Header CSP**: Content-Security-Policy + X-Permitted-Cross-Domain-Policies disuntikkan ke semua respons
- **Penguncian akun**: 5 kali kegagalan login berturut-turut, akun terkunci 15 menit
- **Batasan sesi bersamaan**: satu pengguna maksimal 3 Token valid, lebih dari itu Token paling lama masuk blacklist
- **security.txt**: endpoint `/.well-known/security.txt` RFC 9116
- **Konfigurasi keamanan Nginx**: `docs/nginx-security.conf` referensi penguatan reverse proxy

## Kebijakan Versi API

Nomor versi tercermin pada prefiks URL (`/api/v1/...`, `/api/v2/...`), tidak menggunakan header permintaan:

```bash
curl http://localhost:8787/api/v1/auth/login
```

Menambah versi baru hanya perlu membuat direktori `app/api/{version}/controller/` dan mendaftarkan grup rute versi terkait di `config/route.php`.

## Kebijakan Rate Limit

Sliding window Redis (atomik Lua), default 60 kali/menit/IP/rute:
- Login `/api/v1/auth/login`: 10 kali/menit
- Header respons: `X-RateLimit-Limit/Remaining/Reset`, saat melebihi batas ditambahkan `Retry-After`

> Kunci `RateLimit::$sensitive` harus konsisten dengan **jalur lengkap** di `config/route.php` (termasuk prefiks versi `/api/v{n}`), jika tidak rute sensitif akan secara diam-diam kembali ke default 60 kali/menit.

## Standar Kode

### PHP
- Referensi fungsi/kelas global tanpa awalan `\`, gunakan `use` untuk impor
- File konfigurasi wajib menyertakan komentar bahasa Cina yang menjelaskan arti setiap item konfigurasi
- Semua file `.php` baru wajib menyertakan deklarasi hak cipta di bagian atas
- **Redis diakses melalui kelas utilitas `support\Redis`** (pool koneksi singleton, otomatis membaca variabel lingkungan `REDIS_HOST/PORT/PASSWORD/DB`), semua key otomatis diberi prefiks (default `open-admin:`, dapat dikonfigurasi melalui variabel lingkungan `REDIS_PREFIX`)
- **Hak akses rute**: rute di dalam grup `/admin` memerlukan hak akses berformat `method.path` (seperti `get.admin/dashboard`), rute tanpa validasi hak akses ditempatkan di luar grup dengan hanya menambahkan middleware `AdminAuth`
- **CORS**: saat menambahkan header permintaan, sinkronkan juga middleware `Cors.php` dan `Access-Control-Allow-Headers` pada fallback `route.php`
- **Perlindungan super admin**: metode `update`/`destroy` pada `RoleController` dilarang mengoperasikan peran dengan `slug == 'super_admin'`
- webman mengubah PHP Warning menjadi exception, properti/variabel yang tidak terdefinisi akan menyebabkan kesalahan 500

### Basis data
- Prefiks tabel: `erik_`
- Primary key `id`: tipe BIGINT, non-auto-increment, dibuat oleh snowflake
- Bidang sensitif menggunakan trait `erikwang2013/encryptable` untuk enkripsi/dekripsi otomatis
- File migrasi menggunakan format SQL

### Flutter
- Tata letak versi Web menggunakan gaya panel admin PC (sidebar + header + area konten)
- Menggunakan manajemen status GetX, **semua permintaan API wajib melalui singleton `ApiService`** (Dio + interceptor JWT), dilarang membuat instance Dio terpisah atau hardcode baseUrl
- Persistensi Token menggunakan `shared_preferences`
- Titik putus responsif: seluler (< 768px) dan desktop (>= 768px)
- **Row pada header halaman wajib menggunakan `Wrap`** untuk mencegah overflow saat sidebar diperluas; ChoiceChip filter wajib dibungkus `Obx` agar dapat diperbarui secara reaktif
- **DataTable wajib dibungkus `SingleChildScrollView(scrollDirection: Axis.horizontal)`** untuk mencegah overflow kolom
- Halaman mandiri (seperti ProfilePage) wajib menyertakan `Scaffold`, jika tidak komponen Material seperti `TextField` akan melaporkan "No Material widget found"
- Saat sidebar diperluas/diciutkan gunakan `_showCollapsedContent` untuk menunda penggantian konten, menghindari overflow RenderFlex selama animasi

### HarmonyOS
- Menggunakan klien HTTP native `@ohos.net.http`
- Refresh token tanpa terasa: saat 401 otomatis memanggil `/api/v1/auth/refresh`
- Gagal refresh otomatis dialihkan ke halaman login

## Deployment

### Docker Compose (direkomendasikan untuk produksi)

`docker-compose.yml` di direktori root proyek mengorkestrasi 5 layanan:

| Layanan | Keterangan |
|------|------|
| `nginx` | Reverse proxy Nginx (80/443), layanan file statis |
| `app` | Aplikasi webman PHP 8.3, dibangun `Dockerfile` (termasuk OPcache) |
| `mysql` | MySQL 8.0, persistensi volume data |
| `redis` | Redis 7 Alpine, cache/rate limit/Session |
| `elasticsearch` | Elasticsearch 8.x, pencarian teks lengkap |

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` mendefinisikan pipeline GitHub Actions:

- Pemeriksaan sintaks PHP (`php -l`)
- Unit test PHPUnit
- Analisis statis Flutter (`flutter analyze`)

### Backup Basis Data

`database/backup/backup.sh` — mysqldump + gzip, otomatis membersihkan backup lama lebih dari 30 hari.
`database/backup/restore.sh` — pemulihan interaktif, menampilkan backup yang tersedia untuk dipilih.

### Pemantauan

Endpoint `GET /metrics` (`MetricsController`) mengeluarkan format teks Prometheus, berisi 5 metrik gauge:
- `openadmin_http_requests_total` — total permintaan
- `openadmin_active_users` — jumlah pengguna aktif
- `openadmin_db_connection_status` — status koneksi basis data (0/1)
- `openadmin_redis_connection_status` — status koneksi Redis (0/1)
- `openadmin_memory_usage_bytes` — penggunaan memori
