<?php
$staff = Auth::isStaff();
if (is_post()) {
    $bid = Cards::generate(in_int('plan_id'), in_int('qty'), in_int('agent_id') ?: null, in_str('note'));
    flash('ok', 'وُلّدت الكروت.');
    redirect(url('batch', ['id' => $bid]));
}
$where = $staff ? '1=1' : 'b.agent_id = ' . (int) $ME['id'];
[$off, $per, $pg] = page_args(30);
$total = (int) Db::val("SELECT COUNT(*) FROM card_batches b WHERE $where");
$rows = Db::all("SELECT b.*, p.name AS plan, u.username AS agent, (SELECT COUNT(*) FROM cards c WHERE c.batch_id = b.id AND c.status = 'used') AS used FROM card_batches b JOIN plans p ON p.id = b.plan_id LEFT JOIN users u ON u.id = b.agent_id WHERE $where ORDER BY b.id DESC LIMIT $per OFFSET $off");
$stock = Db::all("SELECT p.name, COUNT(*) n FROM cards c JOIN plans p ON p.id = c.plan_id JOIN card_batches b ON b.id = c.batch_id WHERE c.status = 'new' AND $where GROUP BY p.id, p.name ORDER BY p.name");
layout_start('الكروت', 'cards');
?>
<?php if ($stock): ?>
<div class="tiles"><?php foreach ($stock as $s) { echo stat_tile('متوفر: ' . $s['name'], number_format((int) $s['n'])); } ?></div>
<?php endif; ?>
<?php if (Auth::isAdmin() || $ME['role'] === 'agent'): ?>
<form method="post" class="card row wrap">
  <?= csrf_field() ?>
  <b>توليد دفعة كروت</b>
  <select name="plan_id" required><?= select_opts(plan_options(), '', 'اختر الباقة') ?></select>
  <input type="number" name="qty" min="1" max="<?= Cards::MAX_QTY ?>" placeholder="العدد" required class="w-s">
  <?php if (Auth::isAdmin()): ?><select name="agent_id"><?= select_opts(agent_options(), '', 'للإدارة (بلا خصم)') ?></select><?php endif; ?>
  <input name="note" placeholder="ملاحظة">
  <button class="btn primary" data-confirm="<?= $ME['role'] === 'agent' ? 'سيُخصم ثمن الكروت من رصيدك. متابعة؟' : 'توليد الكروت؟ إن اخترت وكيلاً يُخصم ثمنها من رصيده.' ?>">توليد</button>
</form>
<?php endif; ?>
<div class="card flush">
<table class="tbl">
  <thead><tr><th>الدفعة</th><th>الباقة</th><th>العدد</th><th>المستخدم</th><th>سعر الكارت</th><?= $staff ? '<th>الوكيل</th>' : '' ?><th>التاريخ</th><th>ملاحظة</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $b): ?>
    <tr class="rowlink" data-href="<?= h(url('batch', ['id' => $b['id']])) ?>"><td><a href="<?= h(url('batch', ['id' => $b['id']])) ?>">#<?= (int) $b['id'] ?></a></td><td><?= h($b['plan']) ?></td><td><?= number_format((int) $b['qty']) ?></td><td><?= number_format((int) $b['used']) ?></td><td><?= money($b['unit_price']) ?></td>
    <?= $staff ? '<td>' . h($b['agent'] ?? 'الإدارة') . '</td>' : '' ?><td class="small"><?= h(fmt_dt($b['created_at'])) ?></td><td class="small"><?= h($b['note']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted center">لا توجد دفعات.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pager($total, $per, $pg) ?>
<?php layout_end();
