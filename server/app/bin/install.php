<?php
// يُستدعى من auto-setup.sh: ينشئ الجداول، وحساب مدير أول بكلمة مرور عشوائية إن لم يوجد.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../bootstrap.php';
$db = ss_db();
Schema::apply($db);
if ((int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) { echo "admin_exists\n"; exit(0); }
$user = 'admin';
$pass = substr(preg_replace('/[^A-HJ-NP-Za-km-np-z2-9]/', '', base64_encode(random_bytes(48))), 0, 16); // بلا أحرف متشابهة
$db->prepare('INSERT INTO admins (username, pass_hash) VALUES (?, ?)')->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
echo "admin_created $user $pass\n";
