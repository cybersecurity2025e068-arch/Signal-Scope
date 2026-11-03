<?php
declare(strict_types=1);

final class Geo
{
    public const R = 6371008.8;

    public static function dist(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
        $dp = $p2 - $p1; $dl = deg2rad($lon2 - $lon1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * self::R * asin(min(1.0, sqrt($a)));
    }

    /** إسقاط محلي مسطّح (دقيق لبضعة كيلومترات) حول نقطة مرجعية */
    public static function toLocal(float $lat, float $lon, float $lat0, float $lon0): array
    {
        $x = deg2rad($lon - $lon0) * self::R * cos(deg2rad($lat0));
        $y = deg2rad($lat - $lat0) * self::R;
        return [$x, $y];
    }

    public static function fromLocal(float $x, float $y, float $lat0, float $lon0): array
    {
        $lat = $lat0 + rad2deg($y / self::R);
        $lon = $lon0 + rad2deg($x / (self::R * cos(deg2rad($lat0))));
        return [$lat, $lon];
    }

    /** مسافة Timing Advance بالمتر حسب التقنية */
    public static function taMeters(?string $tech, ?int $ta): ?float
    {
        if ($ta === null) return null;
        switch ($tech) {
            case 'LTE': return $ta * 78.12;
            case 'GSM': return $ta * 553.85;
            default:    return null; // TA في NR يعتمد على التباعد الترددي وغير موحّد في Android
        }
    }

    /** فقد المسار في الفضاء الحر (dB)، d بالمتر و f بالميغاهرتز */
    public static function fspl(float $d, float $fMHz): float
    {
        return 20 * log10(max($d, 1) / 1000) + 20 * log10($fMHz) + 32.44;
    }

    /** تردد الهابط التقريبي من EARFCN (LTE) — للنطاقات الشائعة في العراق */
    public static function earfcnToMHz(?int $earfcn): ?float
    {
        if ($earfcn === null) return null;
        $bands = [ // [N_offs, F_low, N_low, N_high]
            [0, 2110, 0, 599],        // B1
            [1200, 1805, 1200, 1949], // B3
            [2750, 2620, 2750, 3449], // B7
            [3450, 925, 3450, 3799],  // B8
            [6150, 791, 6150, 6449],  // B20
            [9210, 758, 9210, 9659],  // B28
            [37750, 2570, 37750, 38249], // B38
            [38650, 2300, 38650, 39649], // B40
            [39650, 2496, 39650, 41589], // B41
        ];
        foreach ($bands as [$off, $fl, $lo, $hi]) {
            if ($earfcn >= $lo && $earfcn <= $hi) return $fl + 0.1 * ($earfcn - $off);
        }
        return null;
    }
}
