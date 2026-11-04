<?php
declare(strict_types=1);

/**
 * المباني من OpenStreetMap عبر Overpass API، تُجلب لكل مربع 0.02° مرة واحدة وتُخزَّن في MySQL.
 * الارتفاع: الوسم height، وإلا building:levels × 3.2 م، وإلا 7 م افتراضياً.
 */
final class Osm
{
    public const TILE = 0.02;
    public const DEFAULT_H = 7.0;
    private const ENDPOINTS = ['https://overpass-api.de/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];

    public static function tileKey(float $lat, float $lon): string
    {
        return (int)floor($lat / self::TILE) . ':' . (int)floor($lon / self::TILE);
    }

    /** @return string[] مفاتيح المربعات التي تغطي المستطيل */
    public static function tilesFor(float $s, float $w, float $n, float $e): array
    {
        $out = [];
        for ($a = (int)floor($s / self::TILE); $a <= (int)floor($n / self::TILE); $a++)
            for ($b = (int)floor($w / self::TILE); $b <= (int)floor($e / self::TILE); $b++) $out[] = "$a:$b";
        return $out;
    }

    /** يجلب المربعات الناقصة. يعيد عدد المربعات التي ما زالت ناقصة (0 = الكل جاهز). */
    public static function ensure(array $tiles, int $maxFetch, array &$log): int
    {
        if (!$tiles) return 0;
        $db = ss_db();
        $in = implode(',', array_fill(0, count($tiles), '?'));
        $st = $db->prepare("SELECT tile, status, fetched_at FROM osm_tiles WHERE tile IN ($in)");
        $st->execute($tiles);
        $have = [];
        foreach ($st as $r) {
            // المحاولات الفاشلة تُعاد بعد 6 ساعات
            if ($r['status'] === 'ok' || strtotime($r['fetched_at'] . ' UTC') > time() - 6 * 3600) $have[$r['tile']] = $r['status'];
        }
        $missing = array_values(array_filter($tiles, function ($t) use ($have) { return !isset($have[$t]); }));
        $fetched = 0;
        foreach ($missing as $t) {
            if ($fetched >= $maxFetch) break;
            $fetched++;
            try {
                $n = self::fetchTile($t);
                $log[] = "osm $t: $n";
            } catch (Throwable $e) {
                $db->prepare('REPLACE INTO osm_tiles (tile, fetched_at, buildings, status) VALUES (?, UTC_TIMESTAMP(), 0, ?)')->execute([$t, 'error']);
                $log[] = "osm $t error: " . substr($e->getMessage(), 0, 120);
            }
        }
        $pending = 0;
        foreach ($tiles as $t) if (!isset($have[$t])) $pending++;
        return max(0, $pending - $fetched);
    }

    private static function fetchTile(string $t): int
    {
        [$a, $b] = array_map('intval', explode(':', $t));
        $s = $a * self::TILE; $w = $b * self::TILE; $n = $s + self::TILE; $e = $w + self::TILE;
        $q = sprintf('[out:json][timeout:90];way["building"](%.5f,%.5f,%.5f,%.5f);out tags geom;', $s, $w, $n, $e);
        $json = null; $last = null;
        foreach (self::ENDPOINTS as $ep) {
            try { $json = Net::post($ep, 'data=' . rawurlencode($q), 120); break; } catch (Throwable $ex) { $last = $ex; }
        }
        if ($json === null) throw $last ?: new RuntimeException('overpass');
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['elements'])) throw new RuntimeException('رد Overpass غير صالح');
        $db = ss_db();
        $db->beginTransaction();
        $ins = $db->prepare('INSERT IGNORE INTO buildings (id, tile, min_lat, min_lon, max_lat, max_lon, height_m, height_src, poly) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $cnt = 0;
        foreach ($d['elements'] as $el) {
            if (($el['type'] ?? '') !== 'way' || empty($el['geometry']) || count($el['geometry']) < 4) continue;
            $pts = [];
            $mnA = 90; $mxA = -90; $mnO = 180; $mxO = -180;
            foreach ($el['geometry'] as $g) {
                $la = round((float)$g['lat'], 6); $lo = round((float)$g['lon'], 6);
                $pts[] = [$la, $lo];
                $mnA = min($mnA, $la); $mxA = max($mxA, $la); $mnO = min($mnO, $lo); $mxO = max($mxO, $lo);
            }
            [$h, $src] = self::height($el['tags'] ?? []);
            $ins->execute([(int)$el['id'], $t, $mnA, $mnO, $mxA, $mxO, $h, $src, json_encode($pts)]);
            $cnt++;
        }
        $db->prepare('REPLACE INTO osm_tiles (tile, fetched_at, buildings, status) VALUES (?, UTC_TIMESTAMP(), ?, ?)')->execute([$t, $cnt, 'ok']);
        $db->commit();
        return $cnt;
    }

    /** @return array{0:float,1:string} */
    public static function height(array $tags): array
    {
        if (isset($tags['height']) && preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(m)?\s*$/', (string)$tags['height'], $m)) {
            $h = (float)$m[1]; if ($h >= 2 && $h <= 400) return [$h, 'height'];
        }
        if (isset($tags['building:levels']) && is_numeric($tags['building:levels'])) {
            $l = (float)$tags['building:levels']; if ($l >= 1 && $l <= 120) return [$l * 3.2 + 1.0, 'levels'];
        }
        return [self::DEFAULT_H, 'default'];
    }

    /** @return array<int,array{id:int,h:float,bb:array,poly:array}> */
    public static function inBox(float $s, float $w, float $n, float $e): array
    {
        $st = ss_db()->prepare('SELECT id, height_m, min_lat, min_lon, max_lat, max_lon, poly FROM buildings
            WHERE max_lat >= ? AND min_lat <= ? AND max_lon >= ? AND min_lon <= ? LIMIT 200000');
        $st->execute([$s, $n, $w, $e]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['id' => (int)$r['id'], 'h' => (float)$r['height_m'],
                      'bb' => [(float)$r['min_lat'], (float)$r['min_lon'], (float)$r['max_lat'], (float)$r['max_lon']],
                      'poly' => json_decode($r['poly'], true)];
        }
        return $out;
    }
}
