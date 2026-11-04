<?php
declare(strict_types=1);

const SS_VERSION = '1.7.0';

define('SS_APP_DIR', __DIR__);
require SS_APP_DIR . '/lib/Http.php'; // يحوي HttpError أيضاً

spl_autoload_register(function (string $class): void {
    $f = SS_APP_DIR . '/lib/' . $class . '.php';
    if (is_file($f)) require $f;
});

function ss_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = getenv('SIGNALSCOPE_CONFIG') ?: SS_APP_DIR . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config.php غير موجود. انسخ config.example.php إلى config.php');
        }
        $cfg = require $file;
    }
    return $cfg;
}

function ss_db(bool $reconnect = false): PDO
{
    static $pdo = null;
    if ($reconnect) $pdo = null;
    if ($pdo === null) {
        $c = ss_config()['db'];
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/** يسجّل آخر تشغيل لمهمة cron (للتحقق من أن المهام المجدولة تعمل) */
function ss_cron_mark(string $job): void
{
    $f = SS_APP_DIR . '/data/last-cron.json';
    $j = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $j[$job] = gmdate('c');
    @file_put_contents($f, json_encode($j));
}

/** يعيد اتصالاً صالحاً: الاستضافة تغلق الاتصال الخامل بسرعة (MySQL server has gone away) */
function ss_db_alive(): PDO
{
    try { ss_db()->query('SELECT 1'); return ss_db(); }
    catch (Throwable $e) { return ss_db(true); }
}
