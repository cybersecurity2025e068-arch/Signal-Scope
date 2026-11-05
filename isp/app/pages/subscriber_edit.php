<?php
$id = in_int('id');
$s = $id ? Subscribers::get($id) : null;
$v = $s ?: ['username' => '', 'password' => '', 'full_name' => '', 'phone' => '', 'phone2' => '', 'address' => '', 'zone' => '', 'national_id' => '', 'mac' => '', 'static_ip' => '', 'device' => '', 'notes' => '', 'plan_id' => '', 'agent_id' => '', 'lat' => '', 'lng' => ''];
$err = '';
if (is_post()) {
    $in = $_POST;
    try {
        $newId = Subscribers::save($in, $id ?: null);
        flash('ok', $id ? 'حُفظت التعديلات.' : 'أُضيف المشترك. فعّله الآن بالباقة المناسبة.');
        redirect(url('subscriber', ['id' => $newId]));
    } catch (UserError $e) {
        $err = $e->getMessage();
        foreach ($v as $k => $_) {
            if (isset($in[$k]) && is_string($in[$k])) {
                $v[$k] = $in[$k];
            }
        }
    }
}
$zones = array_column(Db::all("SELECT DISTINCT zone FROM subscribers WHERE zone <> '' ORDER BY zone LIMIT 300"), 'zone');
layout_start($id ? 'تعديل ' . $s['username'] : 'مشترك جديد', 'subscribers');
if ($err) {
    echo '<div class="alert error">' . h($err) . '</div>';
}
?>
<form method="post" class="card form-grid">
  <?= csrf_field() ?>
  <label>اسم المستخدم (PPPoE) *<input name="username" value="<?= h($v['username']) ?>" dir="ltr" required pattern="[A-Za-z0-9._@\-]{3,64}" autocomplete="off"></label>
  <label>كلمة المرور <?= $id ? '<small class="muted">(اتركها فارغة لعدم التغيير)</small>' : '<small class="muted">(فارغة = تُولَّد تلقائياً)</small>' ?>
    <input name="password" value="" dir="ltr" autocomplete="new-password"></label>
  <label>الاسم الكامل<input name="full_name" value="<?= h($v['full_name']) ?>"></label>
  <label>الهاتف<input name="phone" value="<?= h($v['phone']) ?>" dir="ltr" inputmode="tel"></label>
  <label>هاتف آخر<input name="phone2" value="<?= h($v['phone2']) ?>" dir="ltr" inputmode="tel"></label>
  <label>المنطقة<input name="zone" value="<?= h($v['zone']) ?>" list="zones"></label>
  <datalist id="zones"><?php foreach ($zones as $z): ?><option value="<?= h($z) ?>"><?php endforeach; ?></datalist>
  <label class="span2">العنوان<input name="address" value="<?= h($v['address']) ?>"></label>
  <label>رقم الهوية<input name="national_id" value="<?= h($v['national_id']) ?>"></label>
  <label>الباقة<select name="plan_id"><?= select_opts(plan_options(false), $v['plan_id'], '— بلا باقة —') ?></select></label>
  <?php if (Auth::isStaff()): ?>
  <label>الوكيل<select name="agent_id"><?= select_opts(agent_options(), $v['agent_id'], 'بلا وكيل (مباشر)') ?></select></label>
  <?php endif; ?>
  <label>MAC<input name="mac" value="<?= h($v['mac']) ?>" dir="ltr" placeholder="AA:BB:CC:DD:EE:FF"></label>
  <label>IP ثابت<input name="static_ip" value="<?= h($v['static_ip']) ?>" dir="ltr"></label>
  <label>الجهاز (راوتر/أنتنا)<input name="device" value="<?= h($v['device']) ?>"></label>
  <label>خط العرض<input name="lat" value="<?= h((string) $v['lat']) ?>" dir="ltr" inputmode="decimal"></label>
  <label>خط الطول<input name="lng" value="<?= h((string) $v['lng']) ?>" dir="ltr" inputmode="decimal"></label>
  <label class="span2">ملاحظات<textarea name="notes" rows="3"><?= h((string) $v['notes']) ?></textarea></label>
  <div class="span2"><button class="btn primary">حفظ</button> <a class="btn" href="<?= h($id ? url('subscriber', ['id' => $id]) : url('subscribers')) ?>">إلغاء</a></div>
</form>
<?php layout_end();
