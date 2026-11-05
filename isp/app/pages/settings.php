<?php
Auth::requireAdmin();
if (is_post()) {
    foreach (['company_name', 'currency', 'support_phone', 'portal_notice'] as $k) {
        Settings::set($k, mb_substr(in_str($k), 0, 500));
    }
    Settings::set('expiry_warn_days', (string) max(1, min(30, in_int('expiry_warn_days', 3))));
    foreach (['portal_enabled', 'agent_can_create', 'agent_can_delete'] as $k) {
        Settings::set($k, in_str($k) === '1' ? '1' : '0');
    }
    Audit::log('settings');
    flash('ok', 'حُفظت الإعدادات.');
    redirect(url('settings'));
}
$s = Settings::all();
layout_start('الإعدادات', 'settings');
$chk = function ($k) use ($s) { return $s[$k] === '1' ? 'checked' : ''; };
?>
<form method="post" class="card form-grid">
  <?= csrf_field() ?>
  <label>اسم الشبكة / الشركة<input name="company_name" value="<?= h($s['company_name']) ?>" required></label>
  <label>العملة<input name="currency" value="<?= h($s['currency']) ?>"></label>
  <label>هاتف الدعم الفني<input name="support_phone" value="<?= h($s['support_phone']) ?>" dir="ltr"></label>
  <label>التنبيه قبل الانتهاء (أيام)<input type="number" name="expiry_warn_days" value="<?= h($s['expiry_warn_days']) ?>" min="1" max="30"></label>
  <label class="check"><input type="checkbox" name="agent_can_create" value="1" <?= $chk('agent_can_create') ?>> يسمح للوكلاء بإضافة مشتركين</label>
  <label class="check"><input type="checkbox" name="agent_can_delete" value="1" <?= $chk('agent_can_delete') ?>> يسمح للوكلاء بحذف مشتركيهم</label>
  <label class="check"><input type="checkbox" name="portal_enabled" value="1" <?= $chk('portal_enabled') ?>> تفعيل بوابة المشترك (يرى اشتراكه ويشحن بكارت)</label>
  <label class="span2">إعلان يظهر في بوابة المشترك<textarea name="portal_notice" rows="2"><?= h($s['portal_notice']) ?></textarea></label>
  <div class="span2"><button class="btn primary">حفظ</button></div>
</form>
<section class="card">
  <h2>معلومات النظام</h2>
  <dl class="kv">
    <dt>الإصدار</dt><dd><?= h(APP_VERSION) ?></dd>
    <dt>PHP</dt><dd><?= h(PHP_VERSION) ?></dd>
    <dt>ربط RADIUS</dt><dd><?= Radius::enabled() ? 'مفعّل' : 'غير مفعّل (config.php ← radius.enabled)' ?></dd>
    <dt>رابط بوابة المشترك</dt><dd dir="ltr"><a href="<?= h(url('portal')) ?>"><?= h(url('portal')) ?></a></dd>
  </dl>
</section>
<?php layout_end();
