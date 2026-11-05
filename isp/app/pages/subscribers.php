<?php
[$w, $p] = Subscribers::scope();
$where = [$w];
$q = in_str('q');
$state = in_str('state');
$plan = in_int('plan_id');
$agent = in_int('agent_id');
$zone = in_str('zone');
$now = now();
$warn = date('Y-m-d H:i:s', time() + 86400 * (int) Settings::get('expiry_warn_days'));
if ($q !== '') {
    $where[] = '(s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ? OR s.phone2 LIKE ? OR s.mac LIKE ? OR s.static_ip LIKE ? OR s.address LIKE ?)';
    $p = array_merge($p, array_fill(0, 7, '%' . $q . '%'));
}
$stateSql = [
    'active' => ['s.suspended = 0 AND s.expires_at >= ?', [$now]],
    'expiring' => ['s.suspended = 0 AND s.expires_at >= ? AND s.expires_at < ?', [$now, $warn]],
    'expired' => ['s.suspended = 0 AND s.expires_at < ?', [$now]],
    'suspended' => ['s.suspended = 1', []],
    'new' => ['s.suspended = 0 AND s.expires_at IS NULL', []],
];
if (isset($stateSql[$state])) {
    $where[] = $stateSql[$state][0];
    $p = array_merge($p, $stateSql[$state][1]);
}
if ($plan) {
    $where[] = 's.plan_id = ?';
    $p[] = $plan;
}
if ($agent && Auth::isStaff()) {
    $where[] = $agent === -1 ? 's.agent_id IS NULL' : 's.agent_id = ?';
    if ($agent !== -1) {
        $p[] = $agent;
    }
}
if ($zone !== '') {
    $where[] = 's.zone = ?';
    $p[] = $zone;
}
$sorts = ['exp' => 's.expires_at', 'new' => 's.id DESC', 'name' => 's.username', '-exp' => 's.expires_at DESC'];
$sort = in_str('sort', 'new');
$order = $sorts[$sort] ?? $sorts['new'];
$W = implode(' AND ', $where);
$base = "FROM subscribers s LEFT JOIN plans pl ON pl.id = s.plan_id LEFT JOIN users ag ON ag.id = s.agent_id WHERE $W";

if (in_str('export') === 'csv') {
    $rows = Db::all("SELECT s.*, pl.name AS plan, ag.username AS agent $base ORDER BY $order", $p);
    send_csv('subscribers', ['اسم المستخدم', 'الاسم', 'الهاتف', 'المنطقة', 'العنوان', 'الباقة', 'الوكيل', 'الانتهاء', 'الحالة', 'MAC', 'IP'], array_map(function ($s) {
        return [$s['username'], $s['full_name'], $s['phone'], $s['zone'], $s['address'], $s['plan'], $s['agent'], $s['expires_at'], state_label(sub_state($s)), $s['mac'], $s['static_ip']];
    }, $rows));
}

[$off, $per, $pg] = page_args(50);
$total = (int) Db::val("SELECT COUNT(*) $base", $p);
$rows = Db::all("SELECT s.*, pl.name AS plan, ag.username AS agent $base ORDER BY $order LIMIT $per OFFSET $off", $p);
$zones = Db::all("SELECT DISTINCT s.zone FROM subscribers s WHERE " . $w . " AND s.zone <> '' ORDER BY s.zone", Subscribers::scope()[1]);
$zoneOpts = [];
foreach ($zones as $z) {
    $zoneOpts[$z['zone']] = $z['zone'];
}

$exportQ = $_GET;
$exportQ['export'] = 'csv';
unset($exportQ['pg']);
$act = '<a class="btn primary" href="' . h(url('subscriber_edit')) . '">+ مشترك جديد</a> <a class="btn" href="?' . h(http_build_query($exportQ)) . '">تصدير Excel</a>';
layout_start('المشتركون', 'subscribers', $act);
?>
<form class="filters card" method="get">
  <input type="hidden" name="p" value="subscribers">
  <input name="q" value="<?= h($q) ?>" placeholder="بحث: اسم المستخدم، الاسم، الهاتف، MAC، IP…">
  <select name="state" data-autosubmit><?= select_opts(['active' => 'فعّال', 'expiring' => 'ينتهي قريباً', 'expired' => 'منتهي', 'suspended' => 'موقوف', 'new' => 'غير مفعّل'], $state, 'كل الحالات') ?></select>
  <select name="plan_id" data-autosubmit><?= select_opts(plan_options(false), $plan ?: '', 'كل الباقات') ?></select>
  <?php if (Auth::isStaff()): ?>
  <select name="agent_id" data-autosubmit><?= select_opts(['-1' => 'بلا وكيل (مباشر)'] + agent_options(), $agent ?: '', 'كل الوكلاء') ?></select>
  <?php endif; ?>
  <?php if ($zoneOpts): ?><select name="zone" data-autosubmit><?= select_opts($zoneOpts, $zone, 'كل المناطق') ?></select><?php endif; ?>
  <select name="sort" data-autosubmit><?= select_opts(['new' => 'الأحدث إضافة', 'exp' => 'الأقرب انتهاءً', '-exp' => 'الأبعد انتهاءً', 'name' => 'أبجدياً'], $sort) ?></select>
  <button class="btn">بحث</button>
</form>
<p class="muted small"><?= number_format($total) ?> مشترك</p>
<div class="card flush">
<table class="tbl">
  <thead><tr><th>المشترك</th><th>الهاتف</th><th>الباقة</th><?php if (Auth::isStaff()): ?><th>الوكيل</th><?php endif; ?><th>الانتهاء</th><th>الحالة</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): $st = sub_state($s); ?>
    <tr class="rowlink" data-href="<?= h(url('subscriber', ['id' => $s['id']])) ?>">
      <td><a href="<?= h(url('subscriber', ['id' => $s['id']])) ?>" dir="ltr"><?= h($s['username']) ?></a><div class="muted small"><?= h($s['full_name']) ?><?= $s['zone'] ? ' · ' . h($s['zone']) : '' ?></div></td>
      <td dir="ltr"><?= h($s['phone']) ?></td>
      <td><?= h($s['plan'] ?? '—') ?></td>
      <?php if (Auth::isStaff()): ?><td><?= h($s['agent'] ?? 'مباشر') ?></td><?php endif; ?>
      <td><?= h(fmt_dt($s['expires_at'], false)) ?><div class="muted small"><?= h(days_left($s['expires_at'])) ?></div></td>
      <td><?= state_badge($st) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted center">لا توجد نتائج.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pager($total, $per, $pg) ?>
<?php layout_end();
