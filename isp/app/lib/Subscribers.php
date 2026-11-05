<?php
final class Subscribers
{
    const FIELDS = ['username', 'password', 'full_name', 'phone', 'phone2', 'address', 'zone', 'national_id', 'mac', 'static_ip', 'device', 'notes'];

    /** شرط يقصر النتائج على مشتركي الوكيل الحالي */
    public static function scope(string $alias = 's'): array
    {
        $u = Auth::user();
        if ($u && $u['role'] === 'agent') {
            return [$alias . '.agent_id = ?', [(int) $u['id']]];
        }
        return ['1=1', []];
    }

    public static function get(int $id, bool $lock = false): array
    {
        [$w, $p] = self::scope();
        $s = Db::one('SELECT s.* FROM subscribers s WHERE s.id = ? AND ' . $w . ($lock ? ' FOR UPDATE' : ''), array_merge([$id], $p));
        if (!$s) {
            throw new UserError('المشترك غير موجود أو ليس ضمن صلاحياتك.');
        }
        return $s;
    }

    public static function normalizeMac(string $m): string
    {
        $m = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $m));
        if ($m === '') {
            return '';
        }
        if (strlen($m) !== 12) {
            throw new UserError('عنوان MAC غير صالح.');
        }
        return implode(':', str_split($m, 2));
    }

    /** إضافة أو تعديل؛ يعيد رقم المشترك */
    public static function save(array $in, ?int $id = null): int
    {
        $me = Auth::user();
        $d = [];
        foreach (self::FIELDS as $f) {
            $d[$f] = trim((string) ($in[$f] ?? ''));
        }
        if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $d['username'])) {
            throw new UserError('اسم المستخدم: 3 إلى 64 حرفاً إنجليزياً أو أرقاماً أو . _ - @ بلا مسافات.');
        }
        if ($d['password'] === '' && !$id) {
            $d['password'] = rand_code(8, '23456789abcdefghjkmnpqrstuvwxyz');
        }
        if ($d['password'] !== '' && !preg_match('/^[\x21-\x7E]{4,64}$/', $d['password'])) {
            throw new UserError('كلمة مرور المشترك: 4 إلى 64 حرفاً إنجليزياً أو رمزاً بلا مسافات.');
        }
        $d['mac'] = self::normalizeMac($d['mac']);
        if ($d['static_ip'] !== '' && !filter_var($d['static_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new UserError('العنوان الثابت (IP) غير صالح.');
        }
        foreach (['lat', 'lng'] as $f) {
            $v = trim((string) ($in[$f] ?? ''));
            $d[$f] = is_numeric($v) ? (float) $v : null;
        }
        $dup = Db::val('SELECT id FROM subscribers WHERE username = ?' . ($id ? ' AND id <> ' . (int) $id : ''), [$d['username']]);
        if ($dup) {
            throw new UserError('اسم المستخدم "' . $d['username'] . '" مستخدم لمشترك آخر.');
        }
        $planId = (int) ($in['plan_id'] ?? 0);
        if ($planId && !Db::val('SELECT id FROM plans WHERE id = ?', [$planId])) {
            throw new UserError('الباقة غير موجودة.');
        }
        $d['plan_id'] = $planId ?: null;

        if ($me['role'] === 'agent') {
            $d['agent_id'] = (int) $me['id'];
        } else {
            $ag = (int) ($in['agent_id'] ?? 0);
            if ($ag && !Db::val("SELECT id FROM users WHERE id = ? AND role = 'agent'", [$ag])) {
                throw new UserError('الوكيل غير موجود.');
            }
            $d['agent_id'] = $ag ?: null;
        }
        $d['updated_at'] = now();

        if ($id) {
            $old = self::get($id);
            if ($d['password'] === '') {
                $d['password'] = $old['password'];
            }
            if ($me['role'] === 'agent') {
                $d['agent_id'] = $old['agent_id'];
            }
            Db::update('subscribers', $d, 'id = ?', [$id]);
            $changes = [];
            foreach ($d as $k => $v) {
                if ($k !== 'updated_at' && (string) $old[$k] !== (string) $v) {
                    $changes[] = $k === 'password' ? 'password' : $k . ': ' . $old[$k] . ' ← ' . $v;
                }
            }
            Audit::log('sub_update', 'subscriber', $id, implode(' | ', $changes));
            self::flashRadius(Radius::sync($id, $old['username']));
            return $id;
        }
        if ($me['role'] === 'agent' && Settings::get('agent_can_create') !== '1') {
            throw new UserError('إضافة المشتركين متاحة للإدارة فقط.');
        }
        $d['created_by'] = (int) $me['id'];
        $d['created_at'] = now();
        $id = Db::insert('subscribers', $d);
        Audit::log('sub_create', 'subscriber', $id, $d['username']);
        self::flashRadius(Radius::sync($id));
        return $id;
    }

    private static function flashRadius(string $err): void
    {
        if ($err !== '') {
            flash('warn', $err);
        }
    }

    /**
     * تفعيل أو تجديد. إن كان الاشتراك سارياً يُضاف المدة إلى تاريخ انتهائه.
     * $opt: charge (bool، للإدارة: خصم من رصيد الوكيل)، card (صف الكارت)، method
     */
    public static function activate(int $subId, int $planId, array $opt = []): array
    {
        $me = Auth::user();
        $res = Db::tx(function () use ($subId, $planId, $opt, $me) {
            $s = isset($opt['portal']) ? Db::one('SELECT * FROM subscribers WHERE id = ? FOR UPDATE', [$subId]) : self::get($subId, true);
            if (!$s) {
                throw new UserError('المشترك غير موجود.');
            }
            $plan = Db::one('SELECT * FROM plans WHERE id = ?', [$planId]);
            if (!$plan || (!$plan['active'] && empty($opt['card']) && !Auth::isStaff())) {
                throw new UserError('الباقة غير متاحة.');
            }
            $card = $opt['card'] ?? null;
            $now = time();
            $base = $s['expires_at'] && strtotime($s['expires_at']) > $now ? strtotime($s['expires_at']) : $now;
            $start = date('Y-m-d H:i:s', $base);
            $end = date('Y-m-d H:i:s', $base + 86400 * (int) $plan['duration_days']);

            $payer = null;
            if ($card) {
                $method = isset($opt['portal']) ? 'portal' : 'card';
                $agentId = $card['agent_id'] ? (int) $card['agent_id'] : ($s['agent_id'] ? (int) $s['agent_id'] : null);
                $price = (int) $card['unit_price'];
            } elseif ($me && $me['role'] === 'agent') {
                $method = 'balance';
                $payer = (int) $me['id'];
                $agentId = $payer;
                $price = (int) $plan['price'];
            } elseif (!empty($opt['charge']) && $s['agent_id']) {
                $method = 'balance';
                $payer = (int) $s['agent_id'];
                $agentId = $payer;
                $price = (int) $plan['price'];
            } else {
                $method = 'admin';
                $agentId = $s['agent_id'] ? (int) $s['agent_id'] : null;
                $price = (int) $plan['price'];
            }

            $actId = Db::insert('activations', [
                'subscriber_id' => $subId,
                'plan_id' => $planId,
                'agent_id' => $agentId,
                'method' => $method,
                'card_id' => $card ? (int) $card['id'] : null,
                'price' => $price,
                'retail_price' => (int) $plan['retail_price'],
                'starts_at' => $start,
                'ends_at' => $end,
                'prev_expires_at' => $s['expires_at'],
                'prev_plan_id' => $s['plan_id'],
                'created_by' => $me ? (int) $me['id'] : null,
                'created_at' => now(),
            ]);
            if ($payer && $price > 0) {
                Billing::move($payer, -$price, 'activation', [
                    'subscriber_id' => $subId, 'activation_id' => $actId,
                    'note' => 'تفعيل ' . $s['username'] . ' — ' . $plan['name'],
                ]);
            }
            if ($card) {
                $n = Db::q("UPDATE cards SET status = 'used', subscriber_id = ?, used_at = ? WHERE id = ? AND status = 'new'", [$subId, now(), $card['id']])->rowCount();
                if ($n !== 1) {
                    throw new UserError('الكارت مستخدم مسبقاً.');
                }
            }
            Db::update('subscribers', ['plan_id' => $planId, 'expires_at' => $end, 'updated_at' => now()], 'id = ?', [$subId]);
            Audit::log(isset($opt['portal']) ? 'portal_recharge' : 'sub_activate', 'subscriber', $subId, $plan['name'] . ' · ' . method_label($method) . ' · حتى ' . fmt_dt($end));
            return ['end' => $end, 'price' => $price, 'method' => $method, 'suspended' => (bool) $s['suspended']];
        });
        self::flashRadius(Radius::sync($subId));
        return $res;
    }

    /** يلغي آخر تفعيل: يعيد تاريخ الانتهاء والباقة، ويرجع المبلغ أو الكارت */
    public static function cancelLast(int $subId): void
    {
        Auth::requireStaff();
        Db::tx(function () use ($subId) {
            $s = self::get($subId, true);
            $a = Db::one('SELECT * FROM activations WHERE subscriber_id = ? AND cancelled = 0 ORDER BY id DESC LIMIT 1 FOR UPDATE', [$subId]);
            if (!$a) {
                throw new UserError('لا يوجد تفعيل لإلغائه.');
            }
            if ($a['ends_at'] !== $s['expires_at']) {
                throw new UserError('تاريخ الانتهاء عُدّل بعد هذا التفعيل؛ عدّله يدوياً بالتمديد.');
            }
            $tx = Db::one("SELECT * FROM transactions WHERE activation_id = ? AND type = 'activation'", [$a['id']]);
            if ($tx) {
                Billing::move((int) $tx['user_id'], -(int) $tx['amount'], 'refund', [
                    'subscriber_id' => $subId, 'activation_id' => (int) $a['id'], 'note' => 'إلغاء تفعيل ' . $s['username'],
                ]);
            }
            if ($a['card_id']) {
                Db::q("UPDATE cards SET status = 'new', subscriber_id = NULL, used_at = NULL WHERE id = ?", [$a['card_id']]);
            }
            Db::update('activations', ['cancelled' => 1], 'id = ?', [$a['id']]);
            Db::update('subscribers', ['expires_at' => $a['prev_expires_at'], 'plan_id' => $a['prev_plan_id'] ?: $a['plan_id'], 'updated_at' => now()], 'id = ?', [$subId]);
            Audit::log('sub_cancel', 'subscriber', $subId, 'تفعيل #' . $a['id'] . ($tx ? ' · أُرجع ' . number_format(-(int) $tx['amount']) : ''));
        });
        self::flashRadius(Radius::sync($subId));
    }

    public static function setSuspended(int $subId, bool $on, string $reason = ''): void
    {
        $s = self::get($subId);
        Db::update('subscribers', ['suspended' => $on ? 1 : 0, 'suspend_reason' => $on ? mb_substr($reason, 0, 255) : '', 'updated_at' => now()], 'id = ?', [$subId]);
        Audit::log($on ? 'sub_suspend' : 'sub_resume', 'subscriber', $subId, $s['username'] . ($reason ? ' · ' . $reason : ''));
        self::flashRadius(Radius::sync($subId));
    }

    /** تمديد مجاني بالأيام (سالب للإنقاص) — للإدارة */
    public static function extend(int $subId, int $days, string $note = ''): void
    {
        Auth::requireStaff();
        if ($days === 0 || abs($days) > 3650) {
            throw new UserError('عدد الأيام غير صالح.');
        }
        $s = self::get($subId);
        $base = $s['expires_at'] && strtotime($s['expires_at']) > time() ? strtotime($s['expires_at']) : time();
        if ($days < 0) {
            $base = $s['expires_at'] ? strtotime($s['expires_at']) : time();
        }
        $end = date('Y-m-d H:i:s', $base + 86400 * $days);
        Db::update('subscribers', ['expires_at' => $end, 'updated_at' => now()], 'id = ?', [$subId]);
        Audit::log('sub_extend', 'subscriber', $subId, $days . ' يوم · حتى ' . fmt_dt($end) . ($note ? ' · ' . $note : ''));
        self::flashRadius(Radius::sync($subId));
    }

    public static function setExpiry(int $subId, string $dt): void
    {
        Auth::requireStaff();
        $t = strtotime($dt);
        if (!$t) {
            throw new UserError('التاريخ غير صالح.');
        }
        $s = self::get($subId);
        $end = date('Y-m-d H:i:s', $t);
        Db::update('subscribers', ['expires_at' => $end, 'updated_at' => now()], 'id = ?', [$subId]);
        Audit::log('sub_extend', 'subscriber', $subId, 'تعيين الانتهاء: ' . fmt_dt($s['expires_at']) . ' ← ' . fmt_dt($end));
        self::flashRadius(Radius::sync($subId));
    }

    public static function transfer(int $subId, int $agentId): void
    {
        Auth::requireStaff();
        $s = self::get($subId);
        if ($agentId && !Db::val("SELECT id FROM users WHERE id = ? AND role = 'agent'", [$agentId])) {
            throw new UserError('الوكيل غير موجود.');
        }
        Db::update('subscribers', ['agent_id' => $agentId ?: null, 'updated_at' => now()], 'id = ?', [$subId]);
        Audit::log('sub_transfer', 'subscriber', $subId, $s['username'] . ' → ' . ($agentId ? Db::val('SELECT username FROM users WHERE id = ?', [$agentId]) : 'بلا وكيل'));
    }

    public static function delete(int $subId): void
    {
        $me = Auth::user();
        if ($me['role'] === 'agent' && Settings::get('agent_can_delete') !== '1') {
            throw new UserError('حذف المشتركين متاح للإدارة فقط.');
        }
        $s = self::get($subId);
        Db::q('DELETE FROM subscribers WHERE id = ?', [$subId]);
        Audit::log('sub_delete', 'subscriber', $subId, $s['username'] . ' · ' . $s['full_name']);
        try {
            Radius::remove($s['username']);
        } catch (Throwable $e) {
            flash('warn', 'تعذّر الحذف من RADIUS: ' . $e->getMessage());
        }
    }
}
