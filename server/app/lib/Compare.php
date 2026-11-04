<?php
declare(strict_types=1);

/**
 * تقارير المقارنة بين مجموعتين من القراءات (A و B): قبل/بعد، مشغّل/مشغّل، وقت/وقت، أو مخصص.
 *
 * المقارنة العادلة: الفرق الإجمالي قد يعكس اختلاف المكان لا الشبكة، لذلك نقارن أيضاً
 * «المربعات المشتركة» فقط (مربعات الخريطة التي فيها قراءات من المجموعتين)، ونختبر دلالة الفرق
 * باختبار الإشارة (binomial sign test) على فروق المربعات.
 * مقاييس الإشارة من RSRP (LTE و5G) فقط حتى لا تُخلط مقاييس 2G/3G المختلفة.
 */
final class Compare
{
    public const MIN_PER_SQUARE = 3;   // أقل عدد قراءات لكل مجموعة في المربع
    public const SAME_DB = 3.0;        // فرق أقل من هذا = «متقارب»

    /** @return array<string,mixed> */
    public static function run(array $A, array $B, array $shared): array
    {
        $size = max(50, min(1000, (int)($shared['size'] ?? 150)));
        $latMid = (float)(ss_db()->query('SELECT AVG(lat) FROM samples WHERE lat IS NOT NULL')->fetchColumn() ?: 30.5);
        $dLat = $size / 111320;
        $dLon = $dLat / max(0.1, cos(deg2rad($latMid)));

        $ga = self::group($A, $shared, $dLat, $dLon);
        $gb = self::group($B, $shared, $dLat, $dLon);

        // المربعات المشتركة
        $features = []; $diffs = []; $improved = 0; $worse = 0; $same = 0;
        foreach ($ga['grid'] as $k => $a) {
            if (!isset($gb['grid'][$k])) continue;
            $b = $gb['grid'][$k];
            if ($a['n'] < self::MIN_PER_SQUARE || $b['n'] < self::MIN_PER_SQUARE) continue;
            $d = $b['r'] - $a['r'];
            $diffs[] = $d;
            if ($d >= self::SAME_DB) $improved++; elseif ($d <= -self::SAME_DB) $worse++; else $same++;
            [$gy, $gx] = array_map('intval', explode(':', $k));
            $y0 = $gy * $dLat; $x0 = $gx * $dLon;
            $features[] = ['type' => 'Feature',
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[round($x0, 6), round($y0, 6)], [round($x0 + $dLon, 6), round($y0, 6)],
                    [round($x0 + $dLon, 6), round($y0 + $dLat, 6)], [round($x0, 6), round($y0 + $dLat, 6)], [round($x0, 6), round($y0, 6)]]]],
                'properties' => ['d' => round($d, 1), 'ra' => round($a['r'], 1), 'rb' => round($b['r'], 1), 'na' => $a['n'], 'nb' => $b['n'],
                    'lat' => round($y0 + $dLat / 2, 6), 'lon' => round($x0 + $dLon / 2, 6)]];
        }
        sort($diffs);
        $nz = array_values(array_filter($diffs, function ($d) { return abs($d) > 1e-9; }));
        $pos = count(array_filter($nz, function ($d) { return $d > 0; }));
        $paired = [
            'squares' => count($diffs), 'size_m' => $size,
            'median_diff' => $diffs ? round(self::pct($diffs, 0.5), 1) : null,
            'mean_diff' => $diffs ? round(array_sum($diffs) / count($diffs), 1) : null,
            'improved' => $improved, 'worse' => $worse, 'same' => $same,
            'p_value' => count($nz) ? round(self::signTest($pos, count($nz)), 4) : null,
        ];
        unset($ga['grid'], $gb['grid']);
        return ['a' => $ga, 'b' => $gb, 'paired' => $paired, 'grid' => ['type' => 'FeatureCollection', 'features' => $features],
                'notes' => ['metric' => 'RSRP (LTE/5G)', 'min_per_square' => self::MIN_PER_SQUARE, 'same_db' => self::SAME_DB]];
    }

    /** @return array<string,mixed> */
    private static function group(array $g, array $shared, float $dLat, float $dLon): array
    {
        $db = ss_db();
        $args = [];
        $w = self::where($g, $shared, $args);

        $st = $db->prepare("SELECT COUNT(*) n, SUM(tech = 'NONE' OR tech IS NULL) nosvc, COUNT(DISTINCT session_id) sessions,
                COUNT(DISTINCT cell_ref) cells, COUNT(DISTINCT device_id) devices, MIN(ts) t0, MAX(ts) t1, SUM(lat IS NOT NULL) located
            FROM samples s WHERE $w");
        $st->execute($args);
        $c = $st->fetch();
        $n = (int)$c['n'];

        $vals = function (string $col) use ($db, $w, $args) {
            $q = $db->prepare("SELECT $col FROM samples s WHERE $w AND $col IS NOT NULL ORDER BY $col LIMIT 400000");
            $q->execute($args);
            return array_map('floatval', $q->fetchAll(PDO::FETCH_COLUMN));
        };
        $rsrp = $vals('s.rsrp');
        $sinr = $vals('s.sinr');
        $rsrq = $vals('s.rsrq');

        // توزيع الجودة والمدرج التكراري (كل 2 dB)
        $buckets = array_fill_keys(['excellent', 'good', 'fair', 'poor', 'bad'], 0);
        $hist = [];
        foreach ($rsrp as $v) {
            $buckets[Reports::bucket((int)$v)]++;
            $bin = (int)(floor($v / 2) * 2);
            $hist[$bin] = ($hist[$bin] ?? 0) + 1;
        }
        ksort($hist);
        $hn = max(1, count($rsrp));
        $histOut = [];
        foreach ($hist as $bin => $cnt) $histOut[] = [$bin + 1, round(100 * $cnt / $hn, 2)];   // مركز الصندوق، النسبة %

        // حسب ساعة اليوم (بتوقيت بغداد)
        $q = $db->prepare("SELECT MOD(FLOOR(s.ts / 3600000) + 3, 24) h, COUNT(*) n, AVG(s.rsrp) r, AVG(s.sinr) si
            FROM samples s WHERE $w AND s.rsrp IS NOT NULL GROUP BY h ORDER BY h");
        $q->execute($args);
        $hours = [];
        foreach ($q as $r) $hours[] = ['h' => (int)$r['h'], 'n' => (int)$r['n'], 'rsrp' => round((float)$r['r'], 1), 'sinr' => $r['si'] === null ? null : round((float)$r['si'], 1)];

        // الشبكة المكانية للمقارنة الزوجية
        $q = $db->prepare("SELECT FLOOR(s.lat / ?) gy, FLOOR(s.lon / ?) gx, COUNT(*) n, AVG(s.rsrp) r
            FROM samples s WHERE $w AND s.lat IS NOT NULL AND s.rsrp IS NOT NULL GROUP BY gy, gx");
        $q->execute(array_merge([$dLat, $dLon], $args));
        $grid = [];
        foreach ($q as $r) $grid[(int)$r['gy'] . ':' . (int)$r['gx']] = ['n' => (int)$r['n'], 'r' => (float)$r['r']];

        $good = $buckets['excellent'] + $buckets['good'];
        return [
            'filters' => $g,
            'samples' => $n, 'sessions' => (int)$c['sessions'], 'devices' => (int)$c['devices'], 'cells' => (int)$c['cells'],
            'located' => (int)$c['located'],
            'from' => $c['t0'] === null ? null : (int)$c['t0'], 'to' => $c['t1'] === null ? null : (int)$c['t1'],
            'no_service_pct' => $n ? round(100 * (int)$c['nosvc'] / $n, 1) : null,
            'rsrp' => Reports::stat($rsrp), 'sinr' => Reports::stat($sinr), 'rsrq' => Reports::stat($rsrq),
            'buckets' => $buckets, 'good_pct' => count($rsrp) ? round(100 * $good / count($rsrp), 1) : null,
            'hist' => $histOut, 'hours' => $hours, 'grid' => $grid,
            'obstacles' => self::obstacles($w, $args),
        ];
    }

    /** شروط المجموعة + الشروط المشتركة. الأعمدة بالبادئة s. */
    private static function where(array $g, array $shared, array &$a): string
    {
        $w = ['1=1'];
        $f = $g + $shared;   // شروط المجموعة تتقدم على المشتركة
        if (!empty($f['session'])) { $w[] = 's.session_id = ?'; $a[] = (int)$f['session']; }
        if (!empty($f['device'])) { $w[] = 's.device_id = ?'; $a[] = (int)$f['device']; }
        if (!empty($f['mcc'])) { $w[] = 's.mcc = ?'; $a[] = (int)$f['mcc']; }
        if (isset($f['mnc']) && $f['mnc'] !== '' && $f['mnc'] !== null) { $w[] = 's.mnc = ?'; $a[] = (int)$f['mnc']; }
        if (!empty($f['tech'])) { $w[] = 's.tech = ?'; $a[] = strtoupper((string)$f['tech']); }
        if (!empty($f['from'])) { $w[] = 's.ts >= ?'; $a[] = (int)$f['from']; }
        if (!empty($f['to'])) { $w[] = 's.ts <= ?'; $a[] = (int)$f['to']; }
        if (isset($f['hour_from'], $f['hour_to']) && $f['hour_from'] !== '' && $f['hour_to'] !== '') {
            $h0 = max(0, min(23, (int)$f['hour_from'])); $h1 = max(0, min(23, (int)$f['hour_to']));
            $hx = 'MOD(FLOOR(s.ts / 3600000) + 3, 24)';
            // مدى يعبر منتصف الليل (مثلاً 22 إلى 4)
            $w[] = $h0 <= $h1 ? "$hx BETWEEN $h0 AND $h1" : "($hx >= $h0 OR $hx <= $h1)";
        }
        if (!empty($shared['bbox'])) {
            $b = array_map('floatval', explode(',', (string)$shared['bbox']));
            if (count($b) === 4) { $w[] = 's.lon BETWEEN ? AND ? AND s.lat BETWEEN ? AND ?'; array_push($a, $b[0], $b[2], $b[1], $b[3]); }
        }
        return implode(' AND ', $w);
    }

    private static function obstacles(string $w, array $args): array
    {
        try { return Obstacles::summary($w, $args); } catch (Throwable $e) { return ['n' => 0]; }
    }

    private static function pct(array $sorted, float $q): float
    {
        $n = count($sorted);
        $i = $q * ($n - 1); $lo = (int)floor($i); $hi = min($n - 1, $lo + 1);
        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($i - $lo);
    }

    /** اختبار الإشارة ثنائي الطرف: احتمال أن يكون k موجباً من n بالصدفة (p = 0.5) */
    public static function signTest(int $k, int $n): float
    {
        if ($n <= 0) return 1.0;
        $k = min($k, $n - $k);
        if ($n > 1000) {   // تقريب طبيعي مع تصحيح الاستمرارية
            $z = (abs($n / 2 - $k) - 0.5) / sqrt($n / 4);
            return min(1.0, 2 * 0.5 * self::erfc($z / M_SQRT2));
        }
        $lg = function ($x) { return self::lgamma($x); };
        $p = 0.0;
        for ($i = 0; $i <= $k; $i++) $p += exp($lg($n + 1) - $lg($i + 1) - $lg($n - $i + 1) - $n * M_LN2);
        return min(1.0, 2 * $p);
    }

    private static function lgamma(float $x): float
    {   // Lanczos
        $g = [76.18009172947146, -86.50532032941677, 24.01409824083091, -1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5];
        $y = $x; $t = $x + 5.5; $t -= ($x + 0.5) * log($t); $s = 1.000000000190015;
        foreach ($g as $c) $s += $c / ++$y;
        return -$t + log(2.5066282746310005 * $s / $x);
    }

    private static function erfc(float $x): float
    {   // Numerical Recipes
        $z = abs($x); $t = 1 / (1 + 0.5 * $z);
        $r = $t * exp(-$z * $z - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418 + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));
        return $x >= 0 ? $r : 2 - $r;
    }
}
