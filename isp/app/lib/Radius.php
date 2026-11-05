<?php
/**
 * مزامنة اختيارية مع FreeRADIUS (rlm_sql): كل مشترك = صفوف في radcheck و radreply.
 * - Cleartext-Password        كلمة مرور PPPoE
 * - Expiration                تاريخ الانتهاء (FreeRADIUS يرفض الدخول بعده تلقائياً)
 * - Auth-Type := Reject       عند الإيقاف أو قبل أول تفعيل
 * - Simultaneous-Use          عدد الجلسات المسموح بها
 * - Mikrotik-Rate-Limit       السرعة من الباقة (رفع/تنزيل)
 * - Framed-IP-Address         عنوان ثابت إن وُجد
 */
final class Radius
{
    /** @var PDO|null */
    private static $pdo;

    public static function enabled(): bool
    {
        return (bool) config('radius.enabled', false);
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            $dsn = (string) config('radius.dsn', '');
            self::$pdo = $dsn === '' ? Db::pdo() : new PDO($dsn, (string) config('radius.user', ''), (string) config('radius.pass', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$pdo;
    }

    private static function q(string $sql, array $p = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public static function remove(string $username): void
    {
        if (!self::enabled() || $username === '') {
            return;
        }
        self::q('DELETE FROM radcheck WHERE username = ?', [$username]);
        self::q('DELETE FROM radreply WHERE username = ?', [$username]);
        self::q('DELETE FROM radusergroup WHERE username = ?', [$username]);
    }

    /** يكتب حالة المشترك الحالية؛ الأخطاء تُسجَّل ولا توقف اللوحة */
    public static function sync(int $subId, string $oldUsername = ''): string
    {
        if (!self::enabled()) {
            return '';
        }
        try {
            $s = Db::one('SELECT s.*, p.speed_down, p.speed_up, p.radius_group FROM subscribers s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$subId]);
            if ($oldUsername !== '' && (!$s || $oldUsername !== $s['username'])) {
                self::remove($oldUsername);
            }
            if (!$s) {
                return '';
            }
            $u = $s['username'];
            self::remove($u);
            $chk = [['Cleartext-Password', ':=', $s['password']]];
            if ($s['suspended'] || !$s['expires_at']) {
                $chk[] = ['Auth-Type', ':=', 'Reject'];
            }
            if ($s['expires_at']) {
                $chk[] = ['Expiration', ':=', date('d M Y H:i:s', strtotime($s['expires_at']))];
            }
            $sim = (int) config('radius.simultaneous_use', 1);
            if ($sim > 0) {
                $chk[] = ['Simultaneous-Use', ':=', (string) $sim];
            }
            if ($s['mac'] !== '' && config('radius.bind_mac', false)) {
                $chk[] = ['Calling-Station-Id', '==', strtoupper($s['mac'])];
            }
            foreach ($chk as $r) {
                self::q('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ?, ?)', [$u, $r[0], $r[1], $r[2]]);
            }
            $rep = [];
            if ((int) $s['speed_down'] > 0 || (int) $s['speed_up'] > 0) {
                $rep[] = [(string) config('radius.rate_attr', 'Mikrotik-Rate-Limit'), (int) $s['speed_up'] . 'M/' . (int) $s['speed_down'] . 'M'];
            }
            if ($s['static_ip'] !== '') {
                $rep[] = ['Framed-IP-Address', $s['static_ip']];
            }
            foreach ($rep as $r) {
                self::q('INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ?, ?)', [$u, $r[0], '=', $r[1]]);
            }
            if (!empty($s['radius_group'])) {
                self::q('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)', [$u, $s['radius_group']]);
            }
            return '';
        } catch (Throwable $e) {
            error_log('radius sync: ' . $e->getMessage());
            return 'تعذّرت المزامنة مع RADIUS: ' . $e->getMessage();
        }
    }

    /** الجلسات المتصلة الآن */
    public static function online(string $search = '', ?array $onlyUsers = null): array
    {
        if (!self::enabled()) {
            return [];
        }
        $sql = 'SELECT username, nasipaddress, framedipaddress, callingstationid, acctstarttime, acctinputoctets, acctoutputoctets FROM radacct WHERE acctstoptime IS NULL';
        $p = [];
        if ($search !== '') {
            $sql .= ' AND (username LIKE ? OR framedipaddress LIKE ? OR callingstationid LIKE ?)';
            $p = array_fill(0, 3, '%' . $search . '%');
        }
        $sql .= ' ORDER BY acctstarttime DESC LIMIT 2000';
        $rows = self::q($sql, $p)->fetchAll();
        if ($onlyUsers !== null) {
            $set = array_flip($onlyUsers);
            $rows = array_values(array_filter($rows, function ($r) use ($set) {
                return isset($set[$r['username']]);
            }));
        }
        return $rows;
    }

    /** آخر الجلسات واستهلاك المشترك منذ تاريخ */
    public static function sessions(string $username, int $limit = 20): array
    {
        if (!self::enabled()) {
            return [];
        }
        return self::q('SELECT acctstarttime, acctstoptime, framedipaddress, callingstationid, acctinputoctets, acctoutputoctets, acctterminatecause FROM radacct WHERE username = ? ORDER BY radacctid DESC LIMIT ' . (int) $limit, [$username])->fetchAll();
    }

    public static function usage(string $username, string $since): array
    {
        if (!self::enabled()) {
            return [0, 0];
        }
        $r = self::q('SELECT COALESCE(SUM(acctinputoctets),0) AS up, COALESCE(SUM(acctoutputoctets),0) AS down FROM radacct WHERE username = ? AND acctstarttime >= ?', [$username, $since])->fetch();
        return [(int) $r['up'], (int) $r['down']];
    }

    public static function bytes($n): string
    {
        $n = (float) $n;
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($n >= 1024 && $i < 4) {
            $n /= 1024;
            $i++;
        }
        return "\u{2066}" . round($n, $i > 2 ? 2 : 0) . ' ' . $u[$i] . "\u{2069}";
    }
}
