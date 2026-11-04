<?php
declare(strict_types=1);

/**
 * مركز إدارة البوابة: دخول واحد للمدير ونائبه والمشرفين يشمل بوابة القسم (/amu/) ومنظومة راصد الإشارة.
 *
 * الأدوار:
 *   owner      المدير: تحكم كامل بكل شيء، ومنه نقل الإدارة إلى غيره.
 *   deputy     النائب: كل صلاحيات المدير عدا حسابات المدير والنواب (لا يعدّلها ولا يحذفها ولا ينشئها).
 *   supervisor مشرف قسم: الأخبار والإعلانات لأقسامه فقط، والاطلاع على الإحصاءات.
 * الهوية من حساب البوابة نفسه (Firebase: Google أو اسم مستخدم الأستاذ)، ويُتحقق من الرمز على الخادم.
 * كل تعديل يُسجَّل في سجل العمليات.
 */
final class Portal
{
    public const ROLES = ['owner' => 'المدير', 'deputy' => 'نائب المدير', 'supervisor' => 'مشرف قسم'];
    public const STATUSES = ['visible' => 'ظاهر', 'locked' => 'يتطلب الدخول', 'maintenance' => 'صيانة', 'hidden' => 'مخفي'];
    public const STAFF_DOMAIN = 'pw.almaaqal.app';

    /** التفرعات الافتراضية (تُضاف مرة واحدة ويمكن تعديلها لاحقاً). match يُطابَق مع «النطاق + المسار» */
    public const DEFAULT_SECTIONS = [
        ['amu-home', 'amu', 'الصفحة الرئيسية لبوابة القسم', '/amu/', '^[^/]+/amu/(index\\.html)?$', ''],
        ['amu-news', 'amu', 'الأخبار', '/amu/#news', '', '#news'],
        ['amu-dl', 'amu', 'التطبيقات والتنزيلات', '/amu/#dl', '', '#dl'],
        ['amu-exams', 'amu', 'الامتحانات', '/amu/exam/', '^[^/]+/amu/exam/', '#ex'],
        ['amu-library', 'amu', 'المكتبة الإلكترونية', '/amu/library/', '^[^/]+/amu/library/', '#lib'],
        ['amu-lectures', 'amu', 'المحاضرات', '/amu/lectures/', '^[^/]+/amu/lectures/', ''],
        ['amu-kontrol', 'amu', 'نظام الكنترول', 'https://drdshsw2022-byte.github.io/almaaqal-kontrol/kontrol/', 'almaaqal-kontrol/kontrol/', ''],
        ['amu-attend', 'amu', 'تطبيق الحضور', 'https://drdshsw2022-byte.github.io/almaaqal/', 'github\\.io/almaaqal/', ''],
        ['raasid-portal', 'raasid', 'بوابة منظومة راصد الإشارة', '/raasid/', '^[^/]+/raasid/(index\\.html)?$', ''],
        ['raasid-dash', 'raasid', 'لوحة الراصد', '/dashboard/', '^[^/]+/dashboard/', ''],
        ['raasid-app', 'raasid', 'راصد الإشارة', '/app/', '^[^/]+/app/(index\\.html)?$', ''],
        ['raasid-ripple', 'raasid', 'حوض الأمواج', '/app/ripple.html', '^[^/]+/app/ripple\\.html', ''],
        ['raasid-lab', 'raasid', 'مختبر القناة اللاسلكية', '/app/lab.html', '^[^/]+/app/lab\\.html', ''],
    ];

    public static function norm(string $e): string { return strtolower(trim($e)); }

    // ------------------------------------------------------------------ التهيئة

    /** يضيف المدير ونائبه والتفرعات الافتراضية عند أول استخدام */
    public static function seed(): void
    {
        $db = ss_db();
        if (!(int)$db->query('SELECT COUNT(*) FROM portal_users')->fetchColumn()) {
            $c = ss_config();
            $ins = $db->prepare("INSERT IGNORE INTO portal_users (email, role, note, added_by) VALUES (?, ?, ?, 'system')");
            $own = self::norm((string)($c['portal_owner'] ?? 'drdsh.sw2022@gmail.com'));
            $dep = self::norm((string)($c['portal_deputy'] ?? 'it.basra@gmail.com'));
            if ($own) $ins->execute([$own, 'owner', 'المدير الرئيسي (من إعدادات البوابة)']);
            if ($dep && $dep !== $own) $ins->execute([$dep, 'deputy', 'المدير الاحتياطي (من إعدادات البوابة)']);
        }
        $ins = $db->prepare("INSERT IGNORE INTO portal_sections (id, system, name, url, match_re, selector, status, sort, builtin) VALUES (?, ?, ?, ?, ?, ?, 'visible', ?, 1)");
        foreach (self::DEFAULT_SECTIONS as $i => $s) $ins->execute([$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], ($i + 1) * 10]);
    }

    // ------------------------------------------------------------------ الجلسة

    /** دخول برمز Firebase: يتحقق من الرمز ثم من دور الحساب */
    public static function login(string $idToken): array
    {
        $db = ss_db();
        $st = $db->prepare('SELECT COUNT(*) FROM login_fail WHERE ip = ? AND ts > ?');
        $st->execute([Http::ip(), time() - 900]);
        if ((int)$st->fetchColumn() >= 20) throw new HttpError(429, 'محاولات كثيرة. حاول بعد 15 دقيقة', 'rate_limited');
        self::seed();
        $c = FirebaseToken::verify($idToken);
        $email = self::norm((string)($c['email'] ?? ''));
        $prov = (string)($c['firebase']['sign_in_provider'] ?? '');
        $staff = substr($email, -strlen('@' . self::STAFF_DOMAIN)) === '@' . self::STAFF_DOMAIN;
        // حسابات Google يجب أن يكون بريدها موثَّقاً؛ وحسابات كلمة المرور مقبولة لأسماء الأساتذة فقط
        if ($email === '' || ($prov === 'password' ? !$staff : empty($c['email_verified']))) throw new HttpError(403, 'بريد الحساب غير موثَّق', 'unverified');
        $u = self::user($email);
        $fail = function (string $m, string $code) use ($db) {
            $db->prepare('INSERT INTO login_fail (ip, ts) VALUES (?, ?)')->execute([Http::ip(), time()]);
            throw new HttpError(403, $m, $code);
        };
        if (self::isBlocked($email)) $fail('هذا الحساب محظور من البوابة', 'blocked');
        if (!$u || !(int)$u['active']) $fail('حسابك (' . $email . ') ليس من مدراء البوابة. اطلب من المدير إضافتك.', 'not_admin');
        if ($staff && $u['role'] !== 'supervisor') $fail('حسابات الأساتذة تكون بدور مشرف فقط', 'not_admin');
        Auth::startSession();
        session_regenerate_id(true);
        $_SESSION['portal'] = ['id' => (int)$u['id'], 'email' => $email, 'role' => $u['role'], 'name' => (string)($c['name'] ?? '')];
        $_SESSION['t'] = time();
        // المدير ونائبه يملكان لوحة الراصد أيضاً بالجلسة نفسها
        if (in_array($u['role'], ['owner', 'deputy'], true)) { $_SESSION['admin_id'] = -(int)$u['id']; $_SESSION['admin_user'] = $email; }
        $db->prepare('UPDATE portal_users SET last_login = UTC_TIMESTAMP(), name = COALESCE(NULLIF(?, \'\'), name) WHERE id = ?')->execute([(string)($c['name'] ?? ''), $u['id']]);
        self::audit('login', $email, $prov === 'password' ? 'اسم مستخدم' : 'Google');
        return self::me(true);
    }

    /** المستخدم الحالي، أو خطأ 401. للطلبات المعدِّلة يلزم ترويسة X-SS (حماية CSRF) */
    public static function me(bool $skipCsrf = false): array
    {
        Auth::startSession();
        $p = $_SESSION['portal'] ?? null;
        if (!$p && !empty($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
            // مدير لوحة الراصد بكلمة المرور: بديل احتياطي بصلاحيات المدير إن تعطّل دخول Google
            $p = ['id' => 0, 'email' => 'admin:' . $_SESSION['admin_user'], 'role' => 'owner', 'name' => (string)$_SESSION['admin_user'], 'local' => true];
        }
        if (!$p) throw new HttpError(401, 'يلزم تسجيل الدخول', 'login_required');
        if (time() - ($_SESSION['t'] ?? 0) > 12 * 3600) { session_destroy(); throw new HttpError(401, 'انتهت الجلسة', 'login_required'); }
        if (!$skipCsrf && $_SERVER['REQUEST_METHOD'] !== 'GET' && ($_SERVER['HTTP_X_SS'] ?? '') !== '1') throw new HttpError(403, 'طلب مرفوض', 'csrf');
        if (empty($p['local'])) {
            // الدور يُقرأ من قاعدة البيانات في كل طلب: سحب الصلاحية يسري فوراً
            $u = self::user($p['email']);
            if (!$u || !(int)$u['active'] || self::isBlocked($p['email'])) { self::logout(); throw new HttpError(401, 'سُحبت صلاحية هذا الحساب', 'login_required'); }
            $p['role'] = $u['role']; $p['id'] = (int)$u['id'];
            $p['depts'] = $u['depts'] ? (json_decode($u['depts'], true) ?: []) : null;
        } else $p['depts'] = null;
        $p['role_ar'] = self::ROLES[$p['role']] ?? $p['role'];
        $p['can'] = self::caps($p['role']);
        return $p;
    }

    public static function logout(): void
    {
        Auth::startSession();
        $_SESSION = [];
        session_destroy();
    }

    /** @return array<string,bool> */
    public static function caps(string $role): array
    {
        $top = in_array($role, ['owner', 'deputy'], true);
        return ['users' => $top, 'admins' => $role === 'owner', 'sections' => $top, 'blocked' => $top, 'news' => true,
                'news_all' => $top, 'stats' => true, 'audit' => $top, 'dashboard' => $top, 'colleges' => $top];
    }

    private static function need(array $me, string $cap): void
    {
        if (empty($me['can'][$cap])) throw new HttpError(403, 'ليست لديك صلاحية لهذا الإجراء', 'forbidden');
    }

    public static function audit(string $action, string $who, string $detail = '', string $target = ''): void
    {
        try {
            ss_db()->prepare('INSERT INTO portal_audit (at, email, action, target, detail, ip) VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?)')
                ->execute([mb_substr($who, 0, 120), $action, mb_substr($target, 0, 120), mb_substr($detail, 0, 500), Http::ip()]);
        } catch (Throwable $e) { error_log('[portal audit] ' . $e->getMessage()); }
    }

    private static function user(string $email): ?array
    {
        $st = ss_db()->prepare('SELECT * FROM portal_users WHERE email = ?');
        $st->execute([self::norm($email)]);
        $u = $st->fetch();
        return $u ?: null;
    }

    private static function isBlocked(string $email): bool
    {
        $st = ss_db()->prepare('SELECT 1 FROM portal_blocked WHERE email = ?');
        $st->execute([self::norm($email)]);
        return (bool)$st->fetchColumn();
    }

    private static function cleanDepts($d): ?array
    {
        if ($d === null || $d === '' || $d === 'all') return null;
        $out = [];
        foreach ((array)$d as $x) { $x = (string)$x; if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $x)) $out[] = $x; }
        return $out ? array_values(array_unique($out)) : null;   // قائمة فارغة = كل الأقسام
    }

    private static function validEmail(string $e): string
    {
        $e = self::norm($e);
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 120) throw new HttpError(400, 'بريد غير صالح: ' . $e, 'bad_email');
        return $e;
    }

    // ------------------------------------------------------------------ الحسابات

    public static function users(array $me): array
    {
        $rows = ss_db()->query("SELECT id, email, name, role, depts, note, active, added_by, created_at, last_login FROM portal_users
            ORDER BY FIELD(role, 'owner', 'deputy', 'supervisor'), email")->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['active'] = (bool)$r['active']; $r['depts'] = $r['depts'] ? json_decode($r['depts'], true) : null; $r['role_ar'] = self::ROLES[$r['role']] ?? $r['role']; }
        unset($r);
        if (!$me['can']['users']) $rows = array_values(array_filter($rows, function ($r) use ($me) { return $r['email'] === $me['email']; }));
        $blocked = $me['can']['blocked'] ? ss_db()->query('SELECT email, reason, added_by, created_at FROM portal_blocked ORDER BY created_at DESC')->fetchAll() : [];
        return ['users' => $rows, 'blocked' => $blocked];
    }

    public static function saveUser(array $me, array $b): array
    {
        self::need($me, 'users');
        $db = ss_db();
        $email = self::validEmail((string)($b['email'] ?? ''));
        $role = (string)($b['role'] ?? 'supervisor');
        if (!isset(self::ROLES[$role])) throw new HttpError(400, 'دور غير معروف', 'bad_role');
        $old = self::user($email);
        $isTop = function (?string $r) { return in_array($r, ['owner', 'deputy'], true); };
        // النائب: لا يمس حسابات المدير والنواب، ولا ينشئها
        if ($me['role'] !== 'owner' && ($isTop($role) || ($old && $isTop($old['role'])))) throw new HttpError(403, 'حسابات المدير ونوابه يديرها المدير وحده', 'forbidden');
        if ($old && $old['email'] === self::norm($me['email']) && $role !== $old['role'] && $role !== 'owner') throw new HttpError(400, 'لا يمكنك تغيير دورك بنفسك. انقل الإدارة إلى غيرك أولاً', 'self_role');
        if (substr($email, -strlen(self::STAFF_DOMAIN) - 1) === '@' . self::STAFF_DOMAIN && $role !== 'supervisor') throw new HttpError(400, 'حسابات الأساتذة تكون بدور مشرف فقط', 'bad_role');
        if (self::isBlocked($email)) throw new HttpError(400, 'هذا البريد محظور. أزل الحظر أولاً', 'blocked');
        $depts = $role === 'supervisor' ? self::cleanDepts($b['depts'] ?? null) : null;
        if ($role === 'supervisor' && !$depts) throw new HttpError(400, 'حدد قسماً واحداً على الأقل للمشرف', 'no_depts');
        $note = mb_substr(trim((string)($b['note'] ?? '')), 0, 200);
        $active = !isset($b['active']) || $b['active'] ? 1 : 0;
        $db->beginTransaction();
        try {
            if ($role === 'owner') {
                // المدير واحد: نقل الإدارة يجعل المدير الحالي نائباً
                if (!$old || $old['role'] !== 'owner') {
                    $db->prepare("UPDATE portal_users SET role = 'deputy' WHERE role = 'owner'")->execute();
                    self::audit('transfer_owner', $me['email'], 'نُقلت الإدارة إلى ' . $email, $email);
                }
                $active = 1;
            }
            if ($old) {
                $db->prepare('UPDATE portal_users SET role = ?, depts = ?, note = ?, active = ? WHERE id = ?')
                   ->execute([$role, $depts === null ? null : json_encode($depts), $note, $active, $old['id']]);
            } else {
                $db->prepare('INSERT INTO portal_users (email, role, depts, note, active, added_by) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$email, $role, $depts === null ? null : json_encode($depts), $note, $active, $me['email']]);
            }
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        self::audit($old ? 'user_update' : 'user_add', $me['email'], self::ROLES[$role] . ($depts ? ' · ' . implode(', ', $depts) : '') . ($active ? '' : ' · معطّل'), $email);
        self::publish();
        return ['ok' => true];
    }

    public static function deleteUser(array $me, string $email): array
    {
        self::need($me, 'users');
        $u = self::user($email);
        if (!$u) throw new HttpError(404, 'الحساب غير موجود', 'not_found');
        if ($u['role'] === 'owner') throw new HttpError(400, 'لا يُحذف المدير. انقل الإدارة إلى غيره أولاً', 'forbidden');
        if ($u['role'] === 'deputy' && $me['role'] !== 'owner') throw new HttpError(403, 'حسابات النواب يديرها المدير وحده', 'forbidden');
        if ($u['email'] === self::norm($me['email'])) throw new HttpError(400, 'لا يمكنك حذف حسابك', 'forbidden');
        ss_db()->prepare('DELETE FROM portal_users WHERE id = ?')->execute([$u['id']]);
        self::audit('user_delete', $me['email'], self::ROLES[$u['role']] ?? $u['role'], $u['email']);
        self::publish();
        return ['ok' => true];
    }

    public static function block(array $me, string $email, string $reason, bool $on): array
    {
        self::need($me, 'blocked');
        $email = self::validEmail($email);
        if ($on) {
            $u = self::user($email);
            if ($u && in_array($u['role'], ['owner', 'deputy'], true)) throw new HttpError(400, 'لا يُحظر المدير أو نائبه', 'forbidden');
            if ($email === self::norm($me['email'])) throw new HttpError(400, 'لا يمكنك حظر نفسك', 'forbidden');
            ss_db()->prepare('INSERT INTO portal_blocked (email, reason, added_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE reason = VALUES(reason)')
                ->execute([$email, mb_substr($reason, 0, 200), $me['email']]);
        } else ss_db()->prepare('DELETE FROM portal_blocked WHERE email = ?')->execute([$email]);
        self::audit($on ? 'block' : 'unblock', $me['email'], $reason, $email);
        self::publish();
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ التفرعات

    public static function sections(): array
    {
        self::seed();
        $rows = ss_db()->query('SELECT * FROM portal_sections ORDER BY sort, id')->fetchAll();
        foreach ($rows as &$r) {
            $r['sort'] = (int)$r['sort']; $r['builtin'] = (bool)$r['builtin'];
            $r['depts_off'] = $r['depts_off'] ? json_decode($r['depts_off'], true) : [];
            $r['status_ar'] = self::STATUSES[$r['status']] ?? $r['status'];
        }
        return $rows;
    }

    public static function saveSection(array $me, array $b): array
    {
        self::need($me, 'sections');
        $id = (string)($b['id'] ?? '');
        $status = (string)($b['status'] ?? 'visible');
        if (!isset(self::STATUSES[$status])) throw new HttpError(400, 'حالة غير معروفة', 'bad_status');
        $msg = mb_substr(trim((string)($b['message'] ?? '')), 0, 250);
        $off = self::cleanDepts($b['depts_off'] ?? null) ?: [];
        $db = ss_db();
        $st = $db->prepare('SELECT * FROM portal_sections WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            // تفرع جديد يضيفه المدير
            if (!preg_match('/^[a-z0-9-]{3,40}$/', $id)) throw new HttpError(400, 'المعرّف: 3–40 حرفاً لاتينياً صغيراً أو أرقاماً أو شرطات', 'bad_id');
            $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 100);
            $url = trim((string)($b['url'] ?? ''));
            if ($name === '' || !preg_match('#^(https://|/)#', $url)) throw new HttpError(400, 'الاسم والرابط مطلوبان (يبدأ الرابط بـ https:// أو /)', 'bad_section');
            $sys = ($b['system'] ?? '') === 'raasid' ? 'raasid' : 'amu';
            $match = (string)($b['match_re'] ?? '');
            if ($match === '') $match = preg_quote((string)preg_replace('#^https?://#', '', $url));
            if (@preg_match('~' . str_replace('~', '\~', $match) . '~', '') === false) throw new HttpError(400, 'نمط المطابقة غير صالح', 'bad_match');
            $db->prepare("INSERT INTO portal_sections (id, system, name, url, match_re, selector, status, message, depts_off, sort, builtin, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, '', ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP())")
               ->execute([$id, $sys, $name, mb_substr($url, 0, 250), mb_substr($match, 0, 250), $status, $msg, $off ? json_encode($off) : null, (int)($b['sort'] ?? 500), $me['email']]);
            self::audit('section_add', $me['email'], $name . ' · ' . self::STATUSES[$status], $id);
        } else {
            $name = isset($b['name']) && trim((string)$b['name']) !== '' ? mb_substr(trim((string)$b['name']), 0, 100) : $old['name'];
            $db->prepare('UPDATE portal_sections SET name = ?, status = ?, message = ?, depts_off = ?, sort = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
               ->execute([$name, $status, $msg, $off ? json_encode($off) : null, (int)($b['sort'] ?? $old['sort']), $me['email'], $id]);
            self::audit('section_update', $me['email'], $name . ' · ' . self::STATUSES[$status] . ($off ? ' · مخفي في ' . count($off) . ' قسم' : ''), $id);
        }
        self::publish();
        return ['ok' => true];
    }

    public static function deleteSection(array $me, string $id): array
    {
        self::need($me, 'sections');
        $st = ss_db()->prepare('SELECT builtin, name FROM portal_sections WHERE id = ?');
        $st->execute([$id]);
        $s = $st->fetch();
        if (!$s) throw new HttpError(404, 'غير موجود', 'not_found');
        if ((int)$s['builtin']) throw new HttpError(400, 'التفرعات الأساسية لا تُحذف، يمكن إخفاؤها', 'forbidden');
        ss_db()->prepare('DELETE FROM portal_sections WHERE id = ?')->execute([$id]);
        self::audit('section_delete', $me['email'], (string)$s['name'], $id);
        self::publish();
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ الأخبار والإعلانات

    public static function news(array $me): array
    {
        $rows = ss_db()->query('SELECT * FROM portal_news ORDER BY active DESC, pinned DESC, created_at DESC LIMIT 300')->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['pinned'] = (bool)$r['pinned']; $r['active'] = (bool)$r['active']; $r['depts'] = $r['depts'] ? json_decode($r['depts'], true) : null; }
        unset($r);
        return $rows;
    }

    /** المشرف يكتب لأقسامه فقط، ولا يعدّل إعلانات غيره */
    private static function newsScope(array $me, ?array $depts): ?array
    {
        if ($me['can']['news_all']) return $depts;
        $mine = $me['depts'] ?? [];
        if (!$depts) return $mine;
        if (array_diff($depts, $mine)) throw new HttpError(403, 'يمكنك النشر لأقسامك فقط', 'forbidden');
        return $depts;
    }

    public static function saveNews(array $me, array $b): array
    {
        self::need($me, 'news');
        $title = mb_substr(trim((string)($b['title'] ?? '')), 0, 150);
        $body = mb_substr(trim((string)($b['body'] ?? '')), 0, 2000);
        if ($title === '') throw new HttpError(400, 'العنوان مطلوب', 'bad_news');
        $level = in_array($b['level'] ?? '', ['info', 'warn', 'urgent'], true) ? $b['level'] : 'info';
        $sys = in_array($b['systems'] ?? '', ['all', 'amu', 'raasid'], true) ? $b['systems'] : 'all';
        $depts = self::newsScope($me, self::cleanDepts($b['depts'] ?? null));
        $d = function ($v) { $v = trim((string)$v); if ($v === '') return null; $t = strtotime($v); if ($t === false) throw new HttpError(400, 'تاريخ غير صالح', 'bad_date'); return gmdate('Y-m-d H:i:s', $t); };
        $starts = $d($b['starts'] ?? ''); $ends = $d($b['ends'] ?? '');
        $vals = [$title, $body, $level, $sys, $depts === null ? null : json_encode($depts), !empty($b['pinned']) ? 1 : 0, $starts, $ends, !isset($b['active']) || $b['active'] ? 1 : 0];
        $db = ss_db();
        $id = (int)($b['id'] ?? 0);
        if ($id) {
            $st = $db->prepare('SELECT created_by FROM portal_news WHERE id = ?'); $st->execute([$id]);
            $owner = $st->fetchColumn();
            if ($owner === false) throw new HttpError(404, 'غير موجود', 'not_found');
            if (!$me['can']['news_all'] && $owner !== $me['email']) throw new HttpError(403, 'يمكنك تعديل إعلاناتك فقط', 'forbidden');
            $db->prepare('UPDATE portal_news SET title = ?, body = ?, level = ?, systems = ?, depts = ?, pinned = ?, starts = ?, ends = ?, active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
               ->execute(array_merge($vals, [$id]));
        } else {
            $db->prepare('INSERT INTO portal_news (title, body, level, systems, depts, pinned, starts, ends, active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
               ->execute(array_merge($vals, [$me['email']]));
            $id = (int)$db->lastInsertId();
        }
        self::audit($b['id'] ?? 0 ? 'news_update' : 'news_add', $me['email'], $title, (string)$id);
        self::publish();
        return ['ok' => true, 'id' => $id];
    }

    public static function deleteNews(array $me, int $id): array
    {
        self::need($me, 'news');
        $st = ss_db()->prepare('SELECT title, created_by FROM portal_news WHERE id = ?'); $st->execute([$id]);
        $n = $st->fetch();
        if (!$n) throw new HttpError(404, 'غير موجود', 'not_found');
        if (!$me['can']['news_all'] && $n['created_by'] !== $me['email']) throw new HttpError(403, 'يمكنك حذف إعلاناتك فقط', 'forbidden');
        ss_db()->prepare('DELETE FROM portal_news WHERE id = ?')->execute([$id]);
        self::audit('news_delete', $me['email'], (string)$n['title'], (string)$id);
        self::publish();
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ الإعدادات العامة للبوابتين

    /** ما تقرؤه صفحات البوابتين (عام، بلا بيانات شخصية: البريد يُرسل مجزّأً SHA-256) */
    public static function publicConfig(): array
    {
        $f = SS_APP_DIR . '/data/portal-config.json';
        if (is_file($f) && time() - (int)filemtime($f) < 300) {
            $j = json_decode((string)file_get_contents($f), true);
            if (is_array($j)) return $j;
        }
        return self::publish();
    }

    public static function publish(): array
    {
        $db = ss_db();
        self::seed();
        $secs = array_map(function ($s) {
            return ['id' => $s['id'], 'system' => $s['system'], 'name' => $s['name'], 'url' => $s['url'], 'match' => $s['match_re'], 'selector' => $s['selector'],
                    'status' => $s['status'], 'message' => $s['message'], 'depts_off' => $s['depts_off']];
        }, self::sections());
        $news = $db->query("SELECT id, title, body, level, systems, depts, pinned, starts, ends FROM portal_news
            WHERE active = 1 AND (starts IS NULL OR starts <= UTC_TIMESTAMP()) AND (ends IS NULL OR ends > UTC_TIMESTAMP())
            ORDER BY pinned DESC, FIELD(level, 'urgent', 'warn', 'info'), created_at DESC LIMIT 20")->fetchAll();
        foreach ($news as &$n) { $n['id'] = (int)$n['id']; $n['pinned'] = (bool)$n['pinned']; $n['depts'] = $n['depts'] ? json_decode($n['depts'], true) : null; }
        unset($n);
        $h = function ($e) { return hash('sha256', self::norm((string)$e)); };
        $cfg = ['v' => time(), 'sections' => $secs, 'news' => $news,
            'priv' => array_map($h, $db->query("SELECT email FROM portal_users WHERE active = 1 AND role IN ('owner','deputy')")->fetchAll(PDO::FETCH_COLUMN)),
            'blocked' => array_map($h, $db->query('SELECT email FROM portal_blocked')->fetchAll(PDO::FETCH_COLUMN))];
        // أقرب وقت يتغير فيه ظهور إعلان (بداية أو نهاية) يحدد صلاحية النسخة المخزنة
        @file_put_contents(SS_APP_DIR . '/data/portal-config.json', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        return $cfg;
    }

    // ------------------------------------------------------------------ الإحصاءات

    /** زيارة: مرة واحدة لكل زائر (عنوان مجزّأ بملح يومي) لكل تفرع في اليوم */
    public static function hit(string $section, string $dept): void
    {
        if (!preg_match('/^[a-z0-9-]{2,40}$/', $section)) return;
        if (!preg_match('/^[A-Za-z0-9_-]{0,40}$/', $dept)) $dept = '';
        $db = ss_db();
        $day = gmdate('Y-m-d', time() + 10800);   // يوم بغداد
        $salt = (string)(ss_config()['deploy_token'] ?? 'ss') . $day;
        $k = hash('sha256', $salt . '|' . Http::ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . $section);
        $st = $db->prepare('INSERT IGNORE INTO portal_hit_seen (k, day) VALUES (?, ?)');
        $st->execute([substr($k, 0, 40), $day]);
        if ($st->rowCount() !== 1) return;
        $db->prepare('INSERT INTO portal_hits (day, section, dept, n) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$day, $section, $dept]);
        if (random_int(1, 200) === 1) $db->prepare('DELETE FROM portal_hit_seen WHERE day < ?')->execute([gmdate('Y-m-d', time() - 2 * 86400)]);
    }

    public static function stats(array $me, int $days): array
    {
        self::need($me, 'stats');
        $db = ss_db();
        $from = gmdate('Y-m-d', time() + 10800 - ($days - 1) * 86400);
        $q = function (string $sql) use ($db, $from) { $st = $db->prepare($sql); $st->execute([$from]); return $st->fetchAll(); };
        $daily = $q('SELECT day, SUM(n) n FROM portal_hits WHERE day >= ? GROUP BY day ORDER BY day');
        $bySec = $q('SELECT section, SUM(n) n FROM portal_hits WHERE day >= ? GROUP BY section ORDER BY n DESC');
        $byDept = $q("SELECT dept, SUM(n) n FROM portal_hits WHERE day >= ? AND dept <> '' GROUP BY dept ORDER BY n DESC");
        $logins = $q("SELECT DATE(at) day, email, COUNT(*) n FROM portal_audit WHERE action = 'login' AND at >= ? GROUP BY DATE(at), email ORDER BY day DESC LIMIT 200");
        $today = gmdate('Y-m-d', time() + 10800);
        $cast = function ($rows, $k) { return array_map(function ($r) use ($k) { return [$k => $r[$k], 'n' => (int)$r['n']]; }, $rows); };
        return ['days' => $days, 'daily' => $cast($daily, 'day'), 'sections' => $cast($bySec, 'section'), 'depts' => $cast($byDept, 'dept'),
            'today' => (int)array_sum(array_map(function ($r) use ($today) { return $r['day'] === $today ? (int)$r['n'] : 0; }, $daily)),
            'total' => (int)array_sum(array_column($daily, 'n')), 'logins' => $logins];
    }

    public static function auditLog(array $me, int $limit): array
    {
        self::need($me, 'audit');
        $st = ss_db()->prepare('SELECT at, email, action, target, detail, ip FROM portal_audit ORDER BY id DESC LIMIT ?');
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------ الكليات والأقسام

    /** قائمة الكليات من بوابة القسم (colleges.json تُحرَّر من لوحة مدير البوابة) مع المشرفين والزيارات */
    public static function colleges(): array
    {
        $doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $list = null;
        foreach ([$doc . '/amu/colleges.json', $doc . '/app/brand/colleges.json', dirname(SS_APP_DIR, 2) . '/web/brand/colleges.json'] as $f) {
            if ($f && is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j) && !empty($j['colleges'])) { $list = $j; break; } }
        }
        $list = $list ?: ['colleges' => []];
        $sup = [];
        foreach (ss_db()->query("SELECT email, name, depts FROM portal_users WHERE role = 'supervisor' AND active = 1")->fetchAll() as $u) {
            foreach ((array)json_decode((string)$u['depts'], true) as $d) $sup[$d][] = $u['name'] ?: $u['email'];
        }
        $st = ss_db()->prepare('SELECT dept, SUM(n) n FROM portal_hits WHERE day >= ? GROUP BY dept');
        $st->execute([gmdate('Y-m-d', time() - 29 * 86400)]);
        $hits = array_column($st->fetchAll(), 'n', 'dept');
        $off = [];
        foreach (self::sections() as $s) foreach ($s['depts_off'] as $d) $off[$d][] = $s['name'];
        foreach ($list['colleges'] as &$c) foreach ($c['depts'] as &$d) {
            $d['supervisors'] = $sup[$d['id']] ?? []; $d['visits_30d'] = (int)($hits[$d['id']] ?? 0); $d['hidden_sections'] = $off[$d['id']] ?? [];
        }
        return $list;
    }
}
