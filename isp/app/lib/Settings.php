<?php
final class Settings
{
    const DEFAULTS = [
        'company_name' => 'شبكتي',
        'currency' => 'د.ع',
        'expiry_warn_days' => '3',
        'portal_enabled' => '1',
        'portal_notice' => '',
        'support_phone' => '',
        'agent_can_create' => '1',
        'agent_can_delete' => '0',
    ];
    /** @var array|null */
    private static $cache;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Db::all('SELECT k, v FROM settings') as $r) {
                    self::$cache[$r['k']] = $r['v'];
                }
            } catch (Throwable $e) {
                // الجداول لم تُنشأ بعد
            }
        }
        return self::$cache;
    }

    public static function get(string $k): string
    {
        $a = self::all();
        return (string) ($a[$k] ?? '');
    }

    public static function set(string $k, string $v): void
    {
        Db::q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        self::$cache = null;
    }
}
