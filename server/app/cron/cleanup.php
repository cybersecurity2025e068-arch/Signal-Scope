<?php
// تنظيف يومي: حذف القراءات الخام الأقدم من retention_days، وسجل محاولات الدخول.
// cPanel ← Cron Jobs، مرة يومياً:
//   /usr/local/bin/php /home/CPANELUSER/signalscope/cron/cleanup.php >/dev/null 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../bootstrap.php';

$db = ss_db();
$days = max(30, (int)(ss_config()['retention_days'] ?? 365));
$cutoff = (int)((time() - $days * 86400) * 1000);
$total = 0;
do { // دفعات صغيرة حتى لا تُقفل الجداول طويلاً
    $st = $db->prepare('DELETE FROM samples WHERE ts < ? LIMIT 5000');
    $st->execute([$cutoff]);
    $total += $n = $st->rowCount();
} while ($n === 5000);

$db->prepare('DELETE FROM login_fail WHERE ts < ?')->execute([time() - 86400]);
// جلسات بلا قراءات بعد الحذف تبقى سجلاً تاريخياً، لكن نحدّث عدّاداتها
if ($total > 0) {
    $db->exec('UPDATE sessions s LEFT JOIN (SELECT session_id, COUNT(*) c FROM samples GROUP BY session_id) t ON t.session_id = s.id SET s.sample_count = COALESCE(t.c, 0)');
}
echo "deleted=$total\n";
