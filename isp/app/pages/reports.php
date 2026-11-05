<?php
$staff = Auth::isStaff();
$from = in_str('from', date('Y-m-01'));
$to = in_str('to', date('Y-m-d'));
$group = in_str('group', 'day');
$agent = $staff ? in_int('agent_id') : (int) $ME['id'];
$where = ['a.cancelled = 0', 'a.created_at >= ?', 'a.created_at < ?'];
$p = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
if ($agent) {
    $where[] = 'a.agent_id = ?';
    $p[] = $agent;
}
$W = implode(' AND ', $where);
$groups = [
    'day' => ['DATE(a.created_at)', 'اليوم'],
    'month' => ["DATE_FORMAT(a.created_at, '%Y-%m')", 'الشهر'],
    'plan' => ['p.name', 'الباقة'],
    'agent' => ["COALESCE(u.username, 'مباشر')", 'الوكيل'],
    'method' => ['a.method', 'الطريقة'],
];
if (!$staff) {
    unset($groups['agent']);
}
if (!isset($groups[$group])) {
    $group = 'day';
}
[$expr, $glabel] = $groups[$group];
$rows = Db::all("SELECT $expr AS g, COUNT(*) AS n, COUNT(DISTINCT a.subscriber_id) AS subs, SUM(a.price) AS cost, SUM(a.retail_price) AS retail
    FROM activations a JOIN plans p ON p.id = a.plan_id LEFT JOIN users u ON u.id = a.agent_id WHERE $W GROUP BY g ORDER BY " . ($group === 'day' || $group === 'month' ? 'g DESC' : 'n DESC'), $p);
if ($group === 'method') {
    foreach ($rows as &$r) {
        $r['g'] = method_label($r['g']);
    }
    unset($r);
}
$tot = ['n' => 0, 'cost' => 0, 'retail' => 0];
foreach ($rows as $r) {
    $tot['n'] += (int) $r['n'];
    $tot['cost'] += (int) $r['cost'];
    $tot['retail'] += (int) $r['retail'];
}
if (in_str('export') === 'csv') {
    send_csv('report', [$glabel, 'التفعيلات', 'مشتركون', 'المبلغ', 'سعر البيع'], array_map(function ($r) {
        return [$r['g'], $r['n'], $r['subs'], $r['cost'], $r['retail']];
    }, $rows));
}
// مشتركون جدد ومنتهون في المدة
[$sw, $sp] = Subscribers::scope();
$dp = [$p[0], $p[1]];
$newSubs = (int) Db::val("SELECT COUNT(*) FROM subscribers s WHERE $sw AND s.created_at >= ? AND s.created_at < ?" . ($staff && $agent ? ' AND s.agent_id = ' . (int) $agent : ''), array_merge($sp, $dp));
$expiredIn = (int) Db::val("SELECT COUNT(*) FROM subscribers s WHERE $sw AND s.expires_at >= ? AND s.expires_at < ? AND s.expires_at < ?" . ($staff && $agent ? ' AND s.agent_id = ' . (int) $agent : ''), array_merge($sp, $dp, [now()]));
$ex = $_GET;
$ex['export'] = 'csv';
layout_start('التقارير', 'reports', '<a class="btn" href="?' . h(http_build_query($ex)) . '">تصدير Excel</a> <button class="btn" data-print>طباعة</button>');
?>
<form class="filters card" method="get">
  <input type="hidden" name="p" value="reports">
  <label class="inline">من <input type="date" name="from" value="<?= h($from) ?>"></label>
  <label class="inline">إلى <input type="date" name="to" value="<?= h($to) ?>"></label>
  <select name="group" data-autosubmit><?php foreach ($groups as $k => $g) { echo '<option value="' . h($k) . '"' . ($k === $group ? ' selected' : '') . '>حسب ' . h($g[1]) . '</option>'; } ?></select>
  <?php if ($staff): ?><select name="agent_id" data-autosubmit><?= select_opts(agent_options(), $agent ?: '', 'كل الوكلاء') ?></select><?php endif; ?>
  <button class="btn">عرض</button>
  <span class="quick"><a href="<?= h(url('reports', ['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'group' => $group])) ?>">اليوم</a> · <a href="<?= h(url('reports', ['from' => date('Y-m-d', strtotime('-6 day')), 'to' => date('Y-m-d'), 'group' => $group])) ?>">7 أيام</a> · <a href="<?= h(url('reports', ['from' => date('Y-m-01', strtotime('first day of last month')), 'to' => date('Y-m-t', strtotime('last day of last month')), 'group' => $group])) ?>">الشهر الماضي</a></span>
</form>
<div class="tiles">
  <?= stat_tile('التفعيلات', number_format($tot['n'])) ?>
  <?= stat_tile($staff ? 'المبيعات (سعر الوكيل)' : 'المدفوع', money($tot['cost']), 'ok') ?>
  <?= stat_tile('بسعر المشترك', money($tot['retail'])) ?>
  <?= stat_tile($staff ? 'هامش الوكلاء' : 'ربحك التقديري', money($tot['retail'] - $tot['cost'])) ?>
  <?= stat_tile('مشتركون جدد', number_format($newSubs)) ?>
  <?= stat_tile('انتهت اشتراكاتهم', number_format($expiredIn), 'bad') ?>
</div>
<div class="card flush">
<table class="tbl">
  <thead><tr><th><?= h($glabel) ?></th><th>التفعيلات</th><th>مشتركون</th><th>المبلغ</th><th>بسعر المشترك</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= h($r['g']) ?></td><td><?= number_format((int) $r['n']) ?></td><td><?= number_format((int) $r['subs']) ?></td><td><?= money($r['cost']) ?></td><td><?= money($r['retail']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted center">لا توجد تفعيلات في هذه المدة.</td></tr><?php else: ?>
    <tr class="total"><td>المجموع</td><td><?= number_format($tot['n']) ?></td><td></td><td><?= money($tot['cost']) ?></td><td><?= money($tot['retail']) ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php layout_end();
