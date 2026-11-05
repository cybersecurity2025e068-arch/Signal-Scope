<?php
final class Auth
{
    const MAX_ATTEMPTS = 8;      // محاولات فاشلة لكل IP
    const WINDOW_MIN = 15;       // خلال هذه الدقائق

    /** @var array|null|false */
    private static $user = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('ISPPANEL');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_divisor', '100');
        session_start();
        // انتهاء الجلسة بعد 8 ساعات من عدم النشاط
        if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 8 * 3600) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = time();
    }

    public static function throttled(string $scope): bool
    {
        $n = (int) Db::val(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND scope = ? AND created_at > ?',
            [client_ip(), $scope, date('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60)]
        );
        return $n >= self::MAX_ATTEMPTS;
    }

    public static function failed(string $scope, string $username): void
    {
        Db::insert('login_attempts', ['ip' => client_ip(), 'username' => mb_substr($username, 0, 64), 'scope' => $scope, 'created_at' => now()]);
    }

    public static function attempt(string $username, string $password): string
    {
        if (self::throttled('panel')) {
            return 'محاولات كثيرة. انتظر ' . self::WINDOW_MIN . ' دقيقة ثم أعد المحاولة.';
        }
        $u = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$u || !password_verify($password, $u['pass_hash'])) {
            self::failed('panel', $username);
            Audit::log('login_fail', 'user', $u ? (int) $u['id'] : null, $username);
            return 'اسم المستخدم أو كلمة المرور غير صحيحة.';
        }
        if (!$u['active']) {
            return 'هذا الحساب معطّل. راجع الإدارة.';
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['pv'] = substr($u['pass_hash'], -12); // تغيير كلمة المرور يُخرج الجلسات الأخرى
        if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['pass_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
            $_SESSION['pv'] = substr((string) Db::val('SELECT pass_hash FROM users WHERE id = ?', [$u['id']]), -12);
        }
        Db::update('users', ['last_login_at' => now(), 'last_login_ip' => client_ip()], 'id = ?', [$u['id']]);
        self::$user = false;
        Audit::log('login', 'user', (int) $u['id']);
        return '';
    }

    public static function user(): ?array
    {
        if (self::$user === false) {
            self::$user = null;
            if (!empty($_SESSION['uid'])) {
                $u = Db::one('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);
                if ($u && $u['active'] && substr($u['pass_hash'], -12) === ($_SESSION['pv'] ?? '')) {
                    self::$user = $u;
                }
            }
        }
        return self::$user;
    }

    public static function refresh(): void
    {
        self::$user = false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = false;
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) {
            redirect(url('login'));
        }
        return $u;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u && $u['role'] === 'admin';
    }

    /** المدير والموظف يديران الكل؛ الوكيل يرى مشتركيه فقط */
    public static function isStaff(): bool
    {
        $u = self::user();
        return $u && in_array($u['role'], ['admin', 'staff'], true);
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            http_response_code(403);
            throw new UserError('هذه الصفحة للمدير فقط.');
        }
    }

    public static function requireStaff(): void
    {
        if (!self::isStaff()) {
            http_response_code(403);
            throw new UserError('هذه الصفحة للإدارة فقط.');
        }
    }

    public static function validPassword(string $p): string
    {
        if (mb_strlen($p) < 8) {
            return 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
        }
        return '';
    }
}
