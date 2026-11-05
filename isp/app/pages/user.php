<?php
Auth::requireStaff();
$id = in_int('id');
$isNew = !$id;
$u = $id ? Db::one('SELECT * FROM users WHERE id = ?', [$id]) : null;
if ($id && !$u) {
    throw new UserError('المستخدم غير موجود.');
}
$self = $id ? url('user', ['id' => $id]) : url('users');

if (is_post()) {
    Auth::requireAdmin();
    $a = in_str('action');
    if ($a === 'save') {
        $d = [
            'full_name' => in_str('full_name'),
            'phone' => in_str('phone'),
            'zone' => in_str('zone'),
            'notes' => in_str('notes'),
            'credit_limit' => max(0, in_int('credit_limit')),
            'active' => in_str('active') === '1' ? 1 : 0,
        ];
        $role = in_str('role', 'agent');
        if (!in_array($role, ['admin', 'staff', 'agent'], true)) {
            $role = 'agent';
        }
        $pass = (string) ($_POST['password'] ?? '');
        if ($isNew) {
            $uname = in_str('username');
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $uname)) {
                throw new UserError('اسم الدخول: 3 إلى 50 حرفاً إنجليزياً أو أرقاماً.');
            }
            if (Db::val('SELECT id FROM users WHERE username = ?', [$uname])) {
                throw new UserError('اسم الدخول مستخدم.');
            }
            if ($e = Auth::validPassword($pass)) {
                throw new UserError($e);
            }
            $d += ['username' => $uname, 'role' => $role, 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'created_at' => now()];
            $id = Db::insert('users', $d);
        } else {
            if ((int) $id === (int) $ME['id'] && ($role !== 'admin' || !$d['active'])) {
                throw new UserError('لا يمكنك إزالة صلاحية المدير أو تعطيل حسابك بنفسك.');
            }
            $d['role'] = $role;
            if ($pass !== '') {
                if ($e = Auth::validPassword($pass)) {
                    throw new UserError($e);
                }
                $d['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            }
            Db::update('users', $d, 'id = ?', [$id]);
        }
        Audit::log('user_save', 'user', $id, ($u['username'] ?? $d['username']) . ' · ' . role_label($role) . ($pass !== '' ? ' · كلمة مرور جديدة' : ''));
        flash('ok', 'حُفظ الحساب.');
        redirect(url('user', ['id' => $id]));
    }
    if (in_array($a, ['deposit', 'withdraw', 'adjust'], true)) {
        if ($u['role'] !== 'agent') {
            throw new UserError('الرصيد للوكلاء فقط.');
        }
        $amt = in_int('amount');
        if ($a !== 'adjust' && $amt <= 0) {
            throw new UserError('أدخل مبلغاً صحيحاً.');
        }
        $signed = $a === 'withdraw' ? -$amt : $amt;
        [, $after] = Billing::move($id, $signed, $a, ['note' => in_str('note'), 'force' => $a === 'adjust']);
        Audit::log('balance', 'user', $id, tx_label($a) . ' ' . number_format($signed) . ' · الرصيد ' . number_format($after));
        flash('ok', tx_label($a) . ' ' . number_format(abs($signed)) . ' — الرصيد الآن ' . number_format($after));
        redirect($self);
    }
    redirect($self);
}

$v = $u ?: ['username' => '', 'full_name' => '', 'phone' => '', 'zone' => '', 'notes' => '', 'credit_limit' => 0, 'active' => 1, 'role' => 'agent'];
layout_start($isNew ? 'حساب جديد' : ($u['full_name'] ?: $u['username']), 'users');

if (!$isNew && $u['role'] === 'agent'):
    $now = now();
    $st = Db::one('SELECT COUNT(*) total, SUM(suspended = 0 AND expires_at >= ?) active, SUM(suspended = 0 AND expires_at < ?) expired FROM subscribers WHERE agent_id = ?', [$now, $now, $id]);
    $m = Db::one('SELECT COUNT(*) n, COALESCE(SUM(price),0) v FROM activations WHERE agent_id = ? AND cancelled = 0 AND created_at >= ?', [$id, date('Y-m-01')]);
?>
<div class="tiles">
  <?= stat_tile('الرصيد', money($u['balance']), (int) $u['balance'] < 0 ? 'bad' : 'ok') ?>
  <?= stat_tile('حد الائتمان', money($u['credit_limit'])) ?>
  <?= stat_tile('المشتركون', number_format((int) $st['active']) . ' <small>فعّال من ' . number_format((int) $st['total']) . '</small>', '', url('subscribers', ['agent_id' => $id])) ?>
  <?= stat_tile('منتهي', number_format((int) $st['expired']), 'bad', url('subscribers', ['agent_id' => $id, 'state' => 'expired'])) ?>
  <?= stat_tile('تفعيلات الشهر', number_format((int) $m['n']) . ' <small>' . money($m['v']) . '</small>') ?>
</div>
<?php if (Auth::isAdmin()): ?>
<section class="card">
  <h2>حركة رصيد</h2>
  <form method="post" class="row wrap">
    <?= csrf_field() ?>
    <select name="action"><option value="deposit">إيداع (إضافة رصيد)</option><option value="withdraw">سحب (خصم)</option><option value="adjust">تسوية (+/-) بلا قيود</option></select>
    <input name="amount" inputmode="numeric" placeholder="المبلغ" required class="w-m">
    <input name="note" placeholder="ملاحظة (رقم الوصل…)">
    <button class="btn primary" data-confirm="تأكيد حركة الرصيد؟">تنفيذ</button>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if (Auth::isAdmin()): ?>
<form method="post" class="card form-grid">
  <h2 class="span2"><?= $isNew ? 'بيانات الحساب' : 'تعديل الحساب' ?></h2>
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <label>اسم الدخول *<input name="username" value="<?= h($v['username']) ?>" dir="ltr" <?= $isNew ? 'required' : 'disabled' ?> autocomplete="off"></label>
  <label>النوع<select name="role"><?= select_opts(['agent' => 'وكيل (يرى مشتركيه فقط ويدفع من رصيده)', 'staff' => 'موظف (يدير كل المشتركين)', 'admin' => 'مدير (كل الصلاحيات)'], $v['role']) ?></select></label>
  <label>كلمة المرور <?= $isNew ? '*' : '<small class="muted">(فارغة = بلا تغيير)</small>' ?><input type="password" name="password" dir="ltr" autocomplete="new-password" <?= $isNew ? 'required minlength="8"' : '' ?>></label>
  <label>الاسم الكامل<input name="full_name" value="<?= h($v['full_name']) ?>"></label>
  <label>الهاتف<input name="phone" value="<?= h($v['phone']) ?>" dir="ltr"></label>
  <label>المنطقة<input name="zone" value="<?= h($v['zone']) ?>"></label>
  <label>حد الائتمان <small class="muted">(كم يسمح بالسالب)</small><input name="credit_limit" value="<?= h((string) $v['credit_limit']) ?>" inputmode="numeric"></label>
  <label class="check"><input type="checkbox" name="active" value="1" <?= $v['active'] ? 'checked' : '' ?>> الحساب فعّال</label>
  <label class="span2">ملاحظات<textarea name="notes" rows="2"><?= h((string) $v['notes']) ?></textarea></label>
  <div class="span2"><button class="btn primary">حفظ</button></div>
</form>
<?php elseif (!$isNew): ?>
<section class="card"><dl class="kv"><dt>اسم الدخول</dt><dd dir="ltr"><?= h($u['username']) ?></dd><dt>النوع</dt><dd><?= h(role_label($u['role'])) ?></dd><dt>الهاتف</dt><dd dir="ltr"><?= h($u['phone']) ?></dd></dl></section>
<?php endif; ?>

<?php if (!$isNew && $u['role'] === 'agent'):
    $tx = Db::all('SELECT t.*, s.username AS sub, c.username AS by_user FROM transactions t LEFT JOIN subscribers s ON s.id = t.subscriber_id LEFT JOIN users c ON c.id = t.created_by WHERE t.user_id = ? ORDER BY t.id DESC LIMIT 30', [$id]); ?>
<section class="card">
  <h2>آخر الحركات <a class="small" href="<?= h(url('transactions', ['user_id' => $id])) ?>">كشف الحساب الكامل</a></h2>
  <?php include APP_DIR . '/pages/_tx_table.php'; ?>
</section>
<?php endif; ?>
<?php layout_end();
