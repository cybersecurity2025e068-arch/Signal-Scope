<?php
// تدوير الأسرار: رمز تسجيل الهواتف، رمز الإعداد، وكلمة مرور حساب المدير الأول.
// يُستدعى من auto-setup.sh عندما يتغيّر الملف rotate.id في المستودع.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../bootstrap.php';

// بلا أحرف متشابهة (0 O o 1 l I) حتى لا تُخطأ عند النسخ أو الكتابة
$rand = fn(int $n) => substr(preg_replace('/[^A-HJ-NP-Za-km-np-z2-9]/', '', base64_encode(random_bytes($n * 3))), 0, $n);
$file = getenv('SIGNALSCOPE_CONFIG') ?: SS_APP_DIR . '/config.php';
$src = (string)file_get_contents($file);
$enroll = $rand(20);
$setup = $rand(24);
$new = preg_replace(["/('enroll_key'\s*=>\s*)'[^']*'/", "/('setup_key'\s*=>\s*)'[^']*'/"], ["\${1}'$enroll'", "\${1}'$setup'"], $src, 1, $count);
if ($new === null || $count < 2) { fwrite(STDERR, "config.php: enroll_key/setup_key not found\n"); exit(1); }
file_put_contents($file, $new);
chmod($file, 0600);

$db = ss_db();
$a = $db->query('SELECT id, username FROM admins ORDER BY id LIMIT 1')->fetch();
if (!$a) { echo "rotated - - $enroll\n"; exit(0); }
$pass = $rand(16);
$db->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $a['id']]);
// تحقق فوري: نقرأ ما حُفظ فعلاً ونطابقه
$st = $db->prepare('SELECT pass_hash FROM admins WHERE id = ?');
$st->execute([$a['id']]);
if (!password_verify($pass, (string)$st->fetchColumn())) { fwrite(STDERR, "admin password verify failed\n"); exit(1); }
$db->exec('DELETE FROM login_fail'); // رفع أي قفل بسبب محاولات سابقة
echo "rotated {$a['username']} $pass $enroll\n";
