<?php
if (Auth::user()) {
    redirect(url('dashboard'));
}
$err = '';
$uname = '';
if (is_post()) {
    csrf_check();
    $uname = in_str('username');
    $err = Auth::attempt($uname, (string) ($_POST['password'] ?? ''));
    if ($err === '') {
        redirect(url('dashboard'));
    }
}
layout_head('تسجيل الدخول');
?>
<body class="auth">
<form class="auth-card" method="post" action="<?= h(url('login')) ?>">
  <?= csrf_field() ?>
  <img class="logo" src="assets/icon.svg" alt="">
  <h1><?= h(Settings::get('company_name')) ?></h1>
  <p class="muted">لوحة إدارة المشتركين والوكلاء</p>
  <?php if ($err): ?><div class="alert error"><?= h($err) ?></div><?php endif; ?>
  <label>اسم المستخدم<input name="username" value="<?= h($uname) ?>" autocomplete="username" dir="ltr" required autofocus></label>
  <label>كلمة المرور<input type="password" name="password" autocomplete="current-password" dir="ltr" required></label>
  <button class="btn primary block">دخول</button>
  <?php if (Settings::get('portal_enabled') === '1'): ?>
  <p class="center small"><a href="<?= h(url('portal')) ?>">أنت مشترك؟ ادخل إلى صفحة اشتراكك</a></p>
  <?php endif; ?>
</form>
</body></html>
