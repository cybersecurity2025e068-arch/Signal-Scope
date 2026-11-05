<?php
final class Cards
{
    const MAX_QTY = 2000;

    /** توليد دفعة كروت؛ إن كانت لوكيل يُخصم ثمنها من رصيده */
    public static function generate(int $planId, int $qty, ?int $agentId, string $note = ''): int
    {
        $me = Auth::user();
        if ($me['role'] === 'agent') {
            $agentId = (int) $me['id'];
        } elseif ($me['role'] !== 'admin') {
            throw new UserError('توليد الكروت للمدير والوكلاء فقط.');
        }
        if ($qty < 1 || $qty > self::MAX_QTY) {
            throw new UserError('العدد بين 1 و ' . self::MAX_QTY . '.');
        }
        $plan = Db::one('SELECT * FROM plans WHERE id = ? AND active = 1', [$planId]);
        if (!$plan) {
            throw new UserError('الباقة غير متاحة.');
        }
        if ($agentId && !Db::val("SELECT id FROM users WHERE id = ? AND role = 'agent'", [$agentId])) {
            throw new UserError('الوكيل غير موجود.');
        }
        return Db::tx(function () use ($plan, $qty, $agentId, $note, $me) {
            $price = (int) $plan['price'];
            $bid = Db::insert('card_batches', [
                'plan_id' => (int) $plan['id'], 'agent_id' => $agentId, 'qty' => $qty, 'unit_price' => $price,
                'note' => mb_substr($note, 0, 255), 'created_by' => (int) $me['id'], 'created_at' => now(),
            ]);
            if ($agentId && $price > 0) {
                Billing::move($agentId, -$price * $qty, 'cards', ['batch_id' => $bid, 'note' => $qty . ' كارت ' . $plan['name']]);
            }
            $st = Db::pdo()->prepare('INSERT INTO cards (batch_id, serial, pin, plan_id, agent_id) VALUES (?, ?, ?, ?, ?)');
            for ($i = 1; $i <= $qty; $i++) {
                $serial = sprintf('%05d%04d', $bid, $i);
                for ($try = 0; ; $try++) {
                    $pin = (string) random_int(1, 9) . rand_code(13, '0123456789');
                    try {
                        $st->execute([$bid, $serial, $pin, (int) $plan['id'], $agentId]);
                        break;
                    } catch (PDOException $e) {
                        if ($try > 5 || $e->getCode() !== '23000') {
                            throw $e;
                        }
                    }
                }
            }
            Audit::log('cards_create', 'batch', $bid, $qty . ' × ' . $plan['name'] . ($agentId ? ' · وكيل #' . $agentId : ''));
            return $bid;
        });
    }

    public static function normalizePin(string $pin): string
    {
        $pin = strtr($pin, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        return preg_replace('/\D/', '', $pin);
    }

    /** يعيد صف الكارت الجاهز للاستعمال أو يرمي خطأ */
    public static function find(string $pin, bool $forAgent = true): array
    {
        $pin = self::normalizePin($pin);
        if (strlen($pin) < 10) {
            throw new UserError('رقم الكارت غير صحيح.');
        }
        $c = Db::one('SELECT c.*, b.unit_price FROM cards c JOIN card_batches b ON b.id = c.batch_id WHERE c.pin = ?', [$pin]);
        if (!$c) {
            throw new UserError('رقم الكارت غير صحيح.');
        }
        if ($c['status'] === 'used') {
            throw new UserError('هذا الكارت مستخدم بتاريخ ' . fmt_dt($c['used_at']) . '.');
        }
        if ($c['status'] === 'disabled') {
            throw new UserError('هذا الكارت معطّل.');
        }
        $me = Auth::user();
        if ($forAgent && $me && $me['role'] === 'agent' && (int) $c['agent_id'] !== (int) $me['id']) {
            throw new UserError('هذا الكارت لا يعود لحسابك.');
        }
        return $c;
    }

    public static function redeem(string $pin, int $subId, bool $portal = false): array
    {
        $c = self::find($pin, !$portal);
        $opt = ['card' => $c];
        if ($portal) {
            $opt['portal'] = true;
        }
        return Subscribers::activate($subId, (int) $c['plan_id'], $opt);
    }
}
