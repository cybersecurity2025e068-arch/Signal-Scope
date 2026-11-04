<?php
declare(strict_types=1);

/**
 * ارتفاعات سطح الأرض من SRTM (ملفات .hgt بدقة 1″ أو 3″).
 * تُنزَّل مرة واحدة لكل مربع درجة من AWS Terrain Tiles (مجانية، بلا مفتاح) وتُحفظ في data/srtm.
 * القراءة مباشرة بـ fseek دون تحميل الملف في الذاكرة.
 */
final class Dem
{
    /** @var array<string,array{fh:resource|null,n:int}> */
    private static $open = [];

    public static function dir(): string
    {
        $d = SS_APP_DIR . '/data/srtm';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }

    public static function tileName(float $lat, float $lon): string
    {
        $la = (int)floor($lat); $lo = (int)floor($lon);
        return ($la >= 0 ? 'N' : 'S') . sprintf('%02d', abs($la)) . ($lo >= 0 ? 'E' : 'W') . sprintf('%03d', abs($lo));
    }

    /** ينزّل المربع إن لم يكن موجوداً. يعيد true إن صار متاحاً. */
    public static function ensure(float $lat, float $lon): bool
    {
        $t = self::tileName($lat, $lon);
        $f = self::dir() . "/$t.hgt";
        if (is_file($f)) return true;
        $fail = "$f.fail";
        if (is_file($fail) && time() - (int)@filemtime($fail) < 86400) return false;   // محاولة واحدة يومياً
        $url = 'https://s3.amazonaws.com/elevation-tiles-prod/skadi/' . substr($t, 0, 3) . "/$t.hgt.gz";
        try {
            $gz = Net::get($url, 180);
            $raw = @gzdecode($gz);
            if ($raw === false || !in_array(strlen($raw), [3601 * 3601 * 2, 1201 * 1201 * 2], true)) throw new RuntimeException('ملف SRTM غير صالح');
            file_put_contents("$f.tmp", $raw);
            rename("$f.tmp", $f);
            @unlink($fail);
            return true;
        } catch (Throwable $e) {
            @file_put_contents($fail, $e->getMessage());
            // مربع بحري أو خارج التغطية: AWS يعيد 404، فنعامله كبحر (ارتفاع 0)
            return false;
        }
    }

    /** الارتفاع بالمتر (استيفاء ثنائي الخطية)، أو null إن لم يتوفر المربع */
    public static function elevation(float $lat, float $lon): ?float
    {
        $t = self::tileName($lat, $lon);
        if (!isset(self::$open[$t])) {
            $f = self::dir() . "/$t.hgt";
            if (!is_file($f)) { self::$open[$t] = ['fh' => null, 'n' => 0]; }
            else {
                $sz = filesize($f);
                self::$open[$t] = ['fh' => fopen($f, 'rb'), 'n' => $sz === 3601 * 3601 * 2 ? 3601 : 1201];
            }
        }
        $o = self::$open[$t];
        if (!$o['fh']) return null;
        $n = $o['n'];
        $fy = (1.0 - ($lat - floor($lat))) * ($n - 1);
        $fx = ($lon - floor($lon)) * ($n - 1);
        $r = (int)floor($fy); $c = (int)floor($fx);
        $r = min($r, $n - 2); $c = min($c, $n - 2);
        $dy = $fy - $r; $dx = $fx - $c;
        $h00 = self::read($o['fh'], $n, $r, $c);     $h01 = self::read($o['fh'], $n, $r, $c + 1);
        $h10 = self::read($o['fh'], $n, $r + 1, $c); $h11 = self::read($o['fh'], $n, $r + 1, $c + 1);
        $vals = [$h00, $h01, $h10, $h11];
        foreach ($vals as $v) if ($v === null) { $ok = array_filter($vals, function ($x) { return $x !== null; }); return $ok ? array_sum($ok) / count($ok) : null; }
        return ($h00 * (1 - $dx) + $h01 * $dx) * (1 - $dy) + ($h10 * (1 - $dx) + $h11 * $dx) * $dy;
    }

    /** @param resource $fh */
    private static function read($fh, int $n, int $r, int $c): ?float
    {
        fseek($fh, ($r * $n + $c) * 2);
        $b = fread($fh, 2);
        if ($b === false || strlen($b) < 2) return null;
        $v = unpack('n', $b)[1];
        if ($v >= 32768) $v -= 65536;           // big-endian signed
        return $v === -32768 ? null : (float)$v;  // فراغ بيانات
    }
}
