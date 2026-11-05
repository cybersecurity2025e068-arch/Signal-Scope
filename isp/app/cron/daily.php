<?php
// يومياً: تنظيف محاولات الدخول القديمة، ومزامنة RADIUS لكل المشتركين (احتياط)
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';
Db::q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
Db::q('DELETE FROM audit_log WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 730)]);
if (Radius::enabled()) {
    $n = 0;
    foreach (Db::all('SELECT id FROM subscribers') as $r) {
        if (Radius::sync((int) $r['id']) === '') {
            $n++;
        }
    }
    echo "radius synced $n\n";
}
