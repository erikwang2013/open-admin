> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
>
> [中文](../README.md) | [English](README.en.md) | [한국어](README.ko.md) | [Русский](README.ru.md) | [Deutsch](README.de.md) | [Français](README.fr.md) | [Español](README.es.md) | [Português](README.pt.md) | [हिन्दी](README.hi.md) | [العربية](README.ar.md) | [বাংলা](README.bn.md) | [Bahasa Indonesia](README.id.md) | [日本語](README.ja.md) | [مخطط العمارة](docs/ARCHITECTURE.ar.md) | [وثيقة التصميم](docs/DESIGN.ar.md) | [بنية الأمان](docs/SECURITY.ar.md) | [مرجع API](docs/API.ar.md)

# نظام إدارة مفتوح (open-admin)

<p align="center">
  <img src="public/img/pet.svg" width="150" height="200" alt="شياو آن — حيوان مشروع open-admin">
</p>

نظام إدارة خلفي متكامل مبني على **webman v2 + Flutter**: المصادقة وصلاحيات RBAC، ودفاع متعمق من 18 طبقة، وقابلية مراقبة عبر Prometheus، وعملاء متعددو المنصات (Flutter Web / HarmonyOS).

> حيوان المشروع «**شياو آن**» روبوت حارس درعي الشكل، يقف عند بوابتي «الحماية» و«التحقق من الهوية» في سلسلة الوسائط.
> المواد [`public/img/pet.svg`](public/img/pet.svg): SVG خالص (بلا سكربتات، بلا اعتماديات خارجية، يدعم الخلفية الداكنة و`prefers-reduced-motion`)، ويُستخدم أيضًا كأيقونة الموقع.
>
> رسوم التصميم: [عمارة النظام](docs/diagrams/architecture.svg) · [التصميم الوظيفي](docs/diagrams/features.svg) · [دورة الحياة](docs/diagrams/lifecycle.svg)

## حيوان المشروع · شياو آن

<table>
<tr>
<td width="170"><img src="public/img/pet.svg" width="150" height="200" alt="شياو آن"></td>
<td>

**شياو آن** (Xiao An) هو حيوان مشروع open-admin، مأخوذ من «**الأمان**» و«**لوحة الإدارة**» — روبوت حارس درعي الشكل.

- **الجسم الدرعي** — يقابل الدفاع المتعمق من 18 طبقة؛ والهوائي العلوي مضاء دائمًا، رمزًا لبقاء الخدمة مقيمة في الذاكرة (webman/workerman)
- **شاشتا العينين** — ترمشان على فترات منتظمة؛ تراقب كل طلب، وتراقب سلسلة الوسائط أيضًا
- **شارة التحقق على الصدر** — علامة الصح مضاءة دائمًا، رمزًا لنجاح التحقق من صلاحيات `method.path`

**وأين يظهر**

| الموضع | الشكل |
|------|------|
| الصفحة الرئيسية للموقع `GET /` | الصورة الرئيسية + تعريف المشروع وروابط الدخول ([`app/view/index/view.html`](app/view/index/view.html)) |
| معالج التثبيت `/install` | صورة رأس المعالج المكوّن من ثلاث خطوات (`InstallController::layout()`) |
| تبويب المتصفح | أيقونة الموقع `rel="icon" type="image/svg+xml"` |
| هذه الوثيقة ورسوم التصميم | صورة المشروع + بطاقة الحيوان في أسفل يمين مخطط العمارة |

> مصدر واحد للمواد: [`public/img/pet.svg`](public/img/pet.svg). تعديله يحدّث جميع المواضع أعلاه في الوقت نفسه.

</td>
</tr>
</table>

**أترسم واحدًا بنفسك؟** مواصفات الأيقونة: اللون الأساسي `#1677FF`، الهوائي الدافئ `#FA8C16`، أخضر التحقق `#52C41A`؛ لوحة الرسم `240 × 320`، والدرع في المنتصف، وأصغر حجم صالح 48 px.

## قائمة الميزات

| المجال | الميزة | الوصف |
|--------|------|------|
| 🔐 المصادقة | تسجيل الدخول / تحديث الرمز / تسجيل الخروج | كود تحقق بالنقر + JWT + قائمة سوداء |
| | قفل الحساب | 5 محاولات فاشلة تقفل الحساب لمدة 15 دقيقة |
| | حد الجلسات المتزامنة | 3 رموز نشطة كحد أقصى لنفس المستخدم |
| 📊 لوحة التحكم | إحصائيات فورية / رسم اتجاهي / رسم توزيعي / آخر العمليات | تخزين مؤقت في Redis لمدة 5 دقائق |
| 👥 إدارة المستخدمين | CRUD + حذف جماعي / تفعيل وتعطيل | حذف ناعم + تأكيد كلمة المرور مرتين |
| | استيراد جماعي من Excel | تحقق سطر بسطر + تقرير بالأخطاء |
| 🔒 الأدوار والصلاحيات | CRUD للأدوار + شجرة الصلاحيات | تحقق RBAC بدقة method.path |
| ⚙ إعدادات النظام | CRUD لأزواج المفاتيح والقيم | إدارة جماعية |
| 📋 تدقيق العمليات | الاستعلام عن السجلات + كشف جهة المصدر | تعرّف تلقائي على 8 منصات |
| 📁 إدارة الملفات | رفع / تصدير Excel / تصدير PDF | إخفاء تلقائي للبيانات الحساسة |
| 🛡 الحماية الأمنية | دفاع متعمق من 18 طبقة | XSS/حقن SQL/اجتياز المسار/حقن الأوامر/CSRF/تحديد المعدل/CSP... |
| 🏥 التشغيل والصيانة | فحص الصحة / المقاييس / توثيق API / security.txt | Prometheus + OpenAPI 3.0 + توثيق تفاعلي erikwang2013/apidoc-php |
| 🌐 التدويل | التبديل بين الصينية والإنجليزية | ترويسة Accept-Language / معامل ?lang= |

## التقنيات المستخدمة

| الطبقة | التقنية | الوصف |
|---|------|------|
| إطار العمل الخلفي | webman v2 (workerman) | إطار PHP فائق الأداء بعمليات مقيمة |
| إصدار PHP | 8.3+ | |
| قاعدة البيانات | MySQL 8.0+ | بادئة الجداول `erik_`، مفتاح أساسي BIGINT غير تلقائي الزيادة |
| محرك البحث | Elasticsearch | المزامنة والاستعلام عبر `webman-scout` |
| واجهة الإدارة | Flutter 3.x | الويب بتصميم لوحة إدارة للحاسوب المكتبي (`apps/flutter/`) |
| الهاتف المحمول | HarmonyOS ArkTS | عميل أصلي لنظام HarmonyOS (`apps/harmonyos/`)، يدعم الهاتف/الجهاز اللوحي/2in1 |

## الاعتماديات الأساسية

| الحزمة | الاستخدام |
|---|------|
| `erikwang2013/snowflake-php` | توليد مفاتيح أساسية BIGINT فريدة عالميًا عبر خوارزمية Snowflake |
| `erikwang2013/hashids` | تشفير وفك تشفير المعرفات في طبقة API لإخفاء المعرفات الحقيقية لقاعدة البيانات |
| `erikwang2013/jwt-webman` | إصدار رموز مصادقة JWT والتحقق منها |
| `erikwang2013/encryption` | تشفير وفك تشفير البيانات الحساسة في طبقة نقل الواجهات |
| `erikwang2013/encryptable` | تشفير وفك تشفير تلقائي للحقول الحساسة في طبقة تخزين قاعدة البيانات |
| `erikwang2013/webman-scout` | مزامنة بيانات Elasticsearch والبحث النصي الكامل |
| `erikwang2013/season` | بيانات أعلام الدول |
| `erikwang2013/poster-php` | توليد كود التحقق بالنقر والتحقق منه + توليد الملصقات |
| `phpoffice/phpspreadsheet` | تصدير Excel |
| `barryvdh/laravel-dompdf` | تصدير PDF (مبني على Dompdf) |

## هيكل المشروع

```
open-admin/
├── app/
│   ├── admin/controller/       # وحدات تحكم لوحة الإدارة
│   │   ├── DashboardController.php # لوحة التحكم (ذاكرة Redis المؤقتة)
│   │   ├── UserController.php      # CRUD للمستخدمين + عمليات جماعية
│   │   ├── RoleController.php      # CRUD للأدوار
│   │   ├── PermissionController.php# CRUD للصلاحيات
│   │   ├── ConfigController.php    # CRUD لإعدادات النظام
│   │   ├── LogController.php       # الاستعلام عن سجلات العمليات
│   │   ├── ProfileController.php   # الملف الشخصي + تسجيل الخروج
│   │   ├── ExportController.php    # تصدير Excel/PDF
│   │   ├── ImportController.php    # استيراد المستخدمين من Excel
│   │   ├── UploadController.php    # رفع الملفات
│   │   ├── HealthController.php    # فحص الصحة
│   │   ├── DocsController.php      # توثيق OpenAPI
│   │   └── BaseController.php      # وحدة التحكم الأساسية
│   ├── api/
│   │   └── v1/controller/          # وحدات تحكم API v1 (الإصدار يظهر في بادئة URL /api/v1)
│   │       ├── CaptchaController.php # كود التحقق بالنقر
│   │       └── AuthController.php    # تسجيل الدخول / تحديث الرمز
│   ├── common/                 # فئات الأدوات المشتركة
│   │   ├── HashidsService.php  # ترميز وفك ترميز المعرفات
│   │   ├── SnowflakeService.php# توليد معرفات Snowflake
│   │   └── EncryptionService.php # تشفير وفك تشفير البيانات + الإخفاء
│   ├── middleware/             # الوسائط
│   │   ├── Cors.php            # مشاركة الموارد عبر النطاقات
│   │   ├── SecurityFilter.php  # اعتراض هجمات الكشف (تقييد طرق HTTP/XSS/حقن SQL/اجتياز المسار/حقن الأوامر/CSRF)
│   │   ├── RateLimit.php       # تحديد معدل Redis (نافذة منزلقة + ترويسات استجابة)
│   │   ├── AdminAuth.php       # مصادقة JWT + قائمة سوداء
│   │   ├── AdminPermission.php # التحقق من صلاحيات RBAC
│   │   └── OperationLog.php    # تسجيل تلقائي لسجلات العمليات (يتضمن كشف جهة المصدر)
│   ├── model/                  # نماذج البيانات
│   ├── view/index/view.html    # قالب الصفحة الرئيسية للموقع (GET /، حيوان المشروع وروابط الدخول)
│   ├── queue/                  # مهام الطوابير
│   └── process/                # العمليات (Http, Monitor)
├── apps/
│   ├── flutter/                # لوحة إدارة Flutter Web (نمط PC)
│   │   └── lib/app/
│   │       ├── pages/          # 5 صفحات كاملة (لوحة التحكم/المستخدمون/الأدوار/الإعدادات/السجلات/الملف الشخصي)
│   │       ├── services/       # ApiService (اعتراض JWT) + AuthService (استمرار الرمز)
│   │       └── layouts/        # تخطيط لوحة إدارة متجاوب (شريط جانبي + شريط علوي + منطقة محتوى)
│   └── harmonyos/              # عميل HarmonyOS الأصلي (تحديث الرمز دون إشعار)
├── config/                     # ملفات الإعدادات (تتضمن تعليقات صينية)
│   ├── route.php               # المسارات + استراتيجية إصدار API
│   ├── middleware.php           # تسجيل الوسائط العامة
│   └── ...                     # إعدادات المكونات المختلفة
├── database/install.sql        # سكربت تثبيت SQL (يتضمن بيانات الصلاحيات الأولية)
├── docs/                       # الوثائق
│   ├── ARCHITECTURE.md         # رسم عمارة النظام (Mermaid)
│   ├── DESIGN.md               # وثيقة التصميم
│   ├── SECURITY.md             # تصميم بنية الأمان
│   ├── API.md                  # وثيقة مرجع API
│   ├── diagrams/               # دليل الرسوم البيانية
│   │   ├── architecture.svg    # رسم تصميم عمارة النظام (SVG)
│   │   ├── features.svg        # رسم التصميم الوظيفي (SVG)
│   │   ├── lifecycle.svg       # رسم دورة الحياة (SVG)
│   │   └── 01..12-*.md         # الرسوم المفصلة (Mermaid، 12 لغة مترجمة)
│   └── translations/           # وثائق متعددة اللغات (12 لغة × README/CLAUDE)
├── public/                     # نقطة الدخول العامة (جذر الويب)
│   ├── img/pet.svg             # حيوان المشروع «شياو آن» (SVG، يعمل أيضًا كأيقونة الموقع)
│   └── favicon.ico             # احتياطي .ico للمتصفحات القديمة
├── runtime/                    # ملفات وقت التشغيل
└── vendor/                     # اعتماديات Composer
```

## التصميم المعماري والرسوم البيانية

الرسوم الثلاثة جميعها **SVG مكتوبة يدويًا بالكامل** (بلا سكربتات، بلا اعتماديات خطوط خارجية، قابلة للتحجيم بلا حدود)، ويمكن عرضها مباشرة في GitHub / المتصفح، كما يمكن إدراجها في عروض PPT والوثائق:

| الرسم | المحتوى | الملف |
|---|------|------|
| تصميم عمارة النظام | طوبولوجيا من أربع طبقات: طبقة العملاء → طبقة البوابة → طبقة تطبيق webman (سلسلة الوسائط / وحدات التحكم / الخدمات المشتركة) → طبقة التخزين، مع الأمان وإمكانية المراقبة على اليمين | [`docs/diagrams/architecture.svg`](docs/diagrams/architecture.svg) |
| التصميم الوظيفي | 12 مجالًا وظيفيًا → مداخل وحدات التحكم → القدرات الرئيسية، وفي الأسفل سلسلة تنفيذ الوسائط ومواصفات واجهات البيانات | [`docs/diagrams/features.svg`](docs/diagrams/features.svg) |
| دورة الحياة | التثبيت → الإقلاع → الاتصال → الحماية → التحقق من الهوية → المعالجة → الاستمرارية → تدقيق الاستجابة، مع الفروع الاستثنائية ودورة حياة الرمز | [`docs/diagrams/lifecycle.svg`](docs/diagrams/lifecycle.svg) |

<img src="docs/diagrams/architecture.svg" width="1100" alt="رسم تصميم عمارة نظام open-admin">

<img src="docs/diagrams/features.svg" width="1100" alt="رسم التصميم الوظيفي لـ open-admin">

<img src="docs/diagrams/lifecycle.svg" width="1100" alt="رسم دورة حياة open-admin">

> للحصول على رسوم قابلة للتحرير على مستوى المصدر، راجع [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) و[`docs/diagrams/`](docs/diagrams/) (Mermaid، قابلة للصق في [Mermaid Live](https://mermaid.live/) للتحرير).

## متطلبات البيئة

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (مطلوب فقط لتطوير الواجهة الأمامية)
- Elasticsearch >= 7.x (اختياري، مطلوب لميزة البحث)

## البدء السريع

### 1. تثبيت الاعتماديات

```bash
composer install
```

### 2. ضبط متغيرات البيئة

انسخ وعدّل متغيرات البيئة (اختياري، إن لم تُضبط تُستخدم القيم الافتراضية في `config/*.php`):

```bash
cp .env.example .env
```

خيارات الإعداد الرئيسية:

| متغير البيئة | الوصف | القيمة الافتراضية |
|---------|------|--------|
| `JWT_SECRET` | مفتاح توقيع JWT | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | قيمة الملح لـ Hashids | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | مفتاح تشفير API | قيمة افتراضية 32 بايت |
| `SNOWFLAKE_DATACENTER_ID` | معرف مركز البيانات (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | معرف عقدة العمل (0-31) | `1` |
| `SCOUT_HOSTS` | عنوان ES | `http://localhost:9200` |

**في بيئة الإنتاج، يجب تغيير جميع المفاتيح إلى سلاسل عشوائية.**

### 3. التثبيت بنقرة واحدة

بعد تشغيل الخدمة، افتح المتصفح على معالج التثبيت لإكمال تهيئة قاعدة البيانات وإنشاء المدير:

```bash
php start.php start
```

يستمع افتراضيًا على `http://0.0.0.0:8787` (يمكن تغيير المنفذ في `config/server.php`).

افتح في المتصفح **`http://localhost:8787/install`**، ثم املأ وفقًا للمعالج:

| الخطوة | المحتوى |
|------|------|
| ① إعدادات قاعدة البيانات | عنوان المضيف والمنفذ واسم قاعدة البيانات واسم المستخدم وكلمة المرور |
| ② إعدادات المدير | اسم مستخدم المدير وكلمة المرور (الافتراضي admin / admin888) |

بعد النقر على «بدء التثبيت» يتم تلقائيًا إنشاء الجداول وزرع بيانات الصلاحيات وإنشاء حساب المدير، وكتابة إعدادات قاعدة البيانات في `.env`.

> بعد اكتمال التثبيت يُنشأ ملف القفل `runtime/install.lock`. لحذف الملف عند الحاجة إلى إعادة التثبيت.

### 4. تسجيل الدخول

قم بزيارة `http://localhost:8787` وسجّل الدخول بحساب المدير الذي أعددته أثناء التثبيت.

### 5. تشغيل الواجهة الأمامية (اختياري)

**لوحة إدارة Flutter (الويب):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # الويب (نمط لوحة إدارة PC)
```

**عميل HarmonyOS (الهاتف المحمول):**

افتح مجلد `apps/harmonyos/` باستخدام DevEco Studio، واربط جهازًا حقيقيًا أو محاكيًا للتشغيل.

### 6. النشر بنقرة واحدة عبر Docker Compose (موصى به للإنتاج)

يوفر المشروع حل ترتيب Docker كامل يتضمن 5 خدمات: Nginx وPHP (تطبيق webman) وMySQL وRedis وElasticsearch.

```bash
# 1. ضبط متغيرات بيئة Docker
cp .env.docker .env

# 2. تشغيل جميع الخدمات
docker-compose up -d

# 3. افتح المتصفح على معالج التثبيت لإكمال التهيئة
# http://localhost:8787/install  (املأ معلومات قاعدة البيانات والمدير)
# أو نفّذ ترحيل SQL يدويًا (داخل حاوية التطبيق):
# docker-compose exec app mysql -h mysql -u root -p < database/install.sql

# 4. الوصول
# http://localhost:8787  (webman)
# http://localhost:8080  (الوكيل العكسي Nginx)
```

- `Dockerfile`: PHP 8.3 + OPcache + Composer، مبني على `php:8.3-cli`
- `docker-compose.yml`: ترتيب 5 خدمات، عزل شبكة، استمرارية بيانات عبر وحدات التخزين
- `.env.docker`: متغيرات بيئة مخصصة لـ Docker


## مواصفات قاعدة البيانات

- **بادئة الجداول**: `erik_`
- **المفتاح الأساسي**: مفتاح جميع الجداول الأساسي هو `id BIGINT UNSIGNED NOT NULL`، مع **تعطيل AUTO_INCREMENT**
- **توليد المعرف**: يُولَّد المعرف الأساسي في طبقة التطبيق عبر `SnowflakeService::generate()`، فريد عبر الأنظمة الموزعة
- **الحقول الإلزامية**: يجب أن يتضمن كل جدول `id`, `created_at`, `updated_at`
- **الحذف الناعم**: الجداول التي تتطلب حذفًا ناعمًا تضيف `deleted_at DATETIME DEFAULT NULL`
- **الحقول الحساسة**: رقم الهاتف والبريد الإلكتروني ورقم الهوية وغيرها تُشفَّر وتُفكَّ تلقائيًا عبر إضافة `encryptable`، وتُخزَّن النصوص المشفرة في حقول `VARCHAR(500)`

## توثيق API

مرجع API الكامل (تنسيق الاستجابة الموحد، أكواد الأخطاء، تفاصيل جميع النقاط الطرفية، عملية المصادقة، استراتيجية تحديد المعدل، سلسلة الوسائط) في **[docs/API.ar.md](docs/API.ar.md)**، ومن أبرز النقاط:

- **تنسيق الاستجابة الموحد**: `{ "code": 0, "message": "success", "data": {...} }`، `code=0` تعني النجاح
- **أكواد الأخطاء**: `400` خطأ في المعاملات / `401` غير مسجّل الدخول / `403` لا صلاحية / `404` غير موجود / `422` فشل التحقق / `429` تجاوز معدل الطلبات / `500` خطأ في الخادم
- **إصدار API**: يظهر رقم الإصدار في بادئة URL (مثل `/api/v1/...`)، ولا يُستخدم ترويسة الطلب
- **المصادقة**: `Authorization: Bearer <token>`؛ صلاحية access_token ساعتان، وrefresh_token 14 يومًا
- **معالجة المعرفات**: المعرفات في الطلبات/الاستجابات عبارة عن سلاسل مشفرة بـ hashids، ولا تكشف معرفات قاعدة البيانات الحقيقية

## ملاحظات الواجهة الأمامية

### لوحة إدارة Flutter (نمط PC)

- **التخطيط**: شريط جانبي (قابل للطي 64px/240px) + شريط علوي + منطقة محتوى، ثلاث نقاط توقف متجاوبة (هاتف/جهاز لوحي/حاسوب مكتبي)
- **الصفحات**: تسجيل الدخول، لوحة التحكم، إدارة المستخدمين، الأدوار والصلاحيات، إعدادات النظام، سجلات العمليات، الملف الشخصي
- **إدارة الحالة**: GetX (`ApiService` كمفردة + استمرار الرمز عبر `AuthService`)
- **لوحة التحكم**: بطاقات إحصائية، رسم خطي للاتجاهات (fl_chart)، رسم دائري، سجلات آخر العمليات
- **التصدير**: تصدير Excel/PDF، ويحتوي PDF على معلومات حقوق نشر غير قابلة للإزالة
- **العمليات الجماعية**: حذف جماعي بالاختيار المتعدد، تفعيل/تعطيل جماعي
- **السمة**: Material 3 بنمطي فاتح/داكن

### هاتف HarmonyOS

- **الصفحات**: تسجيل الدخول، لوحة التحكم، قائمة/تفاصيل المستخدمين، الملف الشخصي
- **المصادقة**: JWT Bearer + تحديث الرمز تلقائيًا دون إشعار عند 401، وإعادة توجيه إلى صفحة الدخول عند فشل التحديث
- **التخزين**: الرمز يُدار عبر AppStorage

## معايير التطوير

- مراجع الدوال/الفئات العامة لا تُسبق بـ `\`، وتُستورد موحّدة عبر `use`
- يجب أن يتضمن رأس كل ملفات PHP بيان حقوق النشر
- يجب أن تتضمن جميع ملفات الإعدادات تعليقات صينية تشرح المعنى
- المفاتيح الأساسية لقاعدة البيانات يجب أن تُولَّد عبر snowflake في طبقة التطبيق، ويُمنع الزيادة التلقائية
- كل المعرفات في معاملات واستجابات طبقة API يجب أن تُشفَّر وتُفكَّ عبر hashids
- يستخدم وسيط AdminPermission ذاكرة Redis للتخزين المؤقت لصلاحيات المستخدم (TTL=60s) لإزالة اختناق استعلامات N+1

## النشر

### Docker Compose (موصى به)

يوفر جذر المشروع `docker-compose.yml` بترتيب 5 خدمات:

| الخدمة | الصورة | المنفذ |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | مبنية من `Dockerfile` محلي | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

تُبنى صورة PHP عبر `Dockerfile`، الصورة الأساسية `php:8.3-cli`، مع تفعيل OPcache.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

خط أنابيب التكامل المستمر عبر GitHub Actions: `.github/workflows/ci.yml`

- فحص بناء جملة PHP (`php -l`)
- اختبارات PHPUnit
- تحليل Flutter الثابت (`flutter analyze`)

### النسخ الاحتياطي لقاعدة البيانات

مجلد `database/backup/`:

- `backup.sh` — نسخ احتياطي mysqldump + gzip، مع تنظيف تلقائي للنسخ الأقدم من 30 يومًا
- `restore.sh` — استعادة تفاعلية، تعرض النسخ الاحتياطية المتاحة للاختيار

### إعدادات أمان Nginx

للنشر في الإنتاج، يُرجى الرجوع إلى `docs/nginx-security.conf` لتهيئة تقوية أمان الوكيل العكسي.

## المشروع مفتوح المصدر ليس بالأمر السهل — نرحب بدعمكم

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

### تبرع بالتحويل الدولي (حوالة عبر الحدود)

**معلومات المستلم**

- اسم المستلم: WANG KEXUN
- رقم حساب الاستلام: 881015918251

**البنك المستلم**

- رمز SWIFT لبنك ZA Bank: AABLHKHHXXX
- اسم البنك: ZA Bank Limited
- رقم البنك: 387
- عنوان البنك: Core F, Cyberport 3, 100 Cyberport Road, Hong Kong

**البنك الوكيل للتحويلات عبر الحدود (عند الحاجة)**

> هذه معلومات البنك الوكيل للتحويلات عبر الحدود (البنك الوسيط)، وليست معلومات البنك المستلم. يُرجى الاستفسار من البنك المُرسِل عما إذا كان يتطلب توفير معلومات البنك الوكيل للتحويلات عبر الحدود.

- **لإيداع دولار هونغ كونغ واليوان الصيني والدولار الأمريكي**، البنك الوكيل هو Citibank:
  - اسم البنك: Citibank N.A. Hong Kong
  - رمز SWIFT: CITIHKHXXXX
  - رقم البنك: 006
  - اسم الفرع: Hong Kong Branch
  - رقم الفرع: 391
  - عنوان البنك: Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong
- **للعملات الأخرى**، البنك الوكيل هو BNY Mellon:
  - اسم البنك: THE BANK OF NEW YORK MELLON
  - رمز SWIFT: IRVTUS3NXXX
  - عنوان البنك: THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States

### التبرع بالعملات الرقمية (Crypto Donation)

إذا كان هذا المشروع مفيدًا لك، فمرحبًا بمسح رمز الاستجابة السريعة للتبرع، شكرًا لك!

| الشبكة (Network) | رمز QR (QR Code) | عنوان المحفظة (Wallet Address) |
|---|---|---|
| BNB Smart Chain (BEP20) | [<img src="../../docs/coin/1.jpg" width="150" alt="BNB Smart Chain (BEP20)">](../../docs/coin/1.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Tron (TRC20) | [<img src="../../docs/coin/2.jpg" width="150" alt="Tron (TRC20)">](../../docs/coin/2.jpg) | `TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| Ethereum (ERC20) | [<img src="../../docs/coin/3.jpg" width="150" alt="Ethereum (ERC20)">](../../docs/coin/3.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Aptos | [<img src="../../docs/coin/4.jpg" width="150" alt="Aptos">](../../docs/coin/4.jpg) | `0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| Plasma | [<img src="../../docs/coin/5.jpg" width="150" alt="Plasma">](../../docs/coin/5.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Polygon POS | [<img src="../../docs/coin/6.jpg" width="150" alt="Polygon POS">](../../docs/coin/6.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Solana | [<img src="../../docs/coin/7.jpg" width="150" alt="Solana">](../../docs/coin/7.jpg) | `2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` |
| The Open Network (TON) | [<img src="../../docs/coin/8.jpg" width="150" alt="The Open Network (TON)">](../../docs/coin/8.jpg) | `UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| Arbitrum One | [<img src="../../docs/coin/9.jpg" width="150" alt="Arbitrum One">](../../docs/coin/9.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| AVAX C-Chain | [<img src="../../docs/coin/10.jpg" width="150" alt="AVAX C-Chain">](../../docs/coin/10.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |

---

## الترخيص

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
