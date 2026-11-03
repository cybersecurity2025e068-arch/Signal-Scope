<?php
declare(strict_types=1);

final class Auth
{
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $t): string
    {
        return hash('sha256', $t);
    }

    /** الجهاز صاحب الرمز، أو خطأ 401 */
    public static function device(): array
    {
        $t = Http::bearer();
        if (!$t) throw new HttpError(401, 'رمز الجهاز مفقود', 'no_token');
        $st = ss_db()->prepare('SELECT * FROM devices WHERE token_hash = ?');
        $st->execute([self::hashToken($t)]);
        $d = $st->fetch();
        if (!$d) throw new HttpError(401, 'رمز الجهاز غير معروف', 'bad_token');
        if ((int)$d['revoked'] === 1) throw new HttpError(401, 'تم إلغاء هذا الجهاز', 'revoked');
        return $d;
    }

    // ---- جلسات المدير (لوحة التحكم) ----

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('SSADMIN');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function admin(): array
    {
        self::startSession();
        if (empty($_SESSION['admin_id'])) throw new HttpError(401, 'يلزم تسجيل الدخول', 'login_required');
        if (time() - ($_SESSION['t'] ?? 0) > 12 * 3600) {
            session_destroy();
            throw new HttpError(401, 'انتهت الجلسة', 'login_required');
        }
        // حماية CSRF: الطلبات المعدّلة يجب أن تحمل ترويسة لا ترسلها النماذج العادية
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && ($_SERVER['HTTP_X_SS'] ?? '') !== '1') {
            throw new HttpError(403, 'طلب مرفوض', 'csrf');
        }
        return ['id' => (int)$_SESSION['admin_id'], 'username' => $_SESSION['admin_user']];
    }

    public static function login(string $user, string $pass): array
    {
        $db = ss_db();
        $ip = Http::ip();
        $since = time() - 900;
        $st = $db->prepare('SELECT COUNT(*) FROM login_fail WHERE ip = ? AND ts > ?');
        $st->execute([$ip, $since]);
        if ((int)$st->fetchColumn() >= 10) throw new HttpError(429, 'محاولات كثيرة. حاول بعد 15 دقيقة', 'rate_limited');

        $st = $db->prepare('SELECT * FROM admins WHERE username = ?');
        $st->execute([$user]);
        $a = $st->fetch();
        if (!$a || !password_verify($pass, $a['pass_hash'])) {
            $db->prepare('INSERT INTO login_fail (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
            usleep(500_000);
            throw new HttpError(401, 'اسم المستخدم أو كلمة المرور غير صحيحة', 'bad_login');
        }
        if (password_needs_rehash($a['pass_hash'], PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')
               ->execute([password_hash($pass, PASSWORD_DEFAULT), $a['id']]);
        }
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$a['id'];
        $_SESSION['admin_user'] = $a['username'];
        $_SESSION['t'] = time();
        return ['id' => (int)$a['id'], 'username' => $a['username']];
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }
}
