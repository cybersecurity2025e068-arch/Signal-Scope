<?php
declare(strict_types=1);

/**
 * مباني Microsoft Global ML Building Footprints (رخصة ODbL): بصمات مبانٍ مستخرجة من صور الأقمار
 * الصناعية تغطي العراق، مقسّمة بمربعات quadkey بمستوى 9 (~0.7°).
 * يُنزَّل ملف المربع مرة واحدة (~25 MB لمنطقة البصرة) ويُستورد إلى جدول buildings على دفعات
 * تُستأنف بين التشغيلات، حتى لا يتجاوز حدود الوقت في الاستضافة المشتركة.
 * الارتفاع غير متوفر في أغلب العراق (-1) فنفترض 7 م (طابقان)، ويُفضَّل ارتفاع OSM إن وُجد.
 */
final class MsBuildings
{
    public const Z = 9;
    public const DEFAULT_H = 7.0;
    private const INDEX = 'https://minedbuildings.z5.web.core.windows.net/global-buildings/dataset-links.csv';

    public static function dir(): string
    {
        $d = SS_APP_DIR . '/data/msb';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }

    public static function quadkey(float $lat, float $lon, int $z = self::Z): string
    {
        $x = ($lon + 180) / 360;
        $s = sin(deg2rad(max(-85.05, min(85.05, $lat))));
        $y = 0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI);
        $n = 1 << $z;
        $tx = (int)min($n - 1, max(0, floor($x * $n)));
        $ty = (int)min($n - 1, max(0, floor($y * $n)));
        $q = '';
        for ($i = $z; $i > 0; $i--) {
            $m = 1 << ($i - 1);
            $q .= (string)((($tx & $m) ? 1 : 0) + (($ty & $m) ? 2 : 0));
        }
        return $q;
    }

    /** @return string[] */
    public static function quadkeysFor(float $s, float $w, float $n, float $e): array
    {
        $out = [];
        foreach ([[$s, $w], [$s, $e], [$n, $w], [$n, $e], [($s + $n) / 2, ($w + $e) / 2]] as [$la, $lo]) $out[self::quadkey($la, $lo)] = true;
        return array_map('strval', array_keys($out));   // PHP يحوّل مفاتيح الأرقام إلى int
    }

    /** حدود مربع quadkey [s, w, n, e] */
    public static function bounds(string $qk): array
    {
        $tx = 0; $ty = 0; $z = strlen($qk);
        for ($i = 0; $i < $z; $i++) { $d = (int)$qk[$i]; $tx = ($tx << 1) | ($d & 1); $ty = ($ty << 1) | (($d >> 1) & 1); }
        $n = 1 << $z;
        $lon = function ($x) use ($n) { return $x / $n * 360 - 180; };
        $lat = function ($y) use ($n) { return rad2deg(atan(sinh(M_PI * (1 - 2 * $y / $n)))); };
        return [$lat($ty + 1), $lon($tx), $lat($ty), $lon($tx + 1)];
    }

    /** روابط ملفات المربع من فهرس Microsoft (يُخزَّن محلياً ويُحدَّث شهرياً) @return string[] */
    public static function urlsFor(string $qk): array
    {
        $f = self::dir() . '/dataset-links.csv';
        if (!is_file($f) || time() - (int)filemtime($f) > 30 * 86400) {
            $csv = Net::get(self::INDEX, 120);
            if (strpos($csv, 'QuadKey') === false) throw new RuntimeException('فهرس Microsoft غير صالح');
            file_put_contents("$f.tmp", $csv); rename("$f.tmp", $f);
        }
        $urls = [];
        $h = fopen($f, 'rb');
        while (($line = fgets($h)) !== false) {
            $p = explode(',', rtrim($line));
            if (count($p) >= 3 && $p[1] === $qk && strpos($p[2], 'https://minedbuildings.') === 0) $urls[] = $p[2];
        }
        fclose($h);
        return array_values(array_unique($urls));
    }

    /**
     * يتأكد من استيراد المربعات. يعيد [pending, none]:
     * pending = مربعات لم يكتمل استيرادها بعد، none = مربعات لا تغطيها بيانات Microsoft.
     * @return array{0:int,1:int}
     */
    public static function ensure(array $qks, float $budget, array &$log): array
    {
        $t0 = microtime(true);
        $pending = 0; $none = 0;
        foreach ($qks as $qk) {
            $st = self::state($qk);
            if ($st['status'] === 'ok') continue;
            if ($st['status'] === 'none') { $none++; continue; }
            if ($st['status'] === 'error' && time() - $st['t'] < 1800) { $pending++; continue; }
            $left = $budget - (microtime(true) - $t0);
            if ($left < 5) { $pending++; continue; }
            try {
                $done = self::import($qk, $left, $log);
                if (!$done) $pending++;
                elseif (self::state($qk)['status'] === 'none') $none++;
            } catch (Throwable $e) {
                self::save($qk, ['status' => 'error', 't' => time(), 'err' => substr($e->getMessage(), 0, 200)] + self::state($qk));
                $log[] = "ms $qk error: " . substr($e->getMessage(), 0, 120);
                $pending++;
            }
        }
        return [$pending, $none];
    }

    /** استيراد قابل للاستئناف. يعيد true عند اكتمال المربع */
    private static function import(string $qk, float $budget, array &$log): bool
    {
        $t0 = microtime(true);
        $st = self::state($qk);
        if (empty($st['urls'])) {
            $urls = self::urlsFor($qk);
            if (!$urls) { self::save($qk, ['status' => 'none', 't' => time()]); self::mark($qk, 'none', 0); $log[] = "ms $qk: لا تغطية"; return true; }
            $st = ['status' => 'importing', 't' => time(), 'urls' => $urls, 'part' => 0, 'line' => 0, 'count' => 0];
            self::save($qk, $st);
        }
        $db = ss_db_alive();
        while ($st['part'] < count($st['urls'])) {
            $gz = self::dir() . "/$qk-{$st['part']}.csv.gz";
            if (!is_file($gz)) {
                $data = Net::get($st['urls'][$st['part']], 240);
                file_put_contents("$gz.tmp", $data); rename("$gz.tmp", $gz);
                $db = ss_db_alive();
                $log[] = "ms $qk part {$st['part']}: downloaded " . round(strlen($data) / 1048576, 1) . ' MB';
            }
            $h = gzopen($gz, 'rb');
            if (!$h) throw new RuntimeException('تعذّر فتح ملف gz');
            for ($i = 0; $i < $st['line'] && !gzeof($h); $i++) gzgets($h, 1 << 20);   // تخطّي ما استُورد سابقاً
            $rows = []; $base = (int)$qk * 1000000 + $st['part'] * 100000000000000;
            $ins = null;
            while (!gzeof($h)) {
                $line = gzgets($h, 1 << 20);
                if ($line === false) break;
                $idx = $st['line']++;
                $f = json_decode($line, true);
                if (!is_array($f) || ($f['geometry']['type'] ?? '') !== 'Polygon') continue;
                $ring = $f['geometry']['coordinates'][0] ?? [];
                if (count($ring) < 4) continue;
                $pts = []; $mnA = 90; $mxA = -90; $mnO = 180; $mxO = -180;
                foreach ($ring as $c) {
                    $la = round((float)$c[1], 6); $lo = round((float)$c[0], 6);
                    $pts[] = [$la, $lo];
                    if ($la < $mnA) $mnA = $la; if ($la > $mxA) $mxA = $la; if ($lo < $mnO) $mnO = $lo; if ($lo > $mxO) $mxO = $lo;
                }
                $hgt = (float)($f['properties']['height'] ?? -1);
                $rows[] = [$base + $idx, "ms:$qk", $mnA, $mnO, $mxA, $mxO, $hgt > 1 ? $hgt : self::DEFAULT_H, $hgt > 1 ? 'ms' : 'ms_def', json_encode($pts)];
                if (count($rows) >= 400) {
                    self::insert($rows); $st['count'] += count($rows); $rows = [];
                    if (microtime(true) - $t0 > $budget) break;
                }
            }
            $eof = gzeof($h);
            gzclose($h);
            if ($rows) { self::insert($rows); $st['count'] += count($rows); }
            if (!$eof) { $st['t'] = time(); self::save($qk, $st); $log[] = "ms $qk: {$st['count']} مبنى حتى الآن"; return false; }
            @unlink($gz);
            $st['part']++; $st['line'] = 0;
            self::save($qk, $st);
        }
        self::save($qk, ['status' => 'ok', 't' => time(), 'count' => $st['count']]);
        self::mark($qk, 'ok', (int)$st['count']);
        // إعادة تحليل القراءات في نطاق المربع بعد وصول مبانيه
        [$s, $w, $n, $e] = self::bounds($qk);
        ss_db_alive()->prepare('DELETE l FROM sample_loss l JOIN samples s ON s.id = l.sample_id WHERE s.lat BETWEEN ? AND ? AND s.lon BETWEEN ? AND ?')
            ->execute([$s - 0.02, $n + 0.02, $w - 0.02, $e + 0.02]);
        $log[] = "ms $qk: اكتمل ({$st['count']} مبنى)";
        return true;
    }

    private static function insert(array $rows): void
    {
        $ph = '(' . implode(',', array_fill(0, 9, '?')) . ')';
        ss_db_alive()->prepare('INSERT IGNORE INTO buildings (id, tile, min_lat, min_lon, max_lat, max_lon, height_m, height_src, poly) VALUES '
            . implode(',', array_fill(0, count($rows), $ph)))->execute(array_merge(...$rows));
    }

    private static function mark(string $qk, string $status, int $n): void
    {
        ss_db_alive()->prepare('REPLACE INTO osm_tiles (tile, fetched_at, buildings, status) VALUES (?, UTC_TIMESTAMP(), ?, ?)')->execute(["ms:$qk", $n, $status]);
    }

    private static function state(string $qk): array
    {
        $f = self::dir() . "/$qk.state.json";
        $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($s) ? $s + ['status' => 'new', 't' => 0] : ['status' => 'new', 't' => 0];
    }

    private static function save(string $qk, array $s): void
    {
        file_put_contents(self::dir() . "/$qk.state.json", json_encode($s));
    }

    /** @return array<int,array{qk:string,status:string,count:int}> */
    public static function statusAll(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.state.json') ?: [] as $f) {
            $s = json_decode((string)file_get_contents($f), true) ?: [];
            $out[] = ['qk' => basename($f, '.state.json'), 'status' => (string)($s['status'] ?? '?'), 'count' => (int)($s['count'] ?? 0)];
        }
        return $out;
    }
}
