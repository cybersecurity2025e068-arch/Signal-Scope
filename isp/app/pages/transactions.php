<?php
$staff = Auth::isStaff();
$uid = $staff ? in_int('user_id') : (int) $ME['id'];
$type = in_str('type');
$from = in_str('from', date('Y-m-01'));
$to = in_str('to', date('Y-m-d'));
$where = ['t.created_at >= ?', 't.created_at < ?'];
$p = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
if ($uid) {
    $where[] = 't.user_id = ?';
    $p[] = $uid;
}
if (isset(['deposit' => 1, 'withdraw' => 1, 'activation' => 1, 'refund' => 1, 'cards' => 1, 'adjust' => 1][$type])) {
    $where[] = 't.type = ?';
    $p[] = $type;
}
$W = implode(' AND ', $where);
$base = "FROM transactions t JOIN users a ON a.id = t.user_id LEFT JOIN subscribers s ON s.id = t.subscriber_id LEFT JOIN users c ON c.id = t.created_by WHERE $W";
if (in_str('export') === 'csv') {
    $rows = Db::all("SELECT t.created_at, a.username AS agent, t.type, t.amount, t.balance_after, s.username AS sub, t.note, c.username AS by_user $base ORDER BY t.id", $p);
    send_csv('transactions', ['التاريخ', 'الوكيل', 'النوع', 'المبلغ', 'الرصيد بعدها', 'المشترك', 'ملاحظة', 'بواسطة'], array_map(function ($r) {
        $r['type'] = tx_label($r['type']);
        return $r;
    }, $rows));
}
$sum = Db::one("SELECT COALESCE(SUM(IF(t.amount > 0, t.amount, 0)),0) AS plus, COALESCE(SUM(IF(t.amount < 0, t.amount, 0)),0) AS minus, COUNT(*) AS n $base", $p);
[$off, $per, $pg] = page_args(100);
$tx = Db::all("SELECT t.*, a.username AS agent, s.username AS sub, c.username AS by_user $base ORDER BY t.id DESC LIMIT $per OFFSET $off", $p);
$showUser = $staff && !$uid;
$ex = $_GET;
$ex['export'] = 'csv';
layout_start($staff ? 'الحركات المالية' : 'كشف الحساب', 'transactions', '<a class="btn" href="?' . h(http_build_query($ex)) . '">تصدير Excel</a>');
?>
<form class="filters card" method="get">
  <input type="hidden" name="p" value="transactions">
  <?php if ($staff): ?><select name="user_id" data-autosubmit><?= select_opts(agent_options(), $uid ?: '', 'كل الوكلاء') ?></select><?php endif; ?>
  <select name="type" data-autosubmit><?= select_opts(['deposit' => 'إيداع', 'withdraw' => 'سحب', 'activation' => 'تفعيل', 'refund' => 'استرجاع', 'cards' => 'شراء كروت', 'adjust' => 'تسوية'], $type, 'كل الأنواع') ?></select>
  <label class="inline">من <input type="date" name="from" value="<?= h($from) ?>"></label>
  <label class="inline">إلى <input type="date" name="to" value="<?= h($to) ?>"></label>
  <button class="btn">عرض</button>
</form>
<div class="tiles">
  <?= stat_tile('عدد الحركات', number_format((int) $sum['n'])) ?>
  <?= stat_tile('إضافات', number_format((int) $sum['plus']), 'ok') ?>
  <?= stat_tile('خصومات', number_format(-(int) $sum['minus']), 'bad') ?>
  <?php if ($uid): $bal = Db::val('SELECT balance FROM users WHERE id = ?', [$uid]); ?><?= stat_tile('الرصيد الحالي', money($bal)) ?><?php endif; ?>
</div>
<div class="card flush"><?php include APP_DIR . '/pages/_tx_table.php'; ?></div>
<?= pager((int) $sum['n'], $per, $pg) ?>
<?php layout_end();
