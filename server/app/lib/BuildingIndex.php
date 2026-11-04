<?php
declare(strict_types=1);

/** فهرس مكاني بسيط للمباني: شبكة 0.0005° (~50 م) + اختبار نقطة داخل مضلع */
final class BuildingIndex
{
    private const G = 0.0005;
    /** @var array<string,int[]> */
    private $grid = [];
    /** @var array */
    private $b;

    public function __construct(array $buildings)
    {
        $this->b = $buildings;
        foreach ($buildings as $i => $bd) {
            [$s, $w, $n, $e] = $bd['bb'];
            for ($a = (int)floor($s / self::G); $a <= (int)floor($n / self::G); $a++)
                for ($c = (int)floor($w / self::G); $c <= (int)floor($e / self::G); $c++) $this->grid["$a:$c"][] = $i;
        }
    }

    /** المبنى الذي يحوي النقطة أو null */
    public function at(float $lat, float $lon): ?array
    {
        $k = (int)floor($lat / self::G) . ':' . (int)floor($lon / self::G);
        if (empty($this->grid[$k])) return null;
        foreach ($this->grid[$k] as $i) {
            $bd = $this->b[$i];
            if ($lat < $bd['bb'][0] || $lat > $bd['bb'][2] || $lon < $bd['bb'][1] || $lon > $bd['bb'][3]) continue;
            if (is_string($bd['poly'])) $this->b[$i]['poly'] = $bd['poly'] = json_decode($bd['poly'], true) ?: [];
            if (self::inPoly($lat, $lon, $bd['poly'])) return $bd;
        }
        return null;
    }

    /** أقصر مسافة (م) من نقطة داخل المضلع إلى حافته @param array|string $poly */
    public static function edgeDistance(float $lat, float $lon, $poly): float
    {
        if (is_string($poly)) $poly = json_decode($poly, true) ?: [];
        $kx = 111320 * cos(deg2rad($lat)); $ky = 111320;
        $best = INF; $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $ax = ($poly[$j][1] - $lon) * $kx; $ay = ($poly[$j][0] - $lat) * $ky;
            $bx = ($poly[$i][1] - $lon) * $kx; $by = ($poly[$i][0] - $lat) * $ky;
            $dx = $bx - $ax; $dy = $by - $ay; $L = $dx * $dx + $dy * $dy;
            $t = $L > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $L)) : 0.0;
            $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
        }
        return is_finite($best) ? $best : 0.0;
    }

    private static function inPoly(float $y, float $x, array $p): bool
    {
        $in = false; $n = count($p);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = $p[$i][0]; $xi = $p[$i][1]; $yj = $p[$j][0]; $xj = $p[$j][1];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $in = !$in;
        }
        return $in;
    }
}
