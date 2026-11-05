<?php
final class Audit
{
    public static function log(string $action, string $entity = '', ?int $id = null, string $details = ''): void
    {
        $u = Auth::user();
        Db::insert('audit_log', [
            'user_id' => $u ? (int) $u['id'] : null,
            'actor' => $u ? $u['username'] : (string) ($_SESSION['portal_user'] ?? 'system'),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $id,
            'details' => mb_substr($details, 0, 500),
            'ip' => client_ip(),
            'created_at' => now(),
        ]);
    }

    public static function label(string $a): string
    {
        $m = [
            'login' => 'تسجيل دخول', 'login_fail' => 'دخول فاشل', 'logout' => 'تسجيل خروج',
            'sub_create' => 'إضافة مشترك', 'sub_update' => 'تعديل مشترك', 'sub_delete' => 'حذف مشترك',
            'sub_activate' => 'تفعيل/تجديد', 'sub_suspend' => 'إيقاف', 'sub_resume' => 'إعادة تشغيل',
            'sub_extend' => 'تمديد مجاني', 'sub_transfer' => 'نقل لوكيل', 'sub_cancel' => 'إلغاء آخر تفعيل',
            'plan_save' => 'حفظ باقة', 'user_save' => 'حفظ مستخدم', 'balance' => 'حركة رصيد',
            'cards_create' => 'توليد كروت', 'card_disable' => 'تعطيل كارت', 'settings' => 'الإعدادات',
            'password' => 'تغيير كلمة المرور', 'portal_recharge' => 'شحن من بوابة المشترك',
        ];
        return $m[$a] ?? $a;
    }
}
