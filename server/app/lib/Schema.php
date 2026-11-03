<?php
declare(strict_types=1);

/** إنشاء الجداول من schema.sql عند أول إعداد، فلا حاجة لاستيرادها يدوياً من phpMyAdmin */
final class Schema
{
    public static function installed(PDO $db): bool
    {
        return (bool)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admins'")->fetchColumn();
    }

    public static function apply(PDO $db): void
    {
        $sql = (string)file_get_contents(SS_APP_DIR . '/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $db->exec($stmt);
        }
    }
}
