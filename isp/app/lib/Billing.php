<?php
final class Billing
{
    /**
     * حركة على رصيد مستخدم (موجب = إضافة، سالب = خصم) داخل معاملة.
     * الخصم لا يتجاوز الرصيد + حد الائتمان.
     * @return array [transaction_id, balance_after]
     */
    public static function move(int $userId, int $amount, string $type, array $extra = []): array
    {
        return Db::tx(function () use ($userId, $amount, $type, $extra) {
            $u = Db::one('SELECT id, balance, credit_limit, username FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$u) {
                throw new UserError('الحساب غير موجود.');
            }
            $after = (int) $u['balance'] + $amount;
            if ($amount < 0 && $after < -(int) $u['credit_limit'] && empty($extra['force'])) {
                throw new UserError('الرصيد غير كافٍ لدى ' . $u['username'] . ': المتاح ' . number_format((int) $u['balance'] + (int) $u['credit_limit']) . ' والمطلوب ' . number_format(-$amount) . '.');
            }
            Db::update('users', ['balance' => $after], 'id = ?', [$userId]);
            $me = Auth::user();
            $id = Db::insert('transactions', [
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $after,
                'subscriber_id' => $extra['subscriber_id'] ?? null,
                'activation_id' => $extra['activation_id'] ?? null,
                'batch_id' => $extra['batch_id'] ?? null,
                'note' => mb_substr((string) ($extra['note'] ?? ''), 0, 255),
                'created_by' => $me ? (int) $me['id'] : null,
                'created_at' => now(),
            ]);
            return [$id, $after];
        });
    }
}
