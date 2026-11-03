<?php
// صفحة فحص بلا اعتماديات (تعمل حتى على PHP 5.6) لتشخيص التثبيت بدقة.
// لا تعرض كلمات مرور. افتحها: https://نطاقك/api/check.php
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$ok = function ($b) { return $b ? 'OK  ' : 'FAIL'; };
$out = array();
$out[] = 'SignalScope check — ' . gmdate('Y-m-d H:i:s') . ' UTC';
$out[] = '';
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$out[] = $ok($phpOk) . ' PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')' . ($phpOk ? '' : '  ← يلزم 8.1+: cPanel ← MultiPHP Manager ← اختر PHP 8.1 أو أحدث للنطاق');
$out[] = 'INFO api dir: ' . __DIR__;
$out[] = 'INFO document root: ' . (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '?');
$app = null;
$cands = array(getenv('SIGNALSCOPE_APP') ?: '', __DIR__ . '/../../signalscope', __DIR__ . '/../../app');
$home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid())['dir'] : '');
if ($home) $cands[] = $home . '/signalscope';
foreach ($cands as $c) { if ($c && is_file($c . '/bootstrap.php')) { $app = realpath($c); break; } }
$out[] = $ok($app !== null) . ' app dir: ' . ($app ?: 'غير موجود (ابحث عن ~/signalscope)');
$cfgFile = $app ? $app . '/config.php' : null;
$cfgOk = $cfgFile && is_readable($cfgFile);
$out[] = $ok($cfgOk) . ' config.php' . ($cfgOk ? '' : ' غير موجود أو غير مقروء');
foreach (array('pdo_mysql', 'json', 'mbstring', 'zlib') as $e) $out[] = $ok(extension_loaded($e)) . ' extension ' . $e;
if ($cfgOk && extension_loaded('pdo_mysql')) {
    $cfg = @include $cfgFile;
    try {
        $pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $out[] = 'OK   database connection';
        $t = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('admins','devices','sessions','samples','cells')")->fetchColumn();
        $out[] = $ok($t == 5) . " tables $t/5";
        if ($t == 5) $out[] = 'INFO admins: ' . $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    } catch (Exception $e) {
        $out[] = 'FAIL database: ' . $e->getMessage();
    }
}
$out[] = $ok(is_file(__DIR__ . '/index.php')) . ' api/index.php';
$out[] = $ok(is_file(__DIR__ . '/../dashboard/index.html')) . ' dashboard/index.html';
$ht = @file_get_contents(__DIR__ . '/.htaccess');
$out[] = 'INFO api/.htaccess handler line: ' . (strpos((string)$ht, 'AddHandler') !== false ? 'yes' : 'no');
$out[] = '';
$out[] = 'API test URL: index.php?r=/v1/ping';
echo implode("\n", $out), "\n";
