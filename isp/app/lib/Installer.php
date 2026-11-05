<?php
/** إنشاء الجداول والترحيلات وحساب المدير الأول. آمن للتكرار. */
final class Installer
{
    /** يعيد كلمة مرور المدير إن أُنشئ الآن، وإلا '' */
    public static function run(): string
    {
        $sql = (string) file_get_contents(APP_DIR . '/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            Db::pdo()->exec($stmt);
        }
        $need = ['activations' => ['prev_expires_at' => 'DATETIME NULL', 'prev_plan_id' => 'INT UNSIGNED NULL', 'cancelled' => 'TINYINT(1) NOT NULL DEFAULT 0']];
        foreach ($need as $t => $cols) {
            $have = array_column(Db::all('SHOW COLUMNS FROM `' . $t . '`'), 'Field');
            foreach ($cols as $c => $def) {
                if (!in_array($c, $have, true)) {
                    Db::pdo()->exec("ALTER TABLE `$t` ADD `$c` $def");
                }
            }
        }
        if ((int) Db::val('SELECT COUNT(*) FROM users') > 0) {
            return '';
        }
        $pass = rand_code(16, 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789');
        Db::insert('users', ['username' => 'admin', 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'full_name' => 'المدير', 'role' => 'admin', 'created_at' => now()]);
        if ((int) Db::val('SELECT COUNT(*) FROM plans') === 0) {
            $t = now();
            foreach ([['باقة 10 ميگا', 10, 2, 25000, 30000], ['باقة 25 ميگا', 25, 5, 35000, 40000], ['باقة 50 ميگا', 50, 10, 50000, 60000]] as $i => $p) {
                Db::insert('plans', ['name' => $p[0], 'speed_down' => $p[1], 'speed_up' => $p[2], 'duration_days' => 30, 'price' => $p[3], 'retail_price' => $p[4], 'sort_order' => $i, 'created_at' => $t]);
            }
        }
        $info = APP_DIR . '/SETUP-INFO.txt';
        @file_put_contents($info, "لوحة إدارة المشتركين — بيانات الدخول (أُنشئ " . now() . ")\n"
            . "احذف هذا الملف بعد حفظ المعلومات، وغيّر كلمة المرور من الملف الشخصي.\n\n"
            . "اسم المستخدم:  admin\nكلمة المرور:   $pass\n");
        @chmod($info, 0600);
        return $pass;
    }

    /** يُستدعى مع كل طلب: يثبّت مرة واحدة لكل إصدار (علامة في ملف) */
    public static function ensure(): void
    {
        $mark = APP_DIR . '/.installed-' . APP_VERSION;
        if (is_file($mark)) {
            return;
        }
        self::run();
        @file_put_contents($mark, now());
    }
}
