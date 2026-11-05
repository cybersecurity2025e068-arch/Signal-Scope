<?php
Auth::requireAdmin();
$q = in_str('q');
$action = in_str('action');
$where = ['1=1'];
$p = [];
if ($q !== '') {
    $where[] = '(actor LIKE ? OR details LIKE ? OR ip LIKE ?)';
    $p = array_fill(0, 3, '%' . $q . '%');
}
if ($action !== '') {
    $where[] = 'action = ?';
    $p[] = $action;
}
$W = implode(' AND ', $where);
[$off, $per, $pg] = page_args(100);
$total = (int) Db::val("SELECT COUNT(*) FROM audit_log WHERE $W", $p);
$rows = Db::all("SELECT * FROM audit_log WHERE $W ORDER BY id DESC LIMIT $per OFFSET $off", $p);
$acts = [];
foreach (Db::all('SELECT DISTINCT action FROM audit_log ORDER BY action') as $r) {
    $acts[$r['action']] = Audit::label($r['action']);
}
layout_start('سجل العمليات', 'audit');
?>
<form class="filters card" method="get"><input type="hidden" name="p" value="audit"><input name="q" value="<?= h($q) ?>" placeholder="المستخدم أو التفاصيل أو IP"><select name="action" data-autosubmit><?= select_opts($acts, $action, 'كل العمليات') ?></select><button class="btn">بحث</button></form>
<div class="card flush">
<table class="tbl"><thead><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th>العنصر</th><th>التفاصيل</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $l): ?>
  <tr><td class="small"><?= h(fmt_dt($l['created_at'])) ?></td><td><?= h($l['actor']) ?></td><td><?= h(Audit::label($l['action'])) ?></td>
  <td class="small"><?php
    if ($l['entity'] === 'subscriber' && $l['entity_id']) { echo '<a href="' . h(url('subscriber', ['id' => $l['entity_id']])) . '">مشترك #' . (int) $l['entity_id'] . '</a>'; }
    elseif ($l['entity'] === 'user' && $l['entity_id']) { echo '<a href="' . h(url('user', ['id' => $l['entity_id']])) . '">حساب #' . (int) $l['entity_id'] . '</a>'; }
    elseif ($l['entity'] === 'batch' && $l['entity_id']) { echo '<a href="' . h(url('batch', ['id' => $l['entity_id']])) . '">دفعة #' . (int) $l['entity_id'] . '</a>'; }
    elseif ($l['entity']) { echo h($l['entity'] . ' #' . $l['entity_id']); } ?></td>
  <td class="small"><?= h($l['details']) ?></td><td dir="ltr" class="small"><?= h($l['ip']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?= pager($total, $per, $pg) ?>
<?php layout_end();
