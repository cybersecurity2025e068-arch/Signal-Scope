<?php
/** خطأ يُعرض للمستخدم كما هو (رسالة عربية واضحة) */
class UserError extends RuntimeException
{
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $page, array $params = []): string
{
    $q = array_merge(['p' => $page], $params);
    return 'index.php?' . http_build_query($q);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function back(string $fallback): void
{
    redirect($fallback);
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('انتهت صلاحية النموذج. ارجع وأعد المحاولة.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function in_str(string $k, string $default = ''): string
{
    $v = $_POST[$k] ?? $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function in_int(string $k, int $default = 0): int
{
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    if (is_string($v)) {
        $v = str_replace([',', '٬', ' '], '', $v);
        $v = strtr($v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }
    return is_numeric($v) ? (int) $v : $default;
}

function money($n): string
{
    return '<span class="nw">' . number_format((float) $n) . ' ' . h(Settings::get('currency')) . '</span>';
}

function fmt_dt(?string $dt, bool $time = true): string
{
    if (!$dt) {
        return '—';
    }
    // عزل اتجاه النص حتى لا ينقلب التاريخ والوقت داخل الصفحة العربية
    return "\u{2066}" . date($time ? 'Y-m-d H:i' : 'Y-m-d', strtotime($dt)) . "\u{2069}";
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function rand_code(int $len, string $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'): string
{
    $s = '';
    $n = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) {
        $s .= $alphabet[random_int(0, $n)];
    }
    return $s;
}

/** حالة الاشتراك محسوبة من تاريخ الانتهاء */
function sub_state(array $s): string
{
    if (!empty($s['suspended'])) {
        return 'suspended';
    }
    if (empty($s['expires_at'])) {
        return 'new';
    }
    $t = strtotime($s['expires_at']);
    if ($t < time()) {
        return 'expired';
    }
    if ($t < time() + 86400 * (int) Settings::get('expiry_warn_days')) {
        return 'expiring';
    }
    return 'active';
}

function state_label(string $st): string
{
    $m = ['active' => 'فعّال', 'expiring' => 'ينتهي قريباً', 'expired' => 'منتهي', 'suspended' => 'موقوف', 'new' => 'غير مفعّل'];
    return $m[$st] ?? $st;
}

function state_badge(string $st): string
{
    return '<span class="badge st-' . h($st) . '">' . h(state_label($st)) . '</span>';
}

function days_left(?string $exp): string
{
    if (!$exp) {
        return '—';
    }
    $d = (strtotime($exp) - time()) / 86400;
    if ($d < 0) {
        return 'منذ ' . (int) ceil(-$d) . ' يوم';
    }
    if ($d < 1) {
        return 'اليوم';
    }
    return (int) floor($d) . ' يوم';
}

function role_label(string $r): string
{
    $m = ['admin' => 'مدير', 'staff' => 'موظف', 'agent' => 'وكيل'];
    return $m[$r] ?? $r;
}

function tx_label(string $t): string
{
    $m = ['deposit' => 'إيداع', 'withdraw' => 'سحب', 'activation' => 'تفعيل', 'refund' => 'استرجاع', 'cards' => 'شراء كروت', 'adjust' => 'تسوية'];
    return $m[$t] ?? $t;
}

function method_label(string $m): string
{
    $x = ['balance' => 'رصيد', 'card' => 'كارت', 'admin' => 'إدارة', 'portal' => 'بوابة المشترك'];
    return $x[$m] ?? $m;
}

/** ترقيم الصفحات: يعيد [offset, limit, page] */
function page_args(int $per = 50): array
{
    $pg = max(1, in_int('pg', 1));
    return [($pg - 1) * $per, $per, $pg];
}

function pager(int $total, int $per, int $pg): string
{
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $q = $_GET;
    $out = '<nav class="pager">';
    $from = max(1, $pg - 3);
    $to = min($pages, $pg + 3);
    if ($from > 1) {
        $q['pg'] = 1;
        $out .= '<a href="?' . h(http_build_query($q)) . '">1</a><span>…</span>';
    }
    for ($i = $from; $i <= $to; $i++) {
        $q['pg'] = $i;
        $out .= $i === $pg ? '<b>' . $i . '</b>' : '<a href="?' . h(http_build_query($q)) . '">' . $i . '</a>';
    }
    if ($to < $pages) {
        $q['pg'] = $pages;
        $out .= '<span>…</span><a href="?' . h(http_build_query($q)) . '">' . $pages . '</a>';
    }
    return $out . '</nav>';
}

/** تنزيل CSV يفتح بالعربية في Excel */
function send_csv(string $name, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Ymd-His') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $header);
    foreach ($rows as $r) {
        fputcsv($f, array_values($r));
    }
    fclose($f);
    exit;
}

function select_opts(array $opts, $selected, string $empty = ''): string
{
    $o = $empty !== '' ? '<option value="">' . h($empty) . '</option>' : '';
    foreach ($opts as $v => $label) {
        $o .= '<option value="' . h($v) . '"' . ((string) $v === (string) $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $o;
}

function plan_options(bool $activeOnly = true): array
{
    $rows = Db::all('SELECT id, name, price FROM plans' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, price');
    $o = [];
    foreach ($rows as $r) {
        $o[$r['id']] = $r['name'] . ' — ' . number_format((float) $r['price']);
    }
    return $o;
}

function agent_options(): array
{
    $o = [];
    foreach (Db::all("SELECT id, username, full_name FROM users WHERE role = 'agent' ORDER BY full_name, username") as $r) {
        $o[$r['id']] = ($r['full_name'] ?: $r['username']) . ' (' . $r['username'] . ')';
    }
    return $o;
}
