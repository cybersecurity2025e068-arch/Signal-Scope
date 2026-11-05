<?php
// استعادة الدخول: php reset-admin.php [username]  ← يطبع كلمة مرور جديدة
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';
$u = $argv[1] ?? 'admin';
$pass = rand_code(16, 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789');
$n = Db::update('users', ['pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'active' => 1], 'username = ?', [$u]);
if (!$n) {
    Db::insert('users', ['username' => $u, 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'full_name' => 'المدير', 'role' => 'admin', 'created_at' => now()]);
}
Db::q("UPDATE users SET role = 'admin' WHERE username = ?", [$u]);
Db::q('DELETE FROM login_attempts');
echo "reset $u $pass\n";
