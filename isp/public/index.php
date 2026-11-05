<?php
// نقطة الدخول الوحيدة. منطق التطبيق خارج public_html في ~/isppanel
$app = getenv('ISP_APP') ?: '';
if ($app === '') {
    foreach ([dirname(__DIR__, 2) . '/isppanel', dirname(__DIR__, 3) . '/isppanel', dirname(__DIR__) . '/app'] as $c) {
        if (is_file($c . '/bootstrap.php')) {
            $app = $c;
            break;
        }
    }
}
if ($app === '' || !is_file($app . '/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("ملفات التطبيق غير موجودة (~/isppanel). أعد Deploy من cPanel.\n");
}
require $app . '/bootstrap.php';
require APP_DIR . '/router.php';
