<?php
if (!Radius::enabled()) {
    throw new UserError('ربط RADIUS غير مفعّل. فعّله من config.php (قسم radius).');
}
$q = in_str('q');
$only = null;
if (!Auth::isStaff()) {
    $only = array_column(Db::all('SELECT username FROM subscribers WHERE agent_id = ?', [$ME['id']]), 'username');
}
$rows = Radius::online($q, $only);
$names = [];
if ($rows) {
    $us = array_column($rows, 'username');
    $in = implode(',', array_fill(0, count($us), '?'));
    foreach (Db::all("SELECT id, username, full_name FROM subscribers WHERE username IN ($in)", $us) as $s) {
        $names[$s['username']] = $s;
    }
}
layout_start('المتصلون الآن', 'online');
?>
<form class="filters card" method="get"><input type="hidden" name="p" value="online"><input name="q" value="<?= h($q) ?>" placeholder="اسم المستخدم أو IP أو MAC"><button class="btn">بحث</button><span class="muted"><?= number_format(count($rows)) ?> متصل</span></form>
<div class="card flush">
<table class="tbl"><thead><tr><th>المشترك</th><th>IP</th><th>MAC</th><th>NAS</th><th>منذ</th><th>تنزيل/رفع</th></tr></thead><tbody>
<?php foreach ($rows as $r): $s = $names[$r['username']] ?? null; ?>
  <tr><td><?= $s ? '<a href="' . h(url('subscriber', ['id' => $s['id']])) . '">' . h($r['username']) . '</a><div class="muted small">' . h($s['full_name']) . '</div>' : h($r['username']) ?></td>
  <td dir="ltr"><?= h($r['framedipaddress']) ?></td><td dir="ltr"><?= h($r['callingstationid']) ?></td><td dir="ltr"><?= h($r['nasipaddress']) ?></td>
  <td class="small"><?= h(fmt_dt($r['acctstarttime'])) ?></td><td dir="ltr"><?= h(Radius::bytes($r['acctoutputoctets'])) ?> / <?= h(Radius::bytes($r['acctinputoctets'])) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted center">لا يوجد متصلون.</td></tr><?php endif; ?>
</tbody></table>
</div>
<?php layout_end();
