<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'php_version', 'message' => 'يلزم PHP 7.4 أو أحدث، والإصدار الحالي ' . PHP_VERSION . '. اختر PHP 7.4+ للنطاق من cPanel ← MultiPHP Manager.']);
    exit;
}

// موقع مجلد التطبيق الخاص (خارج public_html). عدّل المسار إن وضعته في مكان آخر.
$candidates = [
    getenv('SIGNALSCOPE_APP') ?: '',
    __DIR__ . '/../../signalscope',      // cPanel: /home/USER/signalscope
    __DIR__ . '/../../app',              // بنية المستودع: server/app
];
// مجلد المنزل لمستخدم الاستضافة (يعمل مهما كان عمق مجلد الموقع)
$home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
if ($home) $candidates[] = $home . '/signalscope';
foreach ($candidates as $dir) {
    if ($dir && is_file($dir . '/bootstrap.php')) { require $dir . '/bootstrap.php'; break; }
}
if (!defined('SS_APP_DIR')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"app_dir_missing","message":"مجلد signalscope غير موجود خارج public_html"}';
    exit;
}

ini_set('display_errors', '0'); // تحذيرات PHP لا تُطبع داخل ردود JSON
ini_set('session.gc_divisor', '100');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$path = '/' . trim(substr($uri, strlen($base)), '/');
if (strpos($path, '/index.php') === 0) $path = substr($path, 10) ?: '/';
// بديل يعمل على أي خادم: index.php?r=/v1/ping (بلا mod_rewrite ولا PATH_INFO)
if (isset($_GET['r']) && is_string($_GET['r'])) $path = '/' . trim($_GET['r'], '/');

try {
    // ---------- واجهة الهاتف ----------
    if ($path === '/v1/ping') {
        Http::json(['ok' => true, 'version' => SS_VERSION, 'time' => (int)(microtime(true) * 1000)]);
    }

    if ($path === '/v1/register' && $method === 'POST') {
        $b = Http::body(64_000);
        $db = ss_db();
        $st = $db->prepare('SELECT COUNT(*) FROM login_fail WHERE ip = ? AND ts > ?');
        $st->execute([Http::ip(), time() - 900]);
        if ((int)$st->fetchColumn() >= 10) throw new HttpError(429, 'محاولات كثيرة. حاول بعد 15 دقيقة', 'rate_limited');
        // المقارنة تتجاهل حالة الأحرف والمسافات والشرطات: ABCDE-23456 = abcde 23456
        if (!hash_equals(Auth::normalizeCode((string)ss_config()['enroll_key']), Auth::normalizeCode((string)($b['enroll_key'] ?? '')))) {
            $db->prepare('INSERT INTO login_fail (ip, ts) VALUES (?, ?)')->execute([Http::ip(), time()]);
            usleep(500_000);
            throw new HttpError(403, 'رمز التسجيل غير صحيح. انسخه من ملف PHONE-CODE.txt في مجلد signalscope', 'bad_enroll_key');
        }
        $name = trim(mb_substr((string)($b['name'] ?? ''), 0, 100));
        if ($name === '') $name = 'جهاز ' . date('Y-m-d');
        $token = Auth::newToken();
        ss_db()->prepare('INSERT INTO devices (name, model, android, app_version, token_hash, last_seen, last_ip) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)')
            ->execute([$name, mb_substr((string)($b['model'] ?? ''), 0, 100), mb_substr((string)($b['android'] ?? ''), 0, 20),
                       mb_substr((string)($b['app_version'] ?? ''), 0, 20), Auth::hashToken($token), Http::ip()]);
        Http::json(['ok' => true, 'device_id' => (int)ss_db()->lastInsertId(), 'token' => $token, 'name' => $name], 201);
    }

    if ($path === '/v1/ingest' && $method === 'POST') {
        $dev = Auth::device();
        Http::json(Ingest::handle($dev, Http::body()));
    }

    if ($path === '/v1/device/me' && $method === 'GET') {
        $dev = Auth::device();
        Http::json(['id' => (int)$dev['id'], 'name' => $dev['name']]);
    }

    // ---------- مركز إدارة البوابة: نقاط عامة تقرؤها صفحات البوابتين ----------
    if ($path === '/v1/portal/config' || $path === '/v1/portal/hit') {
        // صفحات البوابة على it-basra.com وصفحات الكنترول والحضور على GitHub Pages
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if (preg_match('#^https://(it-basra\.com|www\.it-basra\.com|drdshsw2022-byte\.github\.io)$#', $origin)) {
            header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST'); header('Access-Control-Allow-Headers: Content-Type');
        }
        if ($method === 'OPTIONS') { http_response_code(204); exit; }
        if ($path === '/v1/portal/hit') {
            $b = json_decode((string)file_get_contents('php://input', false, null, 0, 2000), true) ?: $_POST;
            try { Portal::hit((string)($b['s'] ?? ''), (string)($b['d'] ?? '')); } catch (Throwable $e) { error_log('[portal hit] ' . $e->getMessage()); }
            http_response_code(204); exit;
        }
        header('Cache-Control: public, max-age=60');
        Http::json(Portal::publicConfig());
    }
    if ($path === '/v1/portal/session' && $method === 'POST') Http::json(['ok' => true, 'me' => Portal::login((string)(Http::body(16_000)['id_token'] ?? ''))]);
    if ($path === '/v1/portal/logout' && $method === 'POST') { Portal::logout(); Http::json(['ok' => true]); }
    if (strpos($path, '/v1/portal/') === 0) {
        $me = Portal::me();
        $b = $method === 'POST' || $method === 'DELETE' ? Http::body(64_000) : [];
        if ($path === '/v1/portal/me') Http::json(['me' => $me, 'roles' => Portal::ROLES, 'statuses' => Portal::STATUSES, 'project' => FirebaseToken::project()]);
        if ($path === '/v1/portal/users') Http::json(Portal::users($me));
        if ($path === '/v1/portal/users/save' && $method === 'POST') Http::json(Portal::saveUser($me, $b));
        if ($path === '/v1/portal/users/delete' && $method === 'POST') Http::json(Portal::deleteUser($me, (string)($b['email'] ?? '')));
        if ($path === '/v1/portal/blocked' && $method === 'POST') Http::json(Portal::block($me, (string)($b['email'] ?? ''), (string)($b['reason'] ?? ''), !empty($b['on'])));
        if ($path === '/v1/portal/sections') Http::json(['sections' => Portal::sections()]);
        if ($path === '/v1/portal/sections/save' && $method === 'POST') Http::json(Portal::saveSection($me, $b));
        if ($path === '/v1/portal/sections/delete' && $method === 'POST') Http::json(Portal::deleteSection($me, (string)($b['id'] ?? '')));
        if ($path === '/v1/portal/news') Http::json(['news' => Portal::news($me)]);
        if ($path === '/v1/portal/news/save' && $method === 'POST') Http::json(Portal::saveNews($me, $b));
        if ($path === '/v1/portal/news/delete' && $method === 'POST') Http::json(Portal::deleteNews($me, (int)($b['id'] ?? 0)));
        if ($path === '/v1/portal/stats') Http::json(Portal::stats($me, Http::intParam('days', 30, 1, 365)));
        if ($path === '/v1/portal/audit') Http::json(['log' => Portal::auditLog($me, Http::intParam('limit', 300, 1, 2000))]);
        if ($path === '/v1/portal/colleges') Http::json(Portal::colleges());
        if ($path === '/v1/portal/dash-admins') Http::json(['admins' => Portal::dashAdmins($me)]);
        if ($path === '/v1/portal/dash-password' && $method === 'POST') Http::json(Portal::setDashPassword($me, (string)($b['username'] ?? ''), (string)($b['password'] ?? '')));
        throw new HttpError(404, 'المسار غير موجود: ' . $path, 'not_found');
    }

    // ---------- التحديث الذاتي من GitHub (محمي بـ X-Deploy-Token) ----------
    if ($path === '/v1/admin/deploy') {
        $db = ss_db();
        $st = $db->prepare('SELECT COUNT(*) FROM login_fail WHERE ip = ? AND ts > ?');
        $st->execute([Http::ip(), time() - 900]);
        if ((int)$st->fetchColumn() >= 10) throw new HttpError(429, 'محاولات كثيرة', 'rate_limited');
        $want = (string)(ss_config()['deploy_token'] ?? '');
        $given = trim((string)($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? ''));
        if (strlen($want) < 24 || !hash_equals($want, $given)) {
            $db->prepare('INSERT INTO login_fail (ip, ts) VALUES (?, ?)')->execute([Http::ip(), time()]);
            throw new HttpError(403, 'رمز النشر غير صحيح', 'bad_deploy_token');
        }
        if ($method === 'POST' && ($_GET['action'] ?? '') === 'analyze') {
            @set_time_limit(200);
            $log = [];
            try { $r = Obstacles::run(60.0, $log); }
            catch (Throwable $ex) {   // التفاصيل تظهر فقط لحامل رمز النشر
                Http::json(['ok' => false, 'error' => get_class($ex) . ': ' . $ex->getMessage(), 'at' => basename($ex->getFile()) . ':' . $ex->getLine(), 'log' => $log], 500);
            }
            Http::json(['ok' => true, 'run' => $r, 'log' => array_slice($log, 0, 50), 'status' => Obstacles::status()]);
        }
        if ($method === 'POST' && ($_GET['action'] ?? '') === 'rotate') {
            // مفتاح جديد يُكتب في config.php و DEPLOY-TOKEN.txt فقط، ولا يُعاد في الرد
            $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
            $tok = '';
            for ($i = 0; $i < 40; $i++) $tok .= $abc[random_int(0, strlen($abc) - 1)];
            $file = getenv('SIGNALSCOPE_CONFIG') ?: SS_APP_DIR . '/config.php';
            $src = (string)file_get_contents($file);
            $new = preg_replace("/('deploy_token'\s*=>\s*)'[^']*'/", "\${1}'$tok'", $src, 1, $n);
            if ($new === null || $n !== 1) throw new HttpError(500, 'deploy_token غير موجود في config.php', 'rotate_failed');
            file_put_contents($file, $new);
            @chmod($file, 0600);
            $info = SS_APP_DIR . '/DEPLOY-TOKEN.txt';
            file_put_contents($info, "رمز النشر التلقائي الجديد (X-Deploy-Token) لراصد الإشارة:\n\n$tok\n\nالصقه في نافذة Claude الآمنة فقط، ثم احذف هذا الملف. الرمز القديم أُلغي.\n");
            @chmod($info, 0600);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            Http::json(['ok' => true, 'message' => 'أُنشئ رمز جديد في signalscope/DEPLOY-TOKEN.txt وأُلغي القديم', 'at' => gmdate('c')]);
        }
        if ($method === 'POST' && ($_GET['action'] ?? '') === 'amu-link') {
            // روابط «منظومة راصد الإشارة» في بوابة القسم /amu/ (إضافة قابلة للتراجع بـ mode=revert)
            $doc = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
            Http::json(($_GET['mode'] ?? '') === 'revert' ? AmuLink::revert($doc) : AmuLink::apply($doc));
        }
        if ($method === 'POST' && ($_GET['action'] ?? '') === 'amu-nav') {
            // تعديل منفصل وصريح لقائمة «أنظمة المعقل» المشتركة (amu-nav.js) — mode=revert للتراجع
            $doc = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
            Http::json(['ok' => true, 'nav' => ($_GET['mode'] ?? '') === 'revert' ? AmuLink::revertNav($doc) : AmuLink::applyNav($doc)]);
        }
        if (($_GET['action'] ?? '') === 'intf') {   // تشغيل/فحص عزل التشويش (إحصاءات مجمعة فقط)
            @set_time_limit(200);
            $run = $method === 'POST' ? Interference::run(90.0) : null;
            $db = ss_db();
            $rows = function (string $sql) use ($db) { return $db->query($sql)->fetchAll(); };
            Http::json(['ok' => true, 'run' => $run, 'status' => Interference::status(), 'summary' => Interference::summary([]),
                'sources' => array_map(function ($x) { unset($x['hourly']); return $x; }, Interference::sources([])),
                'aggressors' => array_slice(Interference::aggressors([])['aggressors'], 0, 8),
                'sinr_by_rsrp' => $rows("SELECT device_id, FLOOR(rsrp / 10) * 10 band, COUNT(*) n, ROUND(AVG(sinr), 1) sinr, MIN(sinr) mn, MAX(sinr) mx,
                    ROUND(AVG(rsrq), 1) rsrq FROM samples WHERE tech = 'LTE' AND rsrp IS NOT NULL GROUP BY device_id, band ORDER BY device_id, band"),
                'by_src' => $rows("SELECT i.src, i.cls, COUNT(*) n, ROUND(AVG(i.deg),1) deg, ROUND(AVG(s.rsrp),1) rsrp, ROUND(AVG(s.rsrq),1) rsrq, ROUND(AVG(i.sinr_meas),1) sinr,
                    ROUND(AVG(i.n_cochan),2) nco, ROUND(AVG(s.speed),1) spd, COUNT(DISTINCT s.session_id) sess, COUNT(DISTINCT s.cell_ref) cells
                    FROM sample_intf i JOIN samples s ON s.id = i.sample_id GROUP BY i.src, i.cls ORDER BY i.src, n DESC"),
                'rsrq_vs_sinr' => $rows("SELECT FLOOR(rsrq) q, COUNT(*) n, ROUND(AVG(sinr),1) sinr, ROUND(AVG(rsrp),1) rsrp FROM samples WHERE tech = 'LTE' AND sinr IS NOT NULL AND rsrq IS NOT NULL GROUP BY q ORDER BY q"),
                'nb_count' => $rows("SELECT i.n_cochan k, COUNT(*) n, ROUND(AVG(i.deg),1) deg FROM sample_intf i GROUP BY k ORDER BY k LIMIT 12"),
                'nb' => $rows("SELECT device_id, COUNT(*) n, SUM(neighbors IS NOT NULL) with_nb, SUM(neighbors LIKE '%earfcn%') with_earfcn,
                    SUM(neighbors LIKE '%rsrp%') with_rsrp, SUM(sinr IS NOT NULL) with_sinr, SUM(rsrq IS NOT NULL) with_rsrq FROM samples WHERE tech = 'LTE' GROUP BY device_id")]);
        }
        if ($method === 'POST' && ($_GET['action'] ?? '') === 'backup') Http::json(Backup::run());
        if ($method === 'POST' && ($_GET['action'] ?? '') === 'alerts') Http::json(['scan' => Alerts::scan(), 'open' => Alerts::openCount()]);
        if (($_GET['action'] ?? '') === 'diag') {   // إحصاءات مجمعة للتحقق من التحليل (بلا بيانات شخصية)
            $db = ss_db();
            $rows = function (string $sql) use ($db) { return $db->query($sql)->fetchAll(); };
            Http::json(['ok' => true,
                'by_speed' => $rows("SELECT CASE WHEN s.speed IS NULL THEN 'unknown' WHEN s.speed < 1 THEN '0-1' WHEN s.speed < 3 THEN '1-3' WHEN s.speed < 8 THEN '3-8' ELSE '8+' END band,
                    COUNT(*) n, ROUND(100*AVG(l.indoor > 0),1) indoor_pct, ROUND(100*AVG(l.n_buildings > 0),1) blocked_pct, ROUND(AVG(l.excess),1) excess, ROUND(AVG(s.acc),1) acc
                    FROM sample_loss l JOIN samples s ON s.id = l.sample_id GROUP BY band ORDER BY band"),
                'by_dist' => $rows("SELECT CASE WHEN l.dist_m < 100 THEN '<100' WHEN l.dist_m < 300 THEN '100-300' WHEN l.dist_m < 700 THEN '300-700' WHEN l.dist_m < 1500 THEN '700-1500' ELSE '1500+' END band,
                    COUNT(*) n, ROUND(100*AVG(l.n_buildings > 0),1) blocked_pct, ROUND(AVG(l.buildings),1) bld_db, ROUND(AVG(l.excess),1) excess
                    FROM sample_loss l GROUP BY band ORDER BY MIN(l.dist_m)"),
                'acc' => $rows("SELECT ROUND(AVG(acc),1) avg_acc, ROUND(100*AVG(acc > 15),1) pct_over_15m FROM samples WHERE lat IS NOT NULL"),
                'cells_radius' => $rows("SELECT CASE WHEN est_radius_m < 100 THEN '<100' WHEN est_radius_m < 300 THEN '100-300' WHEN est_radius_m < 1500 THEN '300-1500' ELSE '1500+' END band, COUNT(*) n
                    FROM cells WHERE est_lat IS NOT NULL GROUP BY band")]);
        }
        if ($method === 'POST') Http::json(Updater::run((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
        $f = SS_APP_DIR . '/data/deployed.json';
        $q = function (string $sql) { return (int)ss_db()->query($sql)->fetchColumn(); };
        Http::json(['ok' => true, 'version' => SS_VERSION, 'deployed' => is_file($f) ? json_decode((string)file_get_contents($f), true) : null,
            'counts' => ['devices' => $q('SELECT COUNT(*) FROM devices'), 'sessions' => $q('SELECT COUNT(*) FROM sessions'),
                         'samples' => $q('SELECT COUNT(*) FROM samples'), 'cells' => $q('SELECT COUNT(*) FROM cells'),
                         'cells_located' => $q("SELECT COUNT(*) FROM cells WHERE est_lat IS NOT NULL AND est_method <> 'insufficient'")],
            'obstacles' => Obstacles::status() + ['summary' => Obstacles::summary('1=1', [])],
            'cron' => is_file(SS_APP_DIR . '/data/last-cron.json') ? json_decode((string)file_get_contents(SS_APP_DIR . '/data/last-cron.json'), true) : null,
            'alerts_open' => Alerts::openCount(), 'backups' => array_slice(Backup::list(), 0, 3)]);
    }

    // ---------- المدير ----------
    if ($path === '/v1/admin/setup' && $method === 'POST') {
        $b = Http::body(64_000);
        $db = ss_db();
        if (!hash_equals((string)ss_config()['setup_key'], (string)($b['setup_key'] ?? ''))) throw new HttpError(403, 'رمز الإعداد غير صحيح', 'bad_setup_key');
        Schema::apply($db); // ينشئ الجداول الناقصة (آمن للتكرار)
        if ((int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) throw new HttpError(409, 'يوجد حساب مدير مسبقاً', 'already_setup');
        if (!hash_equals((string)ss_config()['setup_key'], (string)($b['setup_key'] ?? ''))) throw new HttpError(403, 'رمز الإعداد غير صحيح', 'bad_setup_key');
        $u = trim((string)($b['username'] ?? ''));
        $p = (string)($b['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $u)) throw new HttpError(400, 'اسم المستخدم: 3–64 حرفاً لاتينياً أو أرقاماً', 'bad_username');
        if (strlen($p) < 10) throw new HttpError(400, 'كلمة المرور 10 أحرف على الأقل', 'weak_password');
        $db->prepare('INSERT INTO admins (username, pass_hash) VALUES (?, ?)')->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
        Http::json(['ok' => true, 'admin' => Auth::login($u, $p)], 201);
    }

    if ($path === '/v1/admin/status' && $method === 'GET') {
        Auth::startSession();
        $hasAdmin = Schema::installed(ss_db()) && (int)ss_db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
        Http::json(['setup_done' => $hasAdmin, 'logged_in' => !empty($_SESSION['admin_id']), 'username' => $_SESSION['admin_user'] ?? null]);
    }

    if ($path === '/v1/admin/login' && $method === 'POST') {
        $b = Http::body(64_000);
        Http::json(['ok' => true, 'admin' => Auth::login((string)($b['username'] ?? ''), (string)($b['password'] ?? ''))]);
    }

    if ($path === '/v1/admin/logout' && $method === 'POST') {
        Auth::logout();
        Http::json(['ok' => true]);
    }

    if ($path === '/v1/admin/password' && $method === 'POST') {
        $a = Auth::admin();
        $b = Http::body(64_000);
        $st = ss_db()->prepare('SELECT pass_hash FROM admins WHERE id = ?');
        $st->execute([$a['id']]);
        if (!password_verify((string)($b['current'] ?? ''), (string)$st->fetchColumn())) throw new HttpError(403, 'كلمة المرور الحالية غير صحيحة', 'bad_password');
        if (strlen((string)($b['new'] ?? '')) < 10) throw new HttpError(400, 'كلمة المرور 10 أحرف على الأقل', 'weak_password');
        ss_db()->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash((string)$b['new'], PASSWORD_DEFAULT), $a['id']]);
        Http::json(['ok' => true]);
    }

    // كل ما يلي يتطلب تسجيل دخول المدير
    Auth::admin();

    if ($path === '/v1/overview') Http::json(Reports::overview());

    if ($path === '/v1/devices') Http::json(Reports::devices());

    if (preg_match('#^/v1/devices/(\d+)/(revoke|restore|rename)$#', $path, $m) && $method === 'POST') {
        $id = (int)$m[1];
        if ($m[2] === 'rename') {
            $name = trim(mb_substr((string)(Http::body(64_000)['name'] ?? ''), 0, 100));
            if ($name === '') throw new HttpError(400, 'الاسم فارغ', 'bad_name');
            ss_db()->prepare('UPDATE devices SET name = ? WHERE id = ?')->execute([$name, $id]);
        } else {
            ss_db()->prepare('UPDATE devices SET revoked = ? WHERE id = ?')->execute([$m[2] === 'revoke' ? 1 : 0, $id]);
        }
        Http::json(['ok' => true]);
    }

    if ($path === '/v1/sessions') {
        $dev = Http::intParam('device', 0, 0, PHP_INT_MAX);
        Http::json(Reports::sessions($dev ?: null, Http::intParam('limit', 100, 1, 500), Http::intParam('offset', 0, 0, 1_000_000)));
    }

    if (preg_match('#^/v1/sessions/(\d+)$#', $path, $m)) {
        if ($method === 'DELETE') {
            $db = ss_db();
            $db->prepare('DELETE i FROM sample_intf i JOIN samples s ON s.id = i.sample_id WHERE s.session_id = ?')->execute([(int)$m[1]]);
            $db->prepare('DELETE FROM samples WHERE session_id = ?')->execute([(int)$m[1]]);
            $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([(int)$m[1]]);
            $db->exec('UPDATE cells SET dirty = 1');
            Http::json(['ok' => true]);
        }
        Http::json(Reports::sessionReport((int)$m[1]));
    }
    if (preg_match('#^/v1/sessions/(\d+)/geojson$#', $path, $m)) {
        if (isset($_GET['download'])) header('Content-Disposition: attachment; filename="session-' . $m[1] . '.geojson"');
        Http::json(Reports::sessionGeoJson((int)$m[1]));
    }
    if (preg_match('#^/v1/sessions/(\d+)/csv$#', $path, $m)) Reports::sessionCsv((int)$m[1]);

    if ($path === '/v1/cells') Http::json(Reports::cells($_GET));
    if (preg_match('#^/v1/cells/(\d+)$#', $path, $m)) Http::json(Reports::cellReport((int)$m[1]));

    if ($path === '/v1/coverage') Http::json(Reports::coverage($_GET));

    if ($path === '/v1/compare' && $method === 'POST') {
        @set_time_limit(120);
        $b = Http::body(64_000);
        Http::json(Compare::run((array)($b['a'] ?? []), (array)($b['b'] ?? []), (array)($b['shared'] ?? [])));
    }

    // ---------- تنبيهات التدهور ----------
    if ($path === '/v1/alerts') Http::json(['alerts' => Alerts::list((string)($_GET['status'] ?? 'open')), 'config' => Alerts::cfg(), 'open' => Alerts::openCount()]);
    if ($path === '/v1/alerts/scan' && $method === 'POST') Http::json(['ok' => true, 'scan' => Alerts::scan(), 'open' => Alerts::openCount()]);
    if (preg_match('#^/v1/alerts/(\d+)/ack$#', $path, $m) && $method === 'POST') { Alerts::ack((int)$m[1], (string)($_SESSION['admin_user'] ?? 'admin')); Http::json(['ok' => true]); }

    // ---------- عزل التشويش ----------
    if ($path === '/v1/interference') Http::json(['status' => Interference::status(), 'summary' => Interference::summary($_GET),
        'catalog' => Interference::catalog(), 'labels' => Interference::LABELS]);
    if ($path === '/v1/interference/grid') Http::json(Interference::grid($_GET));
    if ($path === '/v1/interference/sources') { @set_time_limit(90); Http::json(['sources' => Interference::sources($_GET)]); }
    if ($path === '/v1/interference/aggressors') Http::json(Interference::aggressors($_GET));
    if ($path === '/v1/interference/csv') Interference::csv($_GET);
    if ($path === '/v1/interference/run' && $method === 'POST') { @set_time_limit(120); Http::json(['ok' => true, 'run' => Interference::run(60.0), 'status' => Interference::status()]); }

    // ---------- النسخ الاحتياطي ----------
    if ($path === '/v1/backups') Http::json(['backups' => Backup::list()]);
    if ($path === '/v1/backups/run' && $method === 'POST') Http::json(Backup::run());
    if (preg_match('#^/v1/backups/(signalscope-[0-9]{8}-[0-9]{6}\.sql\.gz)$#', $path, $m)) Backup::send($m[1]);

    if ($path === '/v1/obstacles') Http::json(Obstacles::grid($_GET));
    if ($path === '/v1/obstacles/status') Http::json(Obstacles::status() + ['summary' => Obstacles::summary('1=1', [])]);
    if ($path === '/v1/obstacles/run' && $method === 'POST') {
        @set_time_limit(120);
        $log = [];
        $r = Obstacles::run(25.0, $log);
        Http::json(['ok' => true, 'run' => $r, 'log' => array_slice($log, 0, 30), 'status' => Obstacles::status()]);
    }

    throw new HttpError(404, 'المسار غير موجود: ' . $path, 'not_found');
} catch (HttpError $e) {
    Http::json(['error' => $e->errCode, 'message' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[signalscope] ' . $e);
    Http::json(['error' => 'server_error', 'message' => 'خطأ في الخادم. راجع error_log في cPanel'], 500);
}
