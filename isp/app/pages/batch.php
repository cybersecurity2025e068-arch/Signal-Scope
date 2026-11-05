<?php
$id = in_int('id');
$b = Db::one('SELECT b.*, p.name AS plan, p.duration_days, p.speed_down, u.username AS agent FROM card_batches b JOIN plans p ON p.id = b.plan_id LEFT JOIN users u ON u.id = b.agent_id WHERE b.id = ?', [$id]);
if (!$b || (!Auth::isStaff() && (int) $b['agent_id'] !== (int) $ME['id'])) {
    throw new UserError('الدفعة غير موجودة.');
}
if (is_post()) {
    Auth::requireAdmin();
    $cid = in_int('card_id');
    $n = Db::q("UPDATE cards SET status = 'disabled' WHERE id = ? AND batch_id = ? AND status = 'new'", [$cid, $id])->rowCount();
    if ($n) {
        Audit::log('card_disable', 'batch', $id, 'كارت #' . $cid);
    }
    flash($n ? 'ok' : 'error', $n ? 'عُطّل الكارت.' : 'لا يمكن تعطيل هذا الكارت.');
    redirect(url('batch', ['id' => $id]));
}
$status = in_str('status');
$sw = isset(['new' => 1, 'used' => 1, 'disabled' => 1][$status]) ? ' AND c.status = ' . Db::pdo()->quote($status) : '';
$cards = Db::all("SELECT c.*, s.username AS sub FROM cards c LEFT JOIN subscribers s ON s.id = c.subscriber_id WHERE c.batch_id = ?$sw ORDER BY c.id", [$id]);

if (in_str('export') === 'csv') {
    send_csv('cards-' . $id, ['التسلسل', 'الرقم السري', 'الباقة', 'الحالة', 'المشترك', 'تاريخ الاستخدام'], array_map(function ($c) use ($b) {
        return [$c['serial'], $c['pin'], $b['plan'], $c['status'], $c['sub'], $c['used_at']];
    }, $cards));
}
if (in_str('print') === '1') {
    layout_head('طباعة الكروت');
    $co = Settings::get('company_name');
    $sup = Settings::get('support_phone');
    echo '<body class="print-cards"><div class="no-print bar-print"><button class="btn primary" data-print>طباعة</button> <a class="btn" href="' . h(url('batch', ['id' => $id])) . '">رجوع</a></div><div class="card-sheet">';
    foreach ($cards as $c) {
        if ($c['status'] !== 'new') {
            continue;
        }
        echo '<div class="vcard"><div class="vc-top"><b>' . h($co) . '</b><span>' . h($b['plan']) . '</span></div>'
            . '<div class="vc-pin" dir="ltr">' . h(trim(chunk_split($c['pin'], 4, ' '))) . '</div>'
            . '<div class="vc-foot"><span dir="ltr">S/N ' . h($c['serial']) . '</span><span>' . (int) $b['duration_days'] . ' يوم</span></div>'
            . ($sup ? '<div class="vc-sup">الدعم: <span dir="ltr">' . h($sup) . '</span></div>' : '') . '</div>';
    }
    echo '</div></body></html>';
    exit;
}
$cnt = ['new' => 0, 'used' => 0, 'disabled' => 0];
foreach (Db::all('SELECT status, COUNT(*) n FROM cards WHERE batch_id = ? GROUP BY status', [$id]) as $r) {
    $cnt[$r['status']] = (int) $r['n'];
}
$act = '<a class="btn primary" href="' . h(url('batch', ['id' => $id, 'print' => 1])) . '">طباعة الكروت الجديدة</a> <a class="btn" href="' . h(url('batch', ['id' => $id, 'export' => 'csv'])) . '">تصدير Excel</a>';
layout_start('دفعة كروت #' . $id, 'cards', $act);
?>
<div class="tiles">
  <?= stat_tile('الباقة', h($b['plan'])) ?>
  <?= stat_tile('جديد', number_format($cnt['new']), 'ok', url('batch', ['id' => $id, 'status' => 'new'])) ?>
  <?= stat_tile('مستخدم', number_format($cnt['used']), '', url('batch', ['id' => $id, 'status' => 'used'])) ?>
  <?= stat_tile('معطّل', number_format($cnt['disabled']), 'mute', url('batch', ['id' => $id, 'status' => 'disabled'])) ?>
  <?= stat_tile('الوكيل', h($b['agent'] ?? 'الإدارة')) ?>
</div>
<div class="card flush">
<table class="tbl">
  <thead><tr><th>التسلسل</th><th>الرقم السري</th><th>الحالة</th><th>المشترك</th><th>تاريخ الاستخدام</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($cards as $c): ?>
    <tr><td dir="ltr"><?= h($c['serial']) ?></td><td dir="ltr" class="mono"><?= h($c['pin']) ?></td>
    <td><?= h(['new' => 'جديد', 'used' => 'مستخدم', 'disabled' => 'معطّل'][$c['status']]) ?></td>
    <td><?= $c['sub'] ? '<a href="' . h(url('subscriber', ['id' => $c['subscriber_id']])) . '">' . h($c['sub']) . '</a>' : '' ?></td>
    <td class="small"><?= h(fmt_dt($c['used_at'])) ?></td>
    <td><?php if (Auth::isAdmin() && $c['status'] === 'new'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="card_id" value="<?= (int) $c['id'] ?>"><button class="linkbtn small" data-confirm="تعطيل الكارت؟">تعطيل</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php layout_end();
