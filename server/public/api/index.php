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
            $r = Obstacles::run(60.0, $log);
            Http::json(['ok' => true, 'run' => $r, 'log' => array_slice($log, 0, 50), 'status' => Obstacles::status()]);
        }
        if ($method === 'POST') Http::json(Updater::run((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
        $f = SS_APP_DIR . '/data/deployed.json';
        $q = function (string $sql) { return (int)ss_db()->query($sql)->fetchColumn(); };
        Http::json(['ok' => true, 'version' => SS_VERSION, 'deployed' => is_file($f) ? json_decode((string)file_get_contents($f), true) : null,
            'counts' => ['devices' => $q('SELECT COUNT(*) FROM devices'), 'sessions' => $q('SELECT COUNT(*) FROM sessions'),
                         'samples' => $q('SELECT COUNT(*) FROM samples'), 'cells' => $q('SELECT COUNT(*) FROM cells'),
                         'cells_located' => $q("SELECT COUNT(*) FROM cells WHERE est_lat IS NOT NULL AND est_method <> 'insufficient'")],
            'obstacles' => Obstacles::status()]);
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
