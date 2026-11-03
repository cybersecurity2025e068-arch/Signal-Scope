<?php
declare(strict_types=1);

const SS_VERSION = '1.1.0';

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

function ss_db(): PDO
{
    static $pdo = null;
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
