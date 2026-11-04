<?php
declare(strict_types=1);

/**
 * نسخ احتياطي يومي لقاعدة البيانات: ملف SQL مضغوط (gzip) في ~/signalscope/backups (خارج public_html، صلاحيات 0600).
 * يُحتفظ بآخر 7 نسخ. لا تُنسخ الجداول التي يعيد النظام بناءها تلقائياً (المباني، مربعات OSM، نتائج تحليل العوائق).
 * الاستعادة: phpMyAdmin ← Import ← اختر الملف .sql.gz
 */
final class Backup
{
    private const TABLES = ['admins', 'devices', 'sessions', 'cells', 'samples', 'alerts'];
    private const KEEP = 7;

    public static function dir(): string
    {
        $d = SS_APP_DIR . '/backups';
        if (!is_dir($d)) { @mkdir($d, 0700, true); @file_put_contents("$d/.htaccess", "Require all denied\n"); }
        return $d;
    }

    /** @return array<string,mixed> */
    public static function run(): array
    {
        @set_time_limit(300);
        $db = ss_db_alive();
        $name = 'signalscope-' . gmdate('Ymd-His') . '.sql.gz';
        $path = self::dir() . '/' . $name;
        $gz = gzopen("$path.tmp", 'wb6');
        if (!$gz) throw new RuntimeException('تعذّر إنشاء ملف النسخة');
        gzwrite($gz, "-- SignalScope backup " . gmdate('c') . " (v" . SS_VERSION . ")\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $rows = [];
        foreach (self::TABLES as $t) {
            $exists = $db->query("SHOW TABLES LIKE " . $db->quote($t))->fetchColumn();
            if (!$exists) continue;
            $create = $db->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
            $n = 0; $last = 0;
            // دفعات بالمفتاح الأساسي: ذاكرة ثابتة مهما كبر الجدول
            while (true) {
                $st = $db->prepare("SELECT * FROM `$t` WHERE id > ? ORDER BY id LIMIT 2000");
                $st->execute([$last]);
                $batch = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!$batch) break;
                $cols = '`' . implode('`,`', array_keys($batch[0])) . '`';
                $vals = [];
                foreach ($batch as $r) {
                    $vals[] = '(' . implode(',', array_map(function ($v) use ($db) { return $v === null ? 'NULL' : $db->quote((string)$v); }, $r)) . ')';
                    $last = (int)$r['id'];
                }
                gzwrite($gz, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n");
                $n += count($batch);
                if (count($batch) < 2000) break;
            }
            $rows[$t] = $n;
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        rename("$path.tmp", $path);
        @chmod($path, 0600);
        // الاحتفاظ بآخر 7 نسخ
        $all = self::list();
        foreach (array_slice($all, self::KEEP) as $old) @unlink(self::dir() . '/' . $old['name']);
        return ['ok' => true, 'name' => $name, 'size' => filesize($path), 'rows' => $rows];
    }

    /** @return array<int,array{name:string,size:int,at:string}> الأحدث أولاً */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/signalscope-*.sql.gz') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'at' => gmdate('c', (int)filemtime($f))];
        }
        usort($out, function ($a, $b) { return strcmp($b['name'], $a['name']); });
        return $out;
    }

    public static function send(string $name): void
    {
        if (!preg_match('/^signalscope-\d{8}-\d{6}\.sql\.gz$/', $name)) throw new HttpError(400, 'اسم غير صالح', 'bad_name');
        $f = self::dir() . '/' . $name;
        if (!is_file($f)) throw new HttpError(404, 'النسخة غير موجودة', 'not_found');
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($f));
        header('Cache-Control: no-store');
        readfile($f);
        exit;
    }
}
