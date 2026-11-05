<?php
Auth::requireStaff();
$edit = in_int('edit');
if (is_post()) {
    Auth::requireAdmin();
    $pid = in_int('id');
    $d = [
        'name' => in_str('name'),
        'speed_down' => max(0, in_int('speed_down')),
        'speed_up' => max(0, in_int('speed_up')),
        'duration_days' => max(1, min(3650, in_int('duration_days', 30))),
        'price' => max(0, in_int('price')),
        'retail_price' => max(0, in_int('retail_price')),
        'quota_gb' => max(0, in_int('quota_gb')),
        'radius_group' => mb_substr(in_str('radius_group'), 0, 64),
        'sort_order' => in_int('sort_order'),
        'active' => in_str('active') === '1' ? 1 : 0,
    ];
    if ($d['name'] === '') {
        throw new UserError('اسم الباقة مطلوب.');
    }
    if ($pid) {
        Db::update('plans', $d, 'id = ?', [$pid]);
    } else {
        $d['created_at'] = now();
        $pid = Db::insert('plans', $d);
    }
    Audit::log('plan_save', 'plan', $pid, $d['name'] . ' · ' . $d['price']);
    if (Radius::enabled() && in_str('resync') === '1') {
        foreach (Db::all('SELECT id FROM subscribers WHERE plan_id = ?', [$pid]) as $r) {
            Radius::sync((int) $r['id']);
        }
    }
    flash('ok', 'حُفظت الباقة.');
    redirect(url('plans'));
}
$rows = Db::all('SELECT p.*, (SELECT COUNT(*) FROM subscribers s WHERE s.plan_id = p.id) AS subs FROM plans p ORDER BY p.active DESC, p.sort_order, p.price');
$e = $edit ? Db::one('SELECT * FROM plans WHERE id = ?', [$edit]) : null;
$e = $e ?: ['id' => 0, 'name' => '', 'speed_down' => '', 'speed_up' => '', 'duration_days' => 30, 'price' => '', 'retail_price' => '', 'quota_gb' => 0, 'radius_group' => '', 'sort_order' => 0, 'active' => 1];
layout_start('الباقات', 'plans');
?>
<div class="card flush">
<table class="tbl">
  <thead><tr><th>الباقة</th><th>السرعة (تنزيل/رفع)</th><th>المدة</th><th>سعر الوكيل</th><th>سعر المشترك</th><th>المشتركون</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr<?= $r['active'] ? '' : ' class="cancelled"' ?>><td><b><?= h($r['name']) ?></b><?= $r['active'] ? '' : ' <span class="badge st-suspended">متوقفة</span>' ?><?= $r['quota_gb'] ? '<div class="muted small">' . (int) $r['quota_gb'] . ' GB</div>' : '' ?></td>
    <td dir="ltr"><?= (int) $r['speed_down'] ?>/<?= (int) $r['speed_up'] ?> Mbps</td><td><?= (int) $r['duration_days'] ?> يوم</td>
    <td><?= money($r['price']) ?></td><td><?= money($r['retail_price']) ?></td>
    <td><a href="<?= h(url('subscribers', ['plan_id' => $r['id']])) ?>"><?= number_format((int) $r['subs']) ?></a></td>
    <td><?php if (Auth::isAdmin()): ?><a class="btn small" href="<?= h(url('plans', ['edit' => $r['id']])) ?>#form">تعديل</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted center">أضف أول باقة من النموذج أدناه.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if (Auth::isAdmin()): ?>
<form method="post" class="card form-grid" id="form">
  <h2 class="span2"><?= $e['id'] ? 'تعديل الباقة' : 'باقة جديدة' ?></h2>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
  <label>الاسم *<input name="name" value="<?= h($e['name']) ?>" required placeholder="مثال: باقة 25 ميگا"></label>
  <label>المدة (أيام)<input type="number" name="duration_days" value="<?= h((string) $e['duration_days']) ?>" min="1" required></label>
  <label>سرعة التنزيل (Mbps)<input type="number" name="speed_down" value="<?= h((string) $e['speed_down']) ?>" min="0"></label>
  <label>سرعة الرفع (Mbps)<input type="number" name="speed_up" value="<?= h((string) $e['speed_up']) ?>" min="0"></label>
  <label>سعر الوكيل (يُخصم من رصيده)<input name="price" value="<?= h((string) $e['price']) ?>" inputmode="numeric" required></label>
  <label>سعر البيع للمشترك<input name="retail_price" value="<?= h((string) $e['retail_price']) ?>" inputmode="numeric"></label>
  <label>حجم البيانات GB <small class="muted">(0 = غير محدود)</small><input type="number" name="quota_gb" value="<?= h((string) $e['quota_gb']) ?>" min="0"></label>
  <label>مجموعة RADIUS <small class="muted">(اختياري)</small><input name="radius_group" value="<?= h($e['radius_group']) ?>" dir="ltr"></label>
  <label>ترتيب العرض<input type="number" name="sort_order" value="<?= h((string) $e['sort_order']) ?>"></label>
  <label class="check"><input type="checkbox" name="active" value="1" <?= $e['active'] ? 'checked' : '' ?>> متاحة للتفعيل</label>
  <?php if ($e['id'] && Radius::enabled()): ?><label class="check"><input type="checkbox" name="resync" value="1" checked> تطبيق السرعة الجديدة على مشتركي الباقة في RADIUS</label><?php endif; ?>
  <div class="span2"><button class="btn primary">حفظ</button><?php if ($e['id']): ?> <a class="btn" href="<?= h(url('plans')) ?>">إلغاء</a><?php endif; ?></div>
</form>
<?php endif; ?>
<?php layout_end();
