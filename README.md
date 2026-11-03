# راصد الإشارة · SignalScope 1.1

نظام متكامل لقياس الشبكة الخلوية وتحليلها:

| الجزء | المجلد | الدور |
|---|---|---|
| تطبيق Android | `android/` + `web/` | قراءة حية، تشخيص، تسجيل بالخلفية، **ومزامنة offline-first مع خادمك** |
| الخادم | `server/` | PHP 8 + MySQL على استضافة cPanel: استقبال القراءات، تقدير مواقع الأبراج، التقارير |
| لوحة التحكم | `server/public/dashboard/` | خرائط الجلسات والتغطية والأبراج، تقارير قابلة للطباعة PDF، تصدير CSV وGeoJSON |
| GitHub Actions | `.github/workflows/` | بناء APK تلقائياً مع كل push، ونشر الخادم إلى الاستضافة عبر FTPS |

```
Android (تسجيل + طابور محلي)  ──HTTPS gzip──▶  public_html/api  ──▶  MySQL
                                               public_html/dashboard (لوحة المدير)
                                               signalscope/cron  (تقدير الأبراج كل 15 د)
```

## 1. التثبيت على الاستضافة (cPanel) — مرة واحدة

**الطريقة التلقائية (موصى بها):** cPanel ← **Git™ Version Control** ← Create، والصق رابط المستودع، ثم Manage ← Pull or Deploy ← **Deploy HEAD Commit**. أول Deploy ينشئ قاعدة البيانات ومستخدمها و`config.php` والجداول وحساب المدير ومهام cron تلقائياً، ويكتب الرابط وبيانات الدخول ورمز تسجيل الهواتف في `~/signalscope/SETUP-INFO.txt` (افتحه من File Manager ثم احذفه). سجل الإعداد في `~/signalscope/install.log`. إن تعذّر شيء، اتبع الطريقة اليدوية أدناه.

**الطريقة اليدوية:**

**أ) قاعدة البيانات:** cPanel ← **MySQL® Databases**
1. أنشئ قاعدة باسم `signalscope` (ستصبح `CPANELUSER_signalscope`).
2. أنشئ مستخدماً بكلمة مرور قوية، وأضفه للقاعدة مع **ALL PRIVILEGES**.

**ب) رفع الملفات:** cPanel ← **File Manager** ← مجلد المنزل (`/home/CPANELUSER`، وليس public_html)
1. ارفع `signalscope-hosting.zip` ثم **Extract**. سينتج:
   - `public_html/api/` واجهة API
   - `public_html/dashboard/` لوحة التحكم
   - `public_html/app/` نسخة الويب من التطبيق
   - `signalscope/` الكود الخاص والإعدادات، **خارج** public_html
2. في `signalscope/` انسخ `config.example.php` باسم `config.php` وعدّل:
   - `db`: اسم القاعدة والمستخدم وكلمة المرور.
   - `enroll_key`: رمز يُدخل في الهاتف لتسجيله.
   - `setup_key`: رمز لإنشاء حساب المدير أول مرة.
   - `opencellid_key` (اختياري): مفتاح مجاني من opencellid.org.

**ج) PHP:** cPanel ← **MultiPHP Manager**: اختر PHP 8.1 أو أحدث للنطاق.

**د) أول دخول:** افتح `https://نطاقك/dashboard/`. أدخل `setup_key` واسم مستخدم وكلمة مرور، فتُنشأ الجداول تلقائياً. لا حاجة لاستيراد أي ملف عبر phpMyAdmin. يمكن استيراد `signalscope/schema.sql` يدوياً إن فضّلت.

**هـ) المهام المجدولة:** cPanel ← **Cron Jobs** (استبدل CPANELUSER):
```
*/15 * * * *  /usr/local/bin/php /home/CPANELUSER/signalscope/cron/estimate_towers.php >/dev/null 2>&1
17 3 * * *    /usr/local/bin/php /home/CPANELUSER/signalscope/cron/cleanup.php >/dev/null 2>&1
```

**و) HTTPS:** cPanel ← **SSL/TLS Status** ← Run AutoSSL. التطبيق يرفض الاتصال بدون HTTPS.

**اختبار سريع:** `https://نطاقك/api/v1/ping` يجب أن يعيد `{"ok":true,...}`.

> إن كان الموقع على نطاق فرعي بمجلد مختلف عن public_html، ضع `api/` و`dashboard/` في مجلده. يبحث `api/index.php` عن `signalscope/` في المجلد الأعلى بمستويين؛ إن اختلف المسار عدّل السطر الأول في `api/index.php`.

## 2. ربط الهاتف

1. ثبّت APK (من GitHub Releases، أو ابنه كما في القسم 4).
2. التطبيق ← الإعدادات ← **المزامنة مع الخادم**: أدخل `https://نطاقك` و`enroll_key` واسماً للجهاز.
3. ابدأ التسجيل. تُكتب القراءات في طابور على الهاتف وتُرفع كل دقيقتين تقريباً عند توفر الشبكة. إن انقطع الإنترنت تنتظر ولا تضيع.
4. الجلسة تظهر في اللوحة ← الجلسات. تقديرات الأبراج تظهر بعد أول تشغيل لمهمة cron.

إلغاء جهاز من اللوحة ← الأجهزة يوقف قبول بياناته فوراً.

## 3. النشر التلقائي من GitHub

بعد رفع المستودع إلى GitHub، أضف في **Settings ← Secrets and variables ← Actions**:

| السر | القيمة |
|---|---|
| `FTP_SERVER` | خادم FTP في cPanel، مثل `iqserver.iq-hosting.com` |
| `FTP_USERNAME` | مستخدم FTP (من cPanel ← FTP Accounts؛ يُفضّل حساب جذره مجلد المنزل) |
| `FTP_PASSWORD` | كلمة مروره |
| `FTP_SERVER_DIR` | اختياري: المسار على الخادم إن لم يكن جذر الحساب مجلد المنزل، مثلاً `/` |

بعدها:
- كل push يغيّر `server/` أو `web/` ← ينشر إلى الاستضافة (**Deploy to cPanel**). يرفع الملفات المتغيرة فقط ولا يلمس `config.php`.
- كل push يغيّر `android/` أو `web/` ← يبني APK ويضعه في إصدار **latest** على صفحة Releases.
- وسم مثل `v1.1.0` (`git tag v1.1.0 && git push --tags`) ← إصدار دائم.

بديل بلا FTP ولا أسرار: cPanel ← **Git™ Version Control** ← Create، والصق رابط المستودع، ثم Manage ← Pull or Deploy ← **Deploy HEAD Commit**. يستخدم ملف `.cpanel.yml` ويكتشف مجلد المنزل تلقائياً. للتحديث لاحقاً: **Update from Remote** ثم **Deploy HEAD Commit**.

### مفتاح توقيع ثابت (مستحسن)
بدونه يوقّع كل بناء بمفتاح مختلف، فيرفض Android تثبيت التحديث فوق القديم وتضطر لإزالة التطبيق. أنشئ مفتاحاً مرة واحدة على أي حاسوب فيه Java:
```
keytool -genkeypair -v -keystore release.jks -alias signalscope -keyalg RSA -keysize 2048 -validity 10000
base64 -w0 release.jks > release.b64
```
وأضف الأسرار: `SIGNING_KEYSTORE_BASE64` (محتوى release.b64)، `SIGNING_STORE_PASSWORD`، `SIGNING_KEY_ALIAS` (=`signalscope`)، `SIGNING_KEY_PASSWORD`. احتفظ بنسخة من release.jks في مكان آمن.

## 4. البناء يدوياً
- **APK:** افتح `android/` في Android Studio (Ladybug 2024.2+) ← Build APK، أو `cd android && ./gradlew assembleRelease`.
- **ملف الاستضافة:** `tools/build-hosting.sh --zip` ← `dist/signalscope-hosting.zip`.
- **نسخة الحاسوب:** https://cybersecurity2025e068-arch.github.io/Signal-Scope/ (تُنشر تلقائياً من `web/`؛ فعّلها مرة واحدة من Settings ← Pages ← Source: **GitHub Actions**). افتحها في Chrome أو Edge ثم «تثبيت التطبيق» من شريط العنوان لتصبح تطبيقاً على سطح المكتب. تتوفر أيضاً على استضافتك في `https://نطاقك/app/`، أو محلياً: `cd web && python3 -m http.server 8080`.

## 5. واجهة API

| المسار | الصلاحية | الوظيفة |
|---|---|---|
| `GET /api/v1/ping` | — | فحص الخادم |
| `POST /api/v1/register` | `enroll_key` | تسجيل جهاز، يعيد رمزاً خاصاً به |
| `POST /api/v1/ingest` | رمز الجهاز (Bearer) | رفع دفعة قراءات (JSON، يقبل gzip). الرفع idempotent |
| `GET /api/v1/overview`، `/sessions`، `/sessions/{id}`، `/sessions/{id}/geojson`، `/sessions/{id}/csv` | المدير | الجلسات والتقارير |
| `GET /api/v1/cells`، `/cells/{id}` | المدير | الخلايا والأبراج المقدّرة |
| `GET /api/v1/coverage?size=&bbox=&mcc=&mnc=&tech=&from=&to=&hour_from=&hour_to=` | المدير | شبكة التغطية GeoJSON |
| `GET /api/v1/devices`، `POST /devices/{id}/revoke|restore|rename` | المدير | إدارة الأجهزة |

## 6. كيف يُقدَّر موقع البرج
Android لا يعطي موقع البرج، فيقدّره الخادم من قياساتك (`server/app/lib/TowerEstimator.php`):
1. تجميع القراءات في مربعات 20 m حتى لا يطغى الوقوف الطويل في مكان واحد.
2. بحث شبكي عن الموقع الذي يجعل `RSRP = A − 10·n·log10(d)` أفضل ملاءمة، مع قيد 1.6 ≤ n ≤ 5، وعقوبة على مخالفة مسافة Timing Advance (78 m لكل وحدة في LTE).
3. نصف قطر الثقة: أبعد موقع مرشّح يبقى خطؤه ضمن الحد الإحصائي من الأفضل.

في اختبار محاكاة (3000 قراءة على مسار عشوائي حول برجين) وقع التقدير على بعد نحو 40 m من الموقع الحقيقي. في الميدان تعتمد الدقة على تنوع أماكن القياس حول البرج. القياس من جهة واحدة يعطي نصف قطر ثقة كبيراً، واللوحة تعرضه. **هذا تقدير نموذجي وليس موقعاً مسجّلاً**، والتقارير تقول ذلك صراحة.

## 7. الخصوصية
- كل جهاز له رمز يُخزَّن في القاعدة كـ SHA-256 فقط، ويمكن إلغاؤه.
- لوحة التحكم: كلمات مرور bcrypt، حماية CSRF، وقفل بعد 10 محاولات دخول فاشلة.
- القراءات الخام تُحذف بعد `retention_days` (افتراضياً 365 يوماً).
- المزامنة اختيارية في التطبيق، ويمكن إيقافها أو فصل الجهاز في أي وقت.

## واجهة الجسر (Kotlin ↔ JavaScript)
| الدالة | الإرجاع |
|---|---|
| `getSnapshot()` | JSON: `serving`, `neighbors`, `wifi`, `scan`, `location`, `network`, `permissions`, `device` |
| `requestPermissions()` | يطلب الناقص ثم يستدعي `SS.onPermissions(json)` |
| `scanWifi()` | `true` إن قَبِل النظام المسح (حد 4 مرات / دقيقتين) |
| `startRecording(ms)` / `stopRecording()` | `{ok, file, rows}`، والملفات في `Android/data/com.signalscope.app/files/exports/` |
| `saveText(name, mime, content)` | يحفظ ويفتح قائمة المشاركة |
| `runSpeedTest()` | تقدّم عبر `SS.onSpeedProgress(stage,pct,mbps)` ونتيجة عبر `SS.onSpeedResult(json)` |
| `startMonitor(ms, alertDbm)` / `stopMonitor()` / `isMonitoring()` | مراقبة بالخلفية + إشعار عند ضعف الإشارة |
| `connectServer(url, key, name)` | يسجّل الجهاز في الخادم، والنتيجة عبر `SS.onSyncResult(json)` |
| `getSyncStatus()` / `syncNow()` / `setSyncEnabled(b)` / `disconnectServer()` | حالة المزامنة والتحكم بها |

الدفع الفوري: عند كل تغيّر راديوي يستدعي Kotlin `SS.onSnapshot(json)`، بفاصل إزالة ارتداد 400 ms.

## إصلاحات مقارنة بالمسودة السابقة (CellularSimulator)
- **NR-ARFCN**: المعادلة كانت `0.05 × N` (خطأ بوحدات القياس) ← الصحيح `0.005 × N` MHz تحت 3 GHz حسب TS 38.104.
- **n77/n78 و n1/n66** متداخلة؛ التطبيق يعرض كل المرشحين ويستخدم `getBands()` (Android 11+) للنطاق الفعلي.
- **الجسر**: كان يُضاف بعد `loadUrl` فلا يراه أول تحميل ← يُضاف الآن قبله.
- **أمان WebView**: أُزيل `allowUniversalAccessFromFileURLs` و`MIXED_CONTENT_ALWAYS_ALLOW` و`usesCleartextTraffic`. الملفات تُخدم عبر `WebViewAssetLoader` من أصل HTTPS، والروابط الخارجية تُفتح في المتصفح فلا يصل أي موقع خارجي إلى الجسر.
- **ثنائي الشريحة**: القراءة من شريحة البيانات الافتراضية بدل أول خلية مسجّلة.
- **قيم RSSNR**: بعض المودمات تعيدها بوحدة 0.1 dB، ويُكتشف ذلك ويُصحَّح.
- **5G NSA**: تُقرأ ساق NR من `SignalStrength` لأنها لا تظهر في `allCellInfo`.
- **Speedtest**: كان ملفاً واحداً بحجم 10 MB (يقلّل تقدير الوصلات السريعة بسبب TCP slow-start) ← مراحل متدرجة حتى 8 ثوانٍ، مع jitter.
- **الخدمة الأمامية**: النوع `location` مطلوب في Android 14 لقراءة الخلايا مع إطفاء الشاشة.

## حدود المنصات
- iOS لا يتيح أي API عامة لقراءة RSRP/SINR، لذلك يُستخدم الإدخال اليدوي من Field Test.
- المحاكي (Emulator) لا يعيد بيانات خلايا حقيقية. استخدم هاتفاً بشريحة.
- بعض الشركات المصنّعة تُخفي الجيران أو TA أو SINR؛ التطبيق يعرض «—» بدل قيم وهمية.
