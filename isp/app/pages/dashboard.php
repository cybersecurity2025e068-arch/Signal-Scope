<?php
[$w, $p] = Subscribers::scope();
$now = now();
$warn = date('Y-m-d H:i:s', time() + 86400 * (int) Settings::get('expiry_warn_days'));
$c = Db::one("SELECT COUNT(*) AS total,
    SUM(s.suspended = 1) AS suspended,
    SUM(s.suspended = 0 AND s.expires_at IS NULL) AS fresh,
    SUM(s.suspended = 0 AND s.expires_at < ?) AS expired,
    SUM(s.suspended = 0 AND s.expires_at >= ?) AS active,
    SUM(s.suspended = 0 AND s.expires_at >= ? AND s.expires_at < ?) AS expiring
    FROM subscribers s WHERE $w", array_merge([$now, $now, $now, $warn], $p));

$aw = Auth::isStaff() ? '1=1' : 'a.agent_id = ' . (int) $ME['id'];
$today = date('Y-m-d 00:00:00');
$month = date('Y-m-01 00:00:00');
$sales = Db::one("SELECT
    SUM(a.created_at >= ?) AS n_today, COALESCE(SUM(IF(a.created_at >= ?, a.price, 0)),0) AS v_today,
    COUNT(*) AS n_month, COALESCE(SUM(a.price),0) AS v_month
    FROM activations a WHERE a.cancelled = 0 AND a.created_at >= ? AND $aw", [$today, $today, $month]);

// آخر 14 يوماً
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-$i day"))] = 0;
}
foreach (Db::all("SELECT DATE(a.created_at) d, COUNT(*) n FROM activations a WHERE a.cancelled = 0 AND a.created_at >= ? AND $aw GROUP BY DATE(a.created_at)", [array_key_first($days) . ' 00:00:00']) as $r) {
    $days[$r['d']] = (int) $r['n'];
}
$maxDay = max(1, max($days));

$expiring = Db::all("SELECT s.id, s.username, s.full_name, s.phone, s.expires_at, s.suspended FROM subscribers s WHERE $w AND s.suspended = 0 AND s.expires_at >= ? AND s.expires_at < ? ORDER BY s.expires_at LIMIT 10", array_merge($p, [$now, $warn]));
$recent = Db::all("SELECT a.*, s.username, p.name AS plan FROM activations a JOIN subscribers s ON s.id = a.subscriber_id JOIN plans p ON p.id = a.plan_id WHERE a.cancelled = 0 AND $aw ORDER BY a.id DESC LIMIT 10");

$act = '<a class="btn primary" href="' . h(url('subscriber_edit')) . '">+ مشترك جديد</a> <a class="btn" href="' . h(url('redeem')) . '">تفعيل بكارت</a>';
layout_start('الرئيسية', 'dashboard', $act);
$sl = function ($st) { return url('subscribers', ['state' => $st]); };
?>
<div class="tiles">
  <?= stat_tile('كل المشتركين', number_format((int) $c['total']), '', url('subscribers')) ?>
  <?= stat_tile('فعّال', number_format((int) $c['active']), 'ok', $sl('active')) ?>
  <?= stat_tile('ينتهي خلال ' . (int) Settings::get('expiry_warn_days') . ' أيام', number_format((int) $c['expiring']), 'warn', $sl('expiring')) ?>
  <?= stat_tile('منتهي', number_format((int) $c['expired']), 'bad', $sl('expired')) ?>
  <?= stat_tile('موقوف', number_format((int) $c['suspended']), 'mute', $sl('suspended')) ?>
  <?= stat_tile('غير مفعّل', number_format((int) $c['fresh']), 'mute', $sl('new')) ?>
</div>
<div class="tiles">
  <?= stat_tile('تفعيلات اليوم', number_format((int) $sales['n_today']) . ' <small>' . money($sales['v_today']) . '</small>') ?>
  <?= stat_tile('تفعيلات هذا الشهر', number_format((int) $sales['n_month']) . ' <small>' . money($sales['v_month']) . '</small>') ?>
  <?php if ($ME['role'] === 'agent'): ?>
  <?= stat_tile('رصيدك', money($ME['balance']), (int) $ME['balance'] < 0 ? 'bad' : 'ok', url('transactions')) ?>
  <?php else: ?>
  <?= stat_tile('مجموع أرصدة الوكلاء', money(Db::val("SELECT COALESCE(SUM(balance),0) FROM users WHERE role = 'agent'")), '', url('users')) ?>
  <?php endif; ?>
</div>

<section class="card">
  <h2>التفعيلات خلال 14 يوماً</h2>
  <div class="bars" role="img" aria-label="عدد التفعيلات اليومية">
    <?php foreach ($days as $d => $n): ?>
      <div class="bar" title="<?= h($d) ?>: <?= $n ?>"><i style="height:<?= round($n / $maxDay * 100) ?>%"></i><b><?= $n ?: '' ?></b><span><?= h(date('d/m', strtotime($d))) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>

<div class="grid2">
<section class="card">
  <h2>تنتهي قريباً</h2>
  <?php if (!$expiring): ?><p class="muted">لا يوجد.</p><?php else: ?>
  <table class="tbl"><thead><tr><th>المشترك</th><th>الهاتف</th><th>ينتهي</th></tr></thead><tbody>
  <?php foreach ($expiring as $s): ?>
    <tr><td><a href="<?= h(url('subscriber', ['id' => $s['id']])) ?>"><?= h($s['username']) ?></a><div class="muted small"><?= h($s['full_name']) ?></div></td>
    <td dir="ltr"><?= h($s['phone']) ?></td><td><?= h(fmt_dt($s['expires_at'])) ?><div class="muted small"><?= h(days_left($s['expires_at'])) ?></div></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?>
</section>
<section class="card">
  <h2>آخر التفعيلات</h2>
  <?php if (!$recent): ?><p class="muted">لا يوجد بعد.</p><?php else: ?>
  <table class="tbl"><thead><tr><th>المشترك</th><th>الباقة</th><th>المبلغ</th><th>الوقت</th></tr></thead><tbody>
  <?php foreach ($recent as $a): ?>
    <tr><td><a href="<?= h(url('subscriber', ['id' => $a['subscriber_id']])) ?>"><?= h($a['username']) ?></a></td><td><?= h($a['plan']) ?> <span class="muted small"><?= h(method_label($a['method'])) ?></span></td>
    <td><?= money($a['price']) ?></td><td class="small"><?= h(fmt_dt($a['created_at'])) ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?>
</section>
</div>
<?php layout_end();
