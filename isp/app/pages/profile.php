<?php
if (is_post()) {
    $cur = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    if (!password_verify($cur, $ME['pass_hash'])) {
        throw new UserError('كلمة المرور الحالية غير صحيحة.');
    }
    if ($e = Auth::validPassword($new)) {
        throw new UserError($e);
    }
    if ($new !== (string) ($_POST['confirm'] ?? '')) {
        throw new UserError('تأكيد كلمة المرور غير مطابق.');
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    Db::update('users', ['pass_hash' => $hash], 'id = ?', [$ME['id']]);
    $_SESSION['pv'] = substr($hash, -12);
    Audit::log('password', 'user', (int) $ME['id']);
    flash('ok', 'تغيّرت كلمة المرور. الجلسات الأخرى خرجت تلقائياً.');
    redirect(url('profile'));
}
layout_start('الملف الشخصي', '');
?>
<div class="grid2">
<section class="card"><dl class="kv">
  <dt>اسم الدخول</dt><dd dir="ltr"><?= h($ME['username']) ?></dd>
  <dt>الاسم</dt><dd><?= h($ME['full_name']) ?></dd>
  <dt>النوع</dt><dd><?= h(role_label($ME['role'])) ?></dd>
  <?php if ($ME['role'] === 'agent'): ?><dt>الرصيد</dt><dd><?= money($ME['balance']) ?></dd><?php endif; ?>
  <dt>آخر دخول</dt><dd><?= h(fmt_dt($ME['last_login_at'])) ?> <span dir="ltr" class="muted small"><?= h((string) $ME['last_login_ip']) ?></span></dd>
</dl></section>
<form method="post" class="card stack">
  <h2>تغيير كلمة المرور</h2>
  <?= csrf_field() ?>
  <label>الحالية<input type="password" name="current" required autocomplete="current-password" dir="ltr"></label>
  <label>الجديدة (8 أحرف على الأقل)<input type="password" name="new" required minlength="8" autocomplete="new-password" dir="ltr"></label>
  <label>تأكيد الجديدة<input type="password" name="confirm" required minlength="8" autocomplete="new-password" dir="ltr"></label>
  <button class="btn primary">حفظ</button>
</form>
</div>
<?php layout_end();
