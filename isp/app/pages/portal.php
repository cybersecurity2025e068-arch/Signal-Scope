<?php
// بوابة المشترك: يدخل باسم مستخدم وكلمة مرور PPPoE، يرى اشتراكه ويشحن بكارت
if (Settings::get('portal_enabled') !== '1') {
    throw new UserError('بوابة المشترك غير متاحة.');
}
$err = '';
$ok = '';
$sid = (int) ($_SESSION['portal_sid'] ?? 0);
if (is_post()) {
    csrf_check();
    $a = in_str('action');
    if ($a === 'login') {
        if (Auth::throttled('portal')) {
            $err = 'محاولات كثيرة. انتظر ربع ساعة.';
        } else {
            $u = in_str('username');
            $s = Db::one('SELECT id, username, password FROM subscribers WHERE username = ?', [$u]);
            if ($s && hash_equals($s['password'], (string) ($_POST['password'] ?? ''))) {
                session_regenerate_id(true);
                $_SESSION['portal_sid'] = (int) $s['id'];
                $_SESSION['portal_user'] = $s['username'];
                redirect(url('portal'));
            }
            Auth::failed('portal', $u);
            $err = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
        }
    } elseif ($a === 'logout') {
        unset($_SESSION['portal_sid'], $_SESSION['portal_user']);
        redirect(url('portal'));
    } elseif ($a === 'card' && $sid) {
        if (Auth::throttled('portal')) {
            $err = 'محاولات كثيرة. انتظر ربع ساعة.';
        } else {
            try {
                $r = Cards::redeem(in_str('pin'), $sid, true);
                $ok = 'تم الشحن بنجاح. اشتراكك فعّال حتى ' . fmt_dt($r['end']);
            } catch (UserError $e) {
                Auth::failed('portal', 'card:' . ($_SESSION['portal_user'] ?? ''));
                $err = $e->getMessage();
            }
        }
    }
}
$s = $sid ? Db::one('SELECT s.*, p.name AS plan, p.speed_down, p.speed_up, p.duration_days FROM subscribers s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$sid]) : null;
layout_head('اشتراكي');
$co = Settings::get('company_name');
?>
<body class="auth">
<div class="auth-card wide">
  <img class="logo" src="assets/icon.svg" alt="">
  <h1><?= h($co) ?></h1>
  <?php if (Settings::get('portal_notice')): ?><div class="alert info"><?= nl2br(h(Settings::get('portal_notice'))) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert error"><?= h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert ok"><?= h($ok) ?></div><?php endif; ?>
  <?php if (!$s): ?>
  <p class="muted">ادخل باسم المستخدم وكلمة المرور الخاصة باشتراكك</p>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="login">
    <label>اسم المستخدم<input name="username" dir="ltr" required autocomplete="username"></label>
    <label>كلمة المرور<input type="password" name="password" dir="ltr" required autocomplete="current-password"></label>
    <button class="btn primary block">دخول</button>
  </form>
  <?php else: $st = sub_state($s); ?>
  <div class="exp-box big <?= h($st) ?>">
    <span><?= h(state_label($st)) ?></span><b><?= h(fmt_dt($s['expires_at'])) ?></b><small><?= $s['expires_at'] ? 'المتبقي: ' . h(days_left($s['expires_at'])) : 'لم يُفعَّل بعد' ?></small>
  </div>
  <dl class="kv">
    <dt>المشترك</dt><dd><?= h($s['full_name'] ?: $s['username']) ?></dd>
    <dt>اسم المستخدم</dt><dd dir="ltr"><?= h($s['username']) ?></dd>
    <dt>الباقة</dt><dd><?= h($s['plan'] ?? '—') ?><?= $s['plan'] ? ' · ' . (int) $s['speed_down'] . ' Mbps' : '' ?></dd>
  </dl>
  <?php if ($s['suspended']): ?><div class="alert warn">اشتراكك موقوف. راجع الوكيل أو الدعم الفني.</div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="card">
    <label>شحن بكارت: أدخل الرقم السري<input name="pin" dir="ltr" inputmode="numeric" required autocomplete="off"></label>
    <button class="btn primary block" data-confirm="شحن الكارت؟ ستتغيّر باقتك إلى باقة الكارت.">شحن</button>
  </form>
  <?php
  $hist = Db::all('SELECT a.created_at, a.ends_at, p.name FROM activations a JOIN plans p ON p.id = a.plan_id WHERE a.subscriber_id = ? AND a.cancelled = 0 ORDER BY a.id DESC LIMIT 5', [$sid]);
  if ($hist): ?>
  <h2>آخر التجديدات</h2>
  <table class="tbl"><tbody><?php foreach ($hist as $h) { echo '<tr><td>' . h($h['name']) . '</td><td class="small">' . h(fmt_dt($h['created_at'], false)) . '</td><td class="small">حتى ' . h(fmt_dt($h['ends_at'], false)) . '</td></tr>'; } ?></tbody></table>
  <?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="linkbtn">خروج</button></form>
  <?php endif; ?>
  <?php if (Settings::get('support_phone')): ?><p class="center small muted">الدعم الفني: <span dir="ltr"><?= h(Settings::get('support_phone')) ?></span></p><?php endif; ?>
</div>
</body></html>
