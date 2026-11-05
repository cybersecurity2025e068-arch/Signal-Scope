<?php
$info = null;
if (is_post()) {
    $uname = in_str('username');
    [$w, $p] = Subscribers::scope();
    $sid = (int) Db::val("SELECT s.id FROM subscribers s WHERE s.username = ? AND $w", array_merge([$uname], $p));
    if (!$sid) {
        throw new UserError('لا يوجد مشترك باسم المستخدم "' . $uname . '" ضمن صلاحياتك.');
    }
    $r = Cards::redeem(in_str('pin'), $sid);
    flash('ok', 'شُحن ' . $uname . ' حتى ' . fmt_dt($r['end']));
    redirect(url('subscriber', ['id' => $sid]));
}
if (in_str('check') !== '') {
    $pin = Cards::normalizePin(in_str('check'));
    $c = Db::one('SELECT c.*, p.name AS plan, b.unit_price, s.username AS used_by, u.username AS agent FROM cards c JOIN plans p ON p.id = c.plan_id JOIN card_batches b ON b.id = c.batch_id LEFT JOIN subscribers s ON s.id = c.subscriber_id LEFT JOIN users u ON u.id = c.agent_id WHERE c.pin = ? OR c.serial = ?', [$pin, $pin]);
    if ($c && (Auth::isStaff() || (int) $c['agent_id'] === (int) $ME['id'])) {
        $info = $c;
    } else {
        flash('error', 'الكارت غير موجود.');
    }
}
layout_start('تفعيل بكارت', 'redeem');
?>
<div class="grid2">
<form method="post" class="card stack">
  <h2>شحن اشتراك بكارت</h2>
  <?= csrf_field() ?>
  <label>اسم مستخدم المشترك<input name="username" dir="ltr" required autocomplete="off"></label>
  <label>رقم الكارت (PIN)<input name="pin" dir="ltr" inputmode="numeric" required autocomplete="off"></label>
  <button class="btn primary">شحن</button>
</form>
<form method="get" class="card stack">
  <h2>فحص كارت</h2>
  <input type="hidden" name="p" value="redeem">
  <label>الرقم السري أو التسلسلي<input name="check" dir="ltr" inputmode="numeric" required value="<?= h(in_str('check')) ?>"></label>
  <button class="btn">فحص</button>
  <?php if ($info): ?>
  <dl class="kv">
    <dt>التسلسل</dt><dd dir="ltr"><?= h($info['serial']) ?></dd>
    <dt>الباقة</dt><dd><?= h($info['plan']) ?></dd>
    <dt>الحالة</dt><dd><?= h(['new' => 'جديد', 'used' => 'مستخدم', 'disabled' => 'معطّل'][$info['status']]) ?></dd>
    <?php if ($info['used_by']): ?><dt>استخدمه</dt><dd><a href="<?= h(url('subscriber', ['id' => $info['subscriber_id']])) ?>"><?= h($info['used_by']) ?></a> · <?= h(fmt_dt($info['used_at'])) ?></dd><?php endif; ?>
    <dt>الوكيل</dt><dd><?= h($info['agent'] ?? 'الإدارة') ?></dd>
    <dt>الدفعة</dt><dd><a href="<?= h(url('batch', ['id' => $info['batch_id']])) ?>">#<?= (int) $info['batch_id'] ?></a></dd>
  </dl>
  <?php endif; ?>
</form>
</div>
<?php layout_end();
