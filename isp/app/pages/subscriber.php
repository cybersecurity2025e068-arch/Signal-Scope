<?php
$id = in_int('id');
$s = Subscribers::get($id);
$self = url('subscriber', ['id' => $id]);

if (is_post()) {
    $a = in_str('action');
    switch ($a) {
        case 'activate':
            $r = Subscribers::activate($id, in_int('plan_id'), ['charge' => in_str('charge') === '1']);
            flash('ok', 'تم التفعيل حتى ' . fmt_dt($r['end']) . ($r['method'] === 'balance' ? ' · خُصم ' . number_format($r['price']) : '') . ($r['suspended'] ? ' · تنبيه: المشترك ما زال موقوفاً' : ''));
            redirect($self . '&receipt=last');
            break;
        case 'card':
            $r = Cards::redeem(in_str('pin'), $id);
            flash('ok', 'تم شحن الكارت. الاشتراك حتى ' . fmt_dt($r['end']));
            break;
        case 'suspend':
            Subscribers::setSuspended($id, true, in_str('reason'));
            flash('ok', 'أُوقف المشترك.');
            break;
        case 'resume':
            Subscribers::setSuspended($id, false);
            flash('ok', 'أُعيد تشغيل المشترك.');
            break;
        case 'extend':
            Subscribers::extend($id, in_int('days'), in_str('note'));
            flash('ok', 'عُدّل تاريخ الانتهاء.');
            break;
        case 'set_expiry':
            Subscribers::setExpiry($id, in_str('expires_at'));
            flash('ok', 'عُدّل تاريخ الانتهاء.');
            break;
        case 'transfer':
            Subscribers::transfer($id, in_int('agent_id'));
            flash('ok', 'نُقل المشترك.');
            break;
        case 'cancel_last':
            Subscribers::cancelLast($id);
            flash('ok', 'أُلغي آخر تفعيل وأُرجع المبلغ أو الكارت.');
            break;
        case 'sync':
            $e = Radius::sync($id);
            flash($e ? 'error' : 'ok', $e ?: 'تمت المزامنة مع RADIUS.');
            break;
        case 'delete':
            Subscribers::delete($id);
            flash('ok', 'حُذف المشترك ' . $s['username']);
            redirect(url('subscribers'));
    }
    redirect($self);
}

$plan = $s['plan_id'] ? Db::one('SELECT * FROM plans WHERE id = ?', [$s['plan_id']]) : null;
$agent = $s['agent_id'] ? Db::one('SELECT id, username, full_name, balance FROM users WHERE id = ?', [$s['agent_id']]) : null;
$acts = Db::all('SELECT a.*, p.name AS plan, u.username AS by_user, c.serial FROM activations a JOIN plans p ON p.id = a.plan_id LEFT JOIN users u ON u.id = a.created_by LEFT JOIN cards c ON c.id = a.card_id WHERE a.subscriber_id = ? ORDER BY a.id DESC LIMIT 50', [$id]);
$st = sub_state($s);

// إيصال قابل للطباعة
if (in_str('receipt') === 'last' && $acts) {
    $r = $acts[0];
    layout_head('إيصال');
    ?>
<body class="receipt-page">
<div class="receipt">
  <h2><?= h(Settings::get('company_name')) ?></h2>
  <p class="muted">إيصال تفعيل اشتراك إنترنت</p>
  <table>
    <tr><th>رقم العملية</th><td>#<?= (int) $r['id'] ?></td></tr>
    <tr><th>المشترك</th><td><?= h($s['full_name']) ?></td></tr>
    <tr><th>اسم المستخدم</th><td dir="ltr"><?= h($s['username']) ?></td></tr>
    <tr><th>الباقة</th><td><?= h($r['plan']) ?></td></tr>
    <tr><th>المبلغ</th><td><?= money($r['retail_price'] ?: $r['price']) ?></td></tr>
    <tr><th>من</th><td><?= h(fmt_dt($r['starts_at'])) ?></td></tr>
    <tr><th>إلى</th><td><b><?= h(fmt_dt($r['ends_at'])) ?></b></td></tr>
    <tr><th>التاريخ</th><td><?= h(fmt_dt($r['created_at'])) ?></td></tr>
  </table>
  <?php if (Settings::get('support_phone')): ?><p>الدعم الفني: <span dir="ltr"><?= h(Settings::get('support_phone')) ?></span></p><?php endif; ?>
  <div class="no-print"><button class="btn primary" data-print>طباعة</button> <a class="btn" href="<?= h($self) ?>">رجوع</a></div>
</div>
</body></html>
    <?php
    exit;
}

$staff = Auth::isStaff();
$act = '<a class="btn" href="' . h(url('subscriber_edit', ['id' => $id])) . '">تعديل البيانات</a>';
if ($acts) {
    $act .= ' <a class="btn" href="' . h($self . '&receipt=last') . '">طباعة آخر إيصال</a>';
}
layout_start($s['username'], 'subscribers', $act);
?>
<div class="sub-head card">
  <div>
    <div class="sub-name"><?= h($s['full_name'] ?: $s['username']) ?> <?= state_badge($st) ?></div>
    <div class="muted"><span dir="ltr"><?= h($s['username']) ?></span><?= $s['phone'] ? ' · <span dir="ltr">' . h($s['phone']) . '</span>' : '' ?><?= $s['zone'] ? ' · ' . h($s['zone']) : '' ?></div>
    <?php if ($s['suspended'] && $s['suspend_reason']): ?><div class="alert warn small">سبب الإيقاف: <?= h($s['suspend_reason']) ?></div><?php endif; ?>
  </div>
  <div class="exp-box <?= h($st) ?>">
    <span>ينتهي</span><b><?= h(fmt_dt($s['expires_at'])) ?></b><small><?= h(days_left($s['expires_at'])) ?></small>
  </div>
</div>

<div class="grid2">
<section class="card">
  <h2>تفعيل / تجديد</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="activate">
    <label>الباقة<select name="plan_id" required><?= select_opts(plan_options(), $s['plan_id']) ?></select></label>
    <?php if ($staff && $agent): ?>
      <label class="check"><input type="checkbox" name="charge" value="1" checked> خصم السعر من رصيد الوكيل <?= h($agent['username']) ?> (<?= money($agent['balance']) ?>)</label>
    <?php elseif (!$staff): ?>
      <p class="muted small">يُخصم سعر الباقة من رصيدك (<?= money($ME['balance']) ?>).</p>
    <?php else: ?>
      <p class="muted small">مشترك مباشر: يُسجَّل التفعيل كبيع من الإدارة.</p>
    <?php endif; ?>
    <p class="muted small">إن كان الاشتراك سارياً تُضاف المدة إلى تاريخ الانتهاء الحالي.</p>
    <button class="btn primary" data-confirm="تأكيد تفعيل الاشتراك؟">تفعيل</button>
  </form>
  <hr>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="card">
    <input name="pin" placeholder="رقم الكارت" inputmode="numeric" dir="ltr" required>
    <button class="btn">شحن بكارت</button>
  </form>
</section>

<section class="card">
  <h2>البيانات</h2>
  <dl class="kv">
    <dt>كلمة مرور PPPoE</dt><dd dir="ltr"><span class="secret" data-secret="<?= h($s['password']) ?>">••••••</span> <button type="button" class="linkbtn small" data-reveal>إظهار</button></dd>
    <dt>الباقة</dt><dd><?= $plan ? h($plan['name']) . ' <span class="muted small">' . (int) $plan['speed_down'] . '/' . (int) $plan['speed_up'] . ' Mbps</span>' : '—' ?></dd>
    <dt>الوكيل</dt><dd><?= $agent ? ($staff ? '<a href="' . h(url('user', ['id' => $agent['id']])) . '">' . h($agent['full_name'] ?: $agent['username']) . '</a>' : h($agent['full_name'] ?: $agent['username'])) : 'مباشر' ?></dd>
    <dt>هاتف آخر</dt><dd dir="ltr"><?= h($s['phone2'] ?: '—') ?></dd>
    <dt>العنوان</dt><dd><?= h($s['address'] ?: '—') ?></dd>
    <dt>رقم الهوية</dt><dd><?= h($s['national_id'] ?: '—') ?></dd>
    <dt>MAC</dt><dd dir="ltr"><?= h($s['mac'] ?: '—') ?></dd>
    <dt>IP ثابت</dt><dd dir="ltr"><?= h($s['static_ip'] ?: '—') ?></dd>
    <dt>الجهاز</dt><dd><?= h($s['device'] ?: '—') ?></dd>
    <?php if ($s['lat'] !== null): ?><dt>الموقع</dt><dd dir="ltr"><?= h($s['lat'] . ', ' . $s['lng']) ?></dd><?php endif; ?>
    <dt>أُضيف</dt><dd><?= h(fmt_dt($s['created_at'])) ?></dd>
    <?php if ($s['notes']): ?><dt>ملاحظات</dt><dd><?= nl2br(h($s['notes'])) ?></dd><?php endif; ?>
  </dl>
</section>
</div>

<section class="card">
  <h2>إجراءات</h2>
  <div class="actions-grid">
    <?php if ($s['suspended']): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><button class="btn ok">إعادة التشغيل</button></form>
    <?php else: ?>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="suspend"><input name="reason" placeholder="سبب الإيقاف (اختياري)"><button class="btn warn" data-confirm="إيقاف المشترك؟ سيُقطع اتصاله.">إيقاف</button></form>
    <?php endif; ?>
    <?php if ($staff): ?>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="extend"><input type="number" name="days" placeholder="أيام (+/-)" required class="w-s"><input name="note" placeholder="ملاحظة"><button class="btn">تمديد مجاني</button></form>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="set_expiry"><input type="datetime-local" name="expires_at" value="<?= $s['expires_at'] ? h(date('Y-m-d\TH:i', strtotime($s['expires_at']))) : '' ?>" required><button class="btn">تعيين تاريخ الانتهاء</button></form>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="transfer"><select name="agent_id"><?= select_opts(['0' => 'بلا وكيل (مباشر)'] + agent_options(), (int) $s['agent_id']) ?></select><button class="btn">نقل للوكيل</button></form>
      <?php if (array_filter($acts, function ($a) { return !$a['cancelled']; })): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_last"><button class="btn" data-confirm="إلغاء آخر تفعيل وإرجاع المبلغ أو الكارت؟">إلغاء آخر تفعيل</button></form>
      <?php endif; ?>
      <?php if (Radius::enabled()): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn">مزامنة RADIUS</button></form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($staff || Settings::get('agent_can_delete') === '1'): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger" data-confirm="حذف المشترك نهائياً؟ لا يمكن التراجع.">حذف</button></form>
    <?php endif; ?>
  </div>
</section>

<?php if (Radius::enabled()):
    $sess = Radius::sessions($s['username'], 15);
    [$up, $down] = Radius::usage($s['username'], date('Y-m-d H:i:s', time() - 30 * 86400));
?>
<section class="card">
  <h2>الجلسات والاستهلاك</h2>
  <p>الاستهلاك خلال آخر 30 يوماً: تنزيل <b><?= h(Radius::bytes($down)) ?></b> · رفع <b><?= h(Radius::bytes($up)) ?></b><?= $plan && $plan['quota_gb'] ? ' من ' . (int) $plan['quota_gb'] . ' GB' : '' ?></p>
  <table class="tbl"><thead><tr><th>البداية</th><th>النهاية</th><th>IP</th><th>MAC</th><th>تنزيل/رفع</th><th>سبب الانتهاء</th></tr></thead><tbody>
  <?php foreach ($sess as $r): ?>
    <tr><td><?= h(fmt_dt($r['acctstarttime'])) ?></td><td><?= $r['acctstoptime'] ? h(fmt_dt($r['acctstoptime'])) : '<span class="badge st-active">متصل</span>' ?></td><td dir="ltr"><?= h($r['framedipaddress']) ?></td><td dir="ltr"><?= h($r['callingstationid']) ?></td><td dir="ltr"><?= h(Radius::bytes($r['acctoutputoctets'])) ?> / <?= h(Radius::bytes($r['acctinputoctets'])) ?></td><td><?= h($r['acctterminatecause']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$sess): ?><tr><td colspan="6" class="muted center">لا توجد جلسات.</td></tr><?php endif; ?>
  </tbody></table>
</section>
<?php endif; ?>

<section class="card">
  <h2>سجل التفعيلات</h2>
  <table class="tbl"><thead><tr><th>#</th><th>الباقة</th><th>الطريقة</th><th>المبلغ</th><th>من</th><th>إلى</th><th>بواسطة</th><th>التاريخ</th></tr></thead><tbody>
  <?php foreach ($acts as $a): ?>
    <tr<?= $a['cancelled'] ? ' class="cancelled"' : '' ?>><td><?= (int) $a['id'] ?></td><td><?= h($a['plan']) ?></td><td><?= h(method_label($a['method'])) ?><?= $a['serial'] ? ' <span class="muted small" dir="ltr">' . h($a['serial']) . '</span>' : '' ?><?= $a['cancelled'] ? ' <span class="badge st-suspended">ملغى</span>' : '' ?></td>
    <td><?= money($a['price']) ?></td><td class="small"><?= h(fmt_dt($a['starts_at'])) ?></td><td class="small"><?= h(fmt_dt($a['ends_at'])) ?></td><td><?= h($a['by_user'] ?? 'المشترك') ?></td><td class="small"><?= h(fmt_dt($a['created_at'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$acts): ?><tr><td colspan="8" class="muted center">لم يُفعَّل بعد.</td></tr><?php endif; ?>
  </tbody></table>
</section>

<?php if ($staff):
    $log = Db::all("SELECT * FROM audit_log WHERE entity = 'subscriber' AND entity_id = ? ORDER BY id DESC LIMIT 30", [$id]); ?>
<section class="card">
  <h2>سجل العمليات على المشترك</h2>
  <table class="tbl"><tbody>
  <?php foreach ($log as $l): ?>
    <tr><td class="small"><?= h(fmt_dt($l['created_at'])) ?></td><td><?= h($l['actor']) ?></td><td><?= h(Audit::label($l['action'])) ?></td><td class="small"><?= h($l['details']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>
<?php layout_end();
