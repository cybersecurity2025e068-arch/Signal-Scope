<?php
// ينشئ الجداول (آمن للتكرار) وحساب المدير الأول إن لم يوجد مستخدمون.
// المخرجات: "admin_created <user> <pass>" أو "ok"
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';
$pass = Installer::run();
@file_put_contents(APP_DIR . '/.installed-' . APP_VERSION, now());
echo $pass !== '' ? "admin_created admin $pass\n" : "ok\n";
