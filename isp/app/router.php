<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self'; form-action 'self'");

Installer::ensure();
Auth::start();
require APP_DIR . '/lib/layout.php';

$PAGES = [
    'dashboard' => 'dashboard', 'subscribers' => 'subscribers', 'subscriber' => 'subscriber',
    'subscriber_edit' => 'subscriber_edit', 'plans' => 'plans', 'users' => 'users', 'user' => 'user',
    'transactions' => 'transactions', 'cards' => 'cards', 'batch' => 'batch', 'reports' => 'reports',
    'online' => 'online', 'audit' => 'audit', 'settings' => 'settings', 'profile' => 'profile',
    'redeem' => 'redeem',
];
$PUBLIC = ['login' => 'login', 'portal' => 'portal'];

$PAGE = $_GET['p'] ?? 'dashboard';
$PAGE = is_string($PAGE) ? $PAGE : 'dashboard';

if ($PAGE === 'logout') {
    if (is_post()) {
        csrf_check();
        Audit::log('logout');
        Auth::logout();
    }
    redirect(url('login'));
}

try {
    if (isset($PUBLIC[$PAGE])) {
        require APP_DIR . '/pages/' . $PUBLIC[$PAGE] . '.php';
        exit;
    }
    if (!isset($PAGES[$PAGE])) {
        $PAGE = 'dashboard';
    }
    $ME = Auth::require();
    if (is_post()) {
        csrf_check();
    }
    require APP_DIR . '/pages/' . $PAGES[$PAGE] . '.php';
} catch (UserError $e) {
    if (is_post()) {
        flash('error', $e->getMessage());
        // كل النماذج تُرسل إلى صفحتها نفسها، فنعود إليها (بنفس المعاملات مثل id)
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $q = (string) parse_url($uri, PHP_URL_QUERY);
        redirect('index.php' . ($q !== '' ? '?' . $q : ''));
    }
    layout_start('خطأ', '');
    echo '<div class="alert error">' . h($e->getMessage()) . '</div><p><a class="btn" href="' . h(url('dashboard')) . '">الرئيسية</a></p>';
    layout_end();
} catch (Throwable $e) {
    error_log('isppanel: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    layout_start('خطأ', '');
    echo '<div class="alert error">حدث خطأ غير متوقع. سُجّل في سجل الأخطاء.</div>';
    layout_end();
}
