<?php
declare(strict_types=1);

/**
 * تحديث ذاتي من GitHub: ينزّل أحدث نسخة من الفرع main ويثبّتها بنفس توزيع .cpanel.yml.
 * لا يلمس config.php ولا ملفات البيانات. يُستدعى من POST /v1/admin/deploy مع X-Deploy-Token.
 */
final class Updater
{
    public const REPO = 'cybersecurity2025e068-arch/Signal-Scope';
    public const BRANCH = 'main';

    /** @return array<string,mixed> */
    public static function run(string $docRoot): array
    {
        @set_time_limit(240);
        $log = [];
        $tmp = SS_APP_DIR . '/data/update';
        self::rmrf($tmp);
        if (!@mkdir($tmp, 0700, true) && !is_dir($tmp)) throw new RuntimeException('تعذّر إنشاء مجلد مؤقت');

        $sha = self::latestSha();
        $log[] = 'commit ' . ($sha ? substr($sha, 0, 12) : '?');
        // التنزيل برقم الـ commit لا باسم الفرع: رابط الفرع قد يعيد نسخة قديمة مخزّنة مؤقتاً
        $ref = $sha ?: 'refs/heads/' . self::BRANCH;

        // 1) التنزيل والفك: zip إن وُجد ZipArchive، وإلا tar.gz عبر PharData
        if (class_exists('ZipArchive')) {
            $file = $tmp . '/src.zip';
            self::download('https://codeload.github.com/' . self::REPO . '/zip/' . $ref, $file);
            $z = new ZipArchive();
            if ($z->open($file) !== true) throw new RuntimeException('ملف zip تالف');
            $z->extractTo($tmp . '/x');
            $z->close();
        } elseif (class_exists('PharData')) {
            $file = $tmp . '/src.tar.gz';
            self::download('https://codeload.github.com/' . self::REPO . '/tar.gz/' . $ref, $file);
            $p = new PharData($file);
            $p->extractTo($tmp . '/x', null, true);
        } else {
            throw new RuntimeException('لا يوجد ZipArchive ولا PharData في PHP');
        }
        $roots = glob($tmp . '/x/*', GLOB_ONLYDIR) ?: [];
        if (count($roots) !== 1) throw new RuntimeException('بنية الأرشيف غير متوقعة');
        $src = $roots[0];
        if (!is_file($src . '/server/public/api/index.php') || !is_file($src . '/server/app/bootstrap.php')) {
            throw new RuntimeException('الأرشيف لا يحوي ملفات الخادم المتوقعة');
        }

        // 2) النسخ (نفس توزيع .cpanel.yml)
        $docRoot = rtrim($docRoot, '/');
        $n = 0;
        $n += self::copyDir($src . '/server/public/api', $docRoot . '/api');
        $n += self::copyDir($src . '/server/public/dashboard', $docRoot . '/dashboard');
        $n += self::copyDir($src . '/web', $docRoot . '/app');
        $n += self::copyDir($src . '/server/public/raasid', $docRoot . '/raasid');   // البوابة الموحدة بثلاثة تبويبات
        foreach (['lib', 'cron', 'bin', 'data'] as $d) $n += self::copyDir($src . '/server/app/' . $d, SS_APP_DIR . '/' . $d, ['update']);
        foreach (['bootstrap.php', 'schema.sql', 'config.example.php', '.htaccess', 'rotate.id'] as $f) {
            if (is_file($src . '/server/app/' . $f)) { self::copyFile($src . '/server/app/' . $f, SS_APP_DIR . '/' . $f); $n++; }
        }
        $log[] = "copied $n files";

        // 2b) لوحة إدارة المشتركين: isp/public ← public_html/isp و isp/app ← ~/isppanel
        if (is_dir($src . '/isp/public') && is_dir($src . '/isp/app')) {
            $ispApp = dirname(SS_APP_DIR) . '/isppanel';
            $m = self::copyDir($src . '/isp/public', $docRoot . '/isp') + self::copyDir($src . '/isp/app', $ispApp);
            $isp_index = $docRoot . '/isp/index.php';
            $linked = include $ispApp . '/bin/link-config.php';
            $log[] = "isp: $m files" . (is_array($linked) && $linked ? ' · ' . implode(', ', $linked) : '');
        }

        // 3) أحدث APK من إصدارات GitHub إلى خادمك (تنزيل أسرع وأضمن من الهاتف)
        try { $log[] = self::fetchApk($docRoot . '/raasid'); } catch (Throwable $e) { $log[] = 'apk: ' . $e->getMessage(); }

        // 4) ترقية قاعدة البيانات (آمنة للتكرار)
        require_once SS_APP_DIR . '/lib/Schema.php';
        Schema::apply(ss_db());
        $log[] = 'schema applied';

        self::rmrf($tmp);
        $info = ['sha' => $sha ? substr($sha, 0, 12) : null, 'at' => gmdate('c'), 'files' => $n];
        @file_put_contents(SS_APP_DIR . '/data/deployed.json', json_encode($info));
        if (function_exists('opcache_reset')) @opcache_reset();
        return ['ok' => true, 'deployed' => $info, 'log' => $log];
    }

    /** ينسخ أحدث SignalScope-N.apk من إصدار «latest» إلى raasid/SignalScope.apk مع ملف الإصدار */
    public static function fetchApk(string $dir): string
    {
        $rel = json_decode(self::fetch('https://api.github.com/repos/' . self::REPO . '/releases/tags/latest'), true);
        $best = null;
        foreach (($rel['assets'] ?? []) as $a) {
            if (preg_match('/^SignalScope-(\d+)\.apk$/', (string)($a['name'] ?? ''), $m) && (!$best || (int)$m[1] > $best[0])) $best = [(int)$m[1], $a];
        }
        if (!$best) return 'apk: no asset';
        $meta = $dir . '/SignalScope.json';
        $cur = is_file($meta) ? json_decode((string)file_get_contents($meta), true) : null;
        if (is_array($cur) && ($cur['build'] ?? 0) === $best[0] && is_file($dir . '/SignalScope.apk')) return 'apk: build ' . $best[0] . ' already present';
        $data = self::fetch((string)$best[1]['browser_download_url']);
        if (strlen($data) !== (int)$best[1]['size'] || substr($data, 0, 2) !== 'PK') throw new RuntimeException('APK download incomplete');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($dir . '/SignalScope.apk.tmp', $data);
        rename($dir . '/SignalScope.apk.tmp', $dir . '/SignalScope.apk');
        file_put_contents($meta, json_encode(['build' => $best[0], 'size' => strlen($data), 'sha256' => hash('sha256', $data), 'at' => gmdate('c')]));
        return 'apk: build ' . $best[0] . ' (' . round(strlen($data) / 1048576, 2) . ' MB)';
    }

    private static function latestSha(): ?string
    {
        try {
            $j = json_decode(self::fetch('https://api.github.com/repos/' . self::REPO . '/commits/' . self::BRANCH), true);
            return is_array($j) && isset($j['sha']) && preg_match('/^[0-9a-f]{40}$/', (string)$j['sha']) ? (string)$j['sha'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function download(string $url, string $to): void
    {
        $data = self::fetch($url);
        if (strlen($data) < 1000) throw new RuntimeException('التنزيل من GitHub فشل');
        file_put_contents($to, $data);
    }

    private static function fetch(string $url): string
    {
        $ua = 'SignalScope-Updater/' . SS_VERSION;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: */*']]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code >= 400) throw new RuntimeException("HTTP $code $err");
            return (string)$body;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: $ua\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) throw new RuntimeException('تعذّر الاتصال بـ GitHub (curl و allow_url_fopen غير متاحين)');
        return $body;
    }

    /** @param string[] $skip */
    private static function copyDir(string $from, string $to, array $skip = []): int
    {
        if (!is_dir($from)) return 0;
        if (!is_dir($to)) @mkdir($to, 0755, true);
        $n = 0;
        foreach (scandir($from) ?: [] as $e) {
            if ($e === '.' || $e === '..' || in_array($e, $skip, true) || $e === 'config.php') continue;
            $a = $from . '/' . $e; $b = $to . '/' . $e;
            if (is_link($a)) continue;
            if (is_dir($a)) $n += self::copyDir($a, $b);
            else { self::copyFile($a, $b); $n++; }
        }
        return $n;
    }

    private static function copyFile(string $a, string $b): void
    {
        $tmp = $b . '.tmp-' . getmypid();
        if (!@copy($a, $tmp) || !@rename($tmp, $b)) { @unlink($tmp); throw new RuntimeException('تعذّر نسخ ' . basename($b)); }
        if (substr($b, -3) === '.sh') @chmod($b, 0755);
    }

    private static function rmrf(string $p): void
    {
        if (is_link($p) || is_file($p)) { @unlink($p); return; }
        if (!is_dir($p)) return;
        foreach (scandir($p) ?: [] as $e) if ($e !== '.' && $e !== '..') self::rmrf($p . '/' . $e);
        @rmdir($p);
    }
}
