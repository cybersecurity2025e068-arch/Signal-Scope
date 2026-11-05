<?php
function nav_items(): array
{
    $staff = Auth::isStaff();
    $admin = Auth::isAdmin();
    $items = [
        ['dashboard', 'الرئيسية', 'home'],
        ['subscribers', 'المشتركون', 'users'],
        ['redeem', 'تفعيل بكارت', 'ticket'],
        ['cards', 'الكروت', 'cards'],
        ['transactions', $staff ? 'الحركات المالية' : 'كشف الحساب', 'wallet'],
        ['reports', 'التقارير', 'chart'],
    ];
    if (Radius::enabled()) {
        $items[] = ['online', 'المتصلون الآن', 'wifi'];
    }
    if ($staff) {
        $items[] = ['plans', 'الباقات', 'layers'];
        $items[] = ['users', 'الوكلاء والمستخدمون', 'shield'];
    }
    if ($admin) {
        $items[] = ['audit', 'سجل العمليات', 'list'];
        $items[] = ['settings', 'الإعدادات', 'gear'];
    }
    return $items;
}

function icon(string $n): string
{
    $p = [
        'home' => 'M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z',
        'users' => 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M22 19v-1a4 4 0 0 0-3-3.9M16 3.1a3.5 3.5 0 0 1 0 6.8',
        'ticket' => 'M3 8a2 2 0 0 0 0 4v4a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-4a2 2 0 0 1 0-4V4a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1zM14 3v14',
        'cards' => 'M2 6h20v12H2zM2 10h20M6 15h4',
        'wallet' => 'M20 7H5a2 2 0 0 1 0-4h13v4M3 5v14a2 2 0 0 0 2 2h15V7M16 14h.01',
        'chart' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'wifi' => 'M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M2 9a15 15 0 0 1 20 0M12 19.5h.01',
        'layers' => 'M12 2l10 5-10 5L2 7zM2 17l10 5 10-5M2 12l10 5 10-5',
        'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10',
        'list' => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
        'gear' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
        'user' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
        'menu' => 'M3 6h18M3 12h18M3 18h18',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="' . ($p[$n] ?? '') . '"/></svg>';
}

function layout_head(string $title): void
{
    $co = Settings::get('company_name');
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . h($title . ' · ' . $co) . '</title>'
        . '<link rel="icon" href="assets/icon.svg"><link rel="stylesheet" href="assets/app.css?v=' . APP_VERSION . '">'
        . '<script src="assets/app.js?v=' . APP_VERSION . '" defer></script></head>';
}

function layout_start(string $title, string $active, string $actions = ''): void
{
    $me = Auth::user();
    layout_head($title);
    echo '<body class="app">';
    if ($me) {
        echo '<aside class="side" id="side"><div class="brand"><img src="assets/icon.svg" alt=""><div><b>' . h(Settings::get('company_name')) . '</b><small>لوحة إدارة المشتركين</small></div></div><nav>';
        foreach (nav_items() as $it) {
            echo '<a href="' . h(url($it[0])) . '"' . ($active === $it[0] ? ' class="on"' : '') . '>' . icon($it[2]) . '<span>' . h($it[1]) . '</span></a>';
        }
        echo '</nav><div class="side-foot">v' . APP_VERSION . '</div></aside><div class="scrim" data-close-side></div>';
    }
    echo '<div class="main"><header class="top">';
    if ($me) {
        echo '<button class="iconbtn only-m" data-toggle-side aria-label="القائمة">' . icon('menu') . '</button>';
    }
    echo '<h1>' . h($title) . '</h1><div class="grow"></div>';
    if ($me) {
        if ($me['role'] === 'agent') {
            echo '<a class="chip bal" href="' . h(url('transactions')) . '" title="رصيدك">' . icon('wallet') . money($me['balance']) . '</a>';
        }
        echo '<details class="menu"><summary class="chip">' . icon('user') . '<span class="hide-s">' . h($me['full_name'] ?: $me['username']) . '</span></summary><div class="menu-pop">'
            . '<div class="muted small">' . h($me['username']) . ' · ' . h(role_label($me['role'])) . '</div>'
            . '<a href="' . h(url('profile')) . '">الملف الشخصي وكلمة المرور</a>'
            . '<form method="post" action="' . h(url('logout')) . '">' . csrf_field() . '<button class="linkbtn">تسجيل الخروج</button></form></div></details>';
    }
    echo '</header><main class="content">';
    if ($actions !== '') {
        echo '<div class="page-actions">' . $actions . '</div>';
    }
    foreach (take_flash() as $f) {
        echo '<div class="alert ' . h($f[0]) . '">' . h($f[1]) . '</div>';
    }
}

function layout_end(): void
{
    echo '</main></div></body></html>';
}

function stat_tile(string $label, string $value, string $cls = '', string $href = ''): string
{
    $tag = $href ? 'a href="' . h($href) . '"' : 'div';
    $end = $href ? 'a' : 'div';
    return '<' . $tag . ' class="tile ' . h($cls) . '"><span>' . h($label) . '</span><b>' . $value . '</b></' . $end . '>';
}
