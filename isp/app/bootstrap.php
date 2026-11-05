<?php
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('APP_VERSION', '1.0.0');

$cfgFile = APP_DIR . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("الإعداد غير مكتمل: config.php غير موجود. شغّل Deploy من cPanel أو انسخ config.example.php.\n");
}
$GLOBALS['CONFIG'] = require $cfgFile;
date_default_timezone_set($GLOBALS['CONFIG']['timezone'] ?? 'Asia/Baghdad');

foreach (['Db', 'helpers', 'Auth', 'Audit', 'Settings', 'Radius', 'Billing', 'Subscribers', 'Cards', 'Installer'] as $f) {
    require APP_DIR . '/lib/' . $f . '.php';
}

function config(string $key, $default = null)
{
    $v = $GLOBALS['CONFIG'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}
