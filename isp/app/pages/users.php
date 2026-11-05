<?php
Auth::requireStaff();
$role = in_str('role');
$q = in_str('q');
$where = ['1=1'];
$p = [];
if (in_array($role, ['admin', 'staff', 'agent'], true)) {
    $where[] = 'u.role = ?';
    $p[] = $role;
}
if ($q !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR u.zone LIKE ?)';
    $p = array_merge($p, array_fill(0, 4, '%' . $q . '%'));
}
$now = now();
$rows = Db::all('SELECT u.*, (SELECT COUNT(*) FROM subscribers s WHERE s.agent_id = u.id) AS subs, (SELECT COUNT(*) FROM subscribers s WHERE s.agent_id = u.id AND s.suspended = 0 AND s.expires_at >= ?) AS active_subs FROM users u WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(u.role,'admin','staff','agent'), u.full_name, u.username", array_merge([$now], $p));
$act = Auth::isAdmin() ? '<a class="btn primary" href="' . h(url('user', ['new' => 1])) . '">+ وكيل / مستخدم جديد</a>' : '';
layout_start('الوكلاء والمستخدمون', 'users', $act);
?>
<form class="filters card" method="get">
  <input type="hidden" name="p" value="users">
  <input name="q" value="<?= h($q) ?>" placeholder="بحث بالاسم أو الهاتف أو المنطقة">
  <select name="role" data-autosubmit><?= select_opts(['agent' => 'الوكلاء', 'staff' => 'الموظفون', 'admin' => 'المدراء'], $role, 'الكل') ?></select>
  <button class="btn">بحث</button>
</form>
<div class="card flush">
<table class="tbl">
  <thead><tr><th>الاسم</th><th>النوع</th><th>الهاتف</th><th>المشتركون (فعّال/الكل)</th><th>الرصيد</th><th>آخر دخول</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $u): ?>
    <tr class="rowlink<?= $u['active'] ? '' : ' cancelled' ?>" data-href="<?= h(url('user', ['id' => $u['id']])) ?>">
      <td><a href="<?= h(url('user', ['id' => $u['id']])) ?>"><?= h($u['full_name'] ?: $u['username']) ?></a><div class="muted small" dir="ltr"><?= h($u['username']) ?></div></td>
      <td><?= h(role_label($u['role'])) ?><?= $u['active'] ? '' : ' <span class="badge st-suspended">معطّل</span>' ?></td>
      <td dir="ltr"><?= h($u['phone']) ?></td>
      <td><?= $u['role'] === 'agent' ? number_format((int) $u['active_subs']) . ' / ' . number_format((int) $u['subs']) : '—' ?></td>
      <td><?= $u['role'] === 'agent' ? '<span class="' . ((int) $u['balance'] < 0 ? 'neg' : '') . '">' . money($u['balance']) . '</span>' : '—' ?></td>
      <td class="small"><?= h(fmt_dt($u['last_login_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php layout_end();
