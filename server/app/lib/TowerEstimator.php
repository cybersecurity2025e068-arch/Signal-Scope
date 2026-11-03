<?php
declare(strict_types=1);

/**
 * تقدير موقع البرج (الخلية) من القياسات.
 *
 * الطريقة:
 *  1. تُجمَّع القراءات في مربعات ~20 m (متوسط RSRP لكل مربع) حتى لا يطغى
 *     الوقوف الطويل في مكان واحد على النتيجة.
 *  2. مركز موزون بقوة الإشارة كنقطة بداية.
 *  3. بحث شبكي حول المركز: لكل موقع مرشّح نحسب أفضل ملاءمة لنموذج فقد المسار
 *        RSRP = A - 10·n·log10(d)
 *     بالانحدار الخطي، مع قيد 1.6 ≤ n ≤ 5، ونضيف عقوبة على مخالفة مسافات
 *     Timing Advance إن وُجدت. الموقع ذو أقل خطأ هو التقدير.
 *  4. نصف قطر الثقة = أبعد مرشّح خطؤه ضمن حد إحصائي من الأفضل.
 *
 * النتيجة تقدير نموذجي؛ تتحسن بتنوع أماكن القياس حول البرج.
 */
final class TowerEstimator
{
    public const BIN_M = 20.0;

    /** @param array<int,array{lat:float,lon:float,rsrp:?int,ta:?int,tech:?string}> $pts */
    public static function estimate(array $pts): ?array
    {
        $pts = array_values(array_filter($pts, fn($p) => $p['lat'] !== null && $p['rsrp'] !== null));
        if (count($pts) < 3) {
            if (!$pts) return null;
            $p = $pts[0];
            return ['lat' => $p['lat'], 'lon' => $p['lon'], 'radius' => 2000.0, 'n' => count($pts),
                    'method' => 'insufficient', 'exponent' => null];
        }

        // --- 1) التجميع في مربعات ---
        $lat0 = array_sum(array_column($pts, 'lat')) / count($pts);
        $lon0 = array_sum(array_column($pts, 'lon')) / count($pts);
        $bins = [];
        foreach ($pts as $p) {
            [$x, $y] = Geo::toLocal($p['lat'], $p['lon'], $lat0, $lon0);
            $k = (int)floor($x / self::BIN_M) . ':' . (int)floor($y / self::BIN_M);
            $b = &$bins[$k];
            $b ??= ['x' => 0.0, 'y' => 0.0, 'r' => 0.0, 'c' => 0, 'ta' => []];
            $b['x'] += $x; $b['y'] += $y; $b['r'] += $p['rsrp']; $b['c']++;
            $tam = Geo::taMeters($p['tech'] ?? null, $p['ta'] ?? null);
            if ($tam !== null) $b['ta'][] = $tam;
            unset($b);
        }
        $B = [];
        foreach ($bins as $b) {
            $ta = null;
            if ($b['ta']) { sort($b['ta']); $ta = $b['ta'][intdiv(count($b['ta']), 2)]; }
            $B[] = ['x' => $b['x'] / $b['c'], 'y' => $b['y'] / $b['c'], 'r' => $b['r'] / $b['c'], 'ta' => $ta];
        }
        if (count($B) > 600) { // حد لزمن الحساب على الاستضافة المشتركة
            $stepK = count($B) / 600;
            $B = array_map(fn($i) => $B[(int)floor($i * $stepK)], range(0, 599));
        }
        $nb = count($B);

        // --- 2) المركز الموزون ---
        $sw = $sx = $sy = 0.0;
        foreach ($B as $b) {
            $w = 10 ** ($b['r'] / 20); // سعة خطية
            $sw += $w; $sx += $w * $b['x']; $sy += $w * $b['y'];
        }
        $cx = $sx / $sw; $cy = $sy / $sw;

        // انتشار النقاط (يحدد نطاق البحث)
        $spread = 0.0;
        foreach ($B as $b) $spread = max($spread, hypot($b['x'] - $cx, $b['y'] - $cy));

        if ($nb < 6) {
            [$la, $lo] = Geo::fromLocal($cx, $cy, $lat0, $lon0);
            return ['lat' => $la, 'lon' => $lo, 'radius' => max(300.0, $spread), 'n' => count($pts),
                    'method' => 'centroid', 'exponent' => null];
        }

        // --- 3) البحث الشبكي (خشن ثم دقيق) ---
        $range = min(5000.0, max(800.0, $spread * 1.5));
        $coarse = self::grid($B, $cx, $cy, $range, $range / 30);
        $best = $coarse['best'];
        $fine = self::grid($B, $best['x'], $best['y'], $range / 15, $range / 150);
        if ($fine['best']['cost'] <= $best['cost']) $best = $fine['best'];

        if ($best['n'] === null) {
            [$la, $lo] = Geo::fromLocal($cx, $cy, $lat0, $lon0);
            return ['lat' => $la, 'lon' => $lo, 'radius' => max(300.0, $spread), 'n' => count($pts),
                    'method' => 'centroid', 'exponent' => null];
        }

        // --- 4) نصف قطر الثقة: المرشحون ضمن الحد الإحصائي من الأفضل ---
        $dof = max(1, $nb - 4);
        $sigma2 = max(4.0, $best['cost'] / $dof);      // تباين البواقي (dB²)، حد أدنى 2 dB
        $limit = $best['cost'] + 6.0 * $sigma2;         // ≈ فاصل ثقة 95% لمعاملين
        $radius = 0.0;
        foreach ($coarse['all'] as $c) {
            if ($c['cost'] <= $limit) $radius = max($radius, hypot($c['x'] - $best['x'], $c['y'] - $best['y']));
        }
        $radius = max($radius, $range / 30, 50.0);

        [$la, $lo] = Geo::fromLocal($best['x'], $best['y'], $lat0, $lon0);
        $hasTa = count(array_filter($B, fn($b) => $b['ta'] !== null)) >= 3;
        return [
            'lat' => $la, 'lon' => $lo, 'radius' => round($radius, 1), 'n' => count($pts),
            'method' => $hasTa ? 'pathloss+ta' : 'pathloss',
            'exponent' => round($best['n'], 2),
        ];
    }

    private static function grid(array $B, float $cx, float $cy, float $range, float $step): array
    {
        $all = [];
        $best = ['cost' => INF, 'x' => $cx, 'y' => $cy, 'n' => null];
        $steps = (int)ceil($range / $step);
        for ($i = -$steps; $i <= $steps; $i++) {
            for ($j = -$steps; $j <= $steps; $j++) {
                $x = $cx + $i * $step; $y = $cy + $j * $step;
                $r = self::fit($B, $x, $y);
                $c = ['cost' => $r[0], 'x' => $x, 'y' => $y, 'n' => $r[1]];
                $all[] = $c;
                if ($c['cost'] < $best['cost']) $best = $c;
            }
        }
        return ['best' => $best, 'all' => $all];
    }

    /** ملاءمة RSRP = A + b·log10(d) لموقع مرشّح. يعيد [الخطأ, الأس n] */
    private static function fit(array $B, float $x, float $y): array
    {
        $n = count($B);
        $sl = $sr = $sll = $slr = 0.0;
        $L = [];
        foreach ($B as $k => $p) {
            $d = max(10.0, hypot($p['x'] - $x, $p['y'] - $y));
            $l = log10($d);
            $L[$k] = [$l, $d];
            $sl += $l; $sr += $p['r']; $sll += $l * $l; $slr += $l * $p['r'];
        }
        $den = $n * $sll - $sl * $sl;
        if ($den < 1e-9) return [INF, null];
        $b = ($n * $slr - $sl * $sr) / $den;
        // قيد الأس: 1.6 ≤ n ≤ 5  (b = -10n)
        $b = max(-50.0, min(-16.0, $b));
        $a = ($sr - $b * $sl) / $n;

        $sse = 0.0;
        foreach ($B as $k => $p) {
            $e = $p['r'] - ($a + $b * $L[$k][0]);
            $sse += $e * $e;
            if ($p['ta'] !== null) {
                // TA يعطي المسافة بدقة ±78 m تقريباً: نحوّل الفرق إلى ما يعادل dB
                $de = ($L[$k][1] - $p['ta']) / 156.0;
                $sse += 4.0 * $de * $de;
            }
        }
        return [$sse, -$b / 10];
    }
}
