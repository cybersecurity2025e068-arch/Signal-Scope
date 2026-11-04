<?php
declare(strict_types=1);

/**
 * تحليل العوائق: لكل قراءة نقدّر الفقد الزائد ونوزّعه على أسبابه.
 *
 * 1) الفقد الزائد excess = P0 − FSPL(d) − RSRP، حيث P0 = المئين 90 لـ (RSRP + FSPL) لقراءات الخلية
 *    (أي «أفضل مسار مقاس» لهذه الخلية)، و d المسافة إلى البرج المقدّر.
 * 2) التضاريس: مقطع الأرض (SRTM) بين البرج والهاتف، وأسوأ حافة بمعامل فرينل v ← حيود حافة السكين J(v) (ITU-R P.526).
 * 3) المباني: المباني (OSM) التي يقطعها المسار بارتفاعها ← J(v) لأعلى حافة + 1.5 dB لكل مبنى إضافي (حد 10 dB).
 * 4) داخل المبنى: إن كان الهاتف داخل مبنى ← الوسيط في ITU-R P.2109 (مبنى تقليدي).
 * 5) ما لا تفسّره النماذج = residual (خفوت، تداخل، أو نقص في البيانات).
 * إن تجاوز مجموع النماذج الفقدَ الزائد المقاس، تُوزَّع الحصص بالتناسب حتى يساويه.
 * النتيجة تقدير نموذجي وليست قياساً مباشراً للعائق.
 */
final class Obstacles
{
    public const TOWER_H = 30.0;   // ارتفاع الهوائي المفترض فوق الأرض (م)
    public const PHONE_H = 1.5;
    public const MAX_RADIUS = 1500.0;   // لا نحلل خلية تقدير موقعها أسوأ من هذا

    /** حيود حافة السكين، ITU-R P.526 المعادلة 31 */
    public static function J(float $v): float
    {
        if ($v <= -0.78) return 0.0;
        return 6.9 + 20 * log10(sqrt(($v - 0.1) ** 2 + 1) + $v - 0.1);
    }

    /** فقد دخول المبنى (وسيط 50%) لمبنى تقليدي عند زاوية ارتفاع 0، ITU-R P.2109 */
    public static function p2109(float $fGHz): float
    {
        $lf = log10(max(0.08, $fGHz));
        $A = 12.64 + 3.72 * $lf + 0.96 * $lf * $lf;
        $B = 9.1 - 3.0 * $lf;
        return 10 * log10(10 ** (0.1 * $A) + 10 ** (0.1 * $B) + 10 ** (-0.3));
    }

    public static function freqMHz(?string $tech, ?int $arfcn): float
    {
        if ($tech === 'LTE') { $f = Geo::earfcnToMHz($arfcn); if ($f) return $f; return 1800.0; }
        if ($tech === 'NR') {
            if ($arfcn !== null && $arfcn >= 600000) return 3000 + 0.015 * ($arfcn - 600000);
            if ($arfcn !== null && $arfcn > 0) return 0.005 * $arfcn;
            return 3500.0;
        }
        if ($tech === 'WCDMA') return 2100.0;
        if ($tech === 'GSM') return 900.0;
        return 1800.0;
    }

    /**
     * تشغيل دفعة تحليل ضمن ميزانية زمنية.
     * @return array<string,mixed>
     */
    public static function run(float $budget, array &$log): array
    {
        $t0 = microtime(true);
        $db = ss_db();
        $cells = $db->query("SELECT id, tech, est_lat, est_lon, est_radius_m FROM cells
            WHERE est_lat IS NOT NULL AND est_method IN ('pathloss','pathloss+ta','centroid')
              AND est_radius_m <= " . self::MAX_RADIUS . " ORDER BY last_seen DESC LIMIT 300")->fetchAll();
        $osmBudget = 6;
        $stats = ['cells' => 0, 'samples' => 0, 'skipped_osm' => 0, 'dem' => []];
        foreach ($cells as $c) {
            if (microtime(true) - $t0 > $budget) break;
            $cid = (int)$c['id']; $tLat = (float)$c['est_lat']; $tLon = (float)$c['est_lon'];
            $db = ss_db_alive();
            $qNew = $db->prepare('SELECT s.id, s.lat, s.lon, s.rsrp, s.rssi, s.arfcn, s.tech FROM samples s
            LEFT JOIN sample_loss l ON l.sample_id = s.id
            WHERE s.cell_ref = ? AND s.lat IS NOT NULL AND (s.rsrp IS NOT NULL OR s.rssi IS NOT NULL) AND l.sample_id IS NULL
                LIMIT 3000');
            $qAll = $db->prepare('SELECT lat, lon, rsrp, rssi, arfcn, tech FROM samples
            WHERE cell_ref = ? AND lat IS NOT NULL AND (rsrp IS NOT NULL OR rssi IS NOT NULL) ORDER BY ts DESC LIMIT 20000');
            $qNew->execute([$cid]);
            $rows = $qNew->fetchAll();
            if (!$rows) continue;

            // المستطيل المحيط بالبرج والقراءات
            $s = $tLat; $n = $tLat; $w = $tLon; $e = $tLon;
            foreach ($rows as $r) { $s = min($s, (float)$r['lat']); $n = max($n, (float)$r['lat']); $w = min($w, (float)$r['lon']); $e = max($e, (float)$r['lon']); }
            $s -= 0.002; $n += 0.002; $w -= 0.002; $e += 0.002;

            $pending = Osm::ensure(Osm::tilesFor($s, $w, $n, $e), $osmBudget, $log);
            if ($pending > 0) { $osmBudget = 0; $stats['skipped_osm']++; continue; }

            $demOK = true;
            foreach ([[$s, $w], [$s, $e], [$n, $w], [$n, $e]] as [$la, $lo]) {
                $tn = Dem::tileName($la, $lo);
                if (!isset($stats['dem'][$tn])) $stats['dem'][$tn] = Dem::ensure($la, $lo);
                $demOK = $demOK && $stats['dem'][$tn];
            }

            // التنزيلات الخارجية أعلاه قد تُسقط الاتصال: نعيد الاتصال ونحضّر الاستعلام من جديد
            $db = ss_db_alive();
            $qAll = $db->prepare('SELECT lat, lon, rsrp, rssi, arfcn, tech FROM samples
                WHERE cell_ref = ? AND lat IS NOT NULL AND (rsrp IS NOT NULL OR rssi IS NOT NULL) ORDER BY ts DESC LIMIT 20000');
            // P0: «أفضل مسار مقاس» للخلية
            $qAll->execute([$cid]);
            $vals = [];
            foreach ($qAll as $r) {
                $sig = $r['rsrp'] !== null ? (float)$r['rsrp'] : (float)$r['rssi'];
                $d = Geo::dist($tLat, $tLon, (float)$r['lat'], (float)$r['lon']);
                $vals[] = $sig + Geo::fspl(max($d, 20), self::freqMHz($r['tech'], $r['arfcn'] === null ? null : (int)$r['arfcn']));
            }
            sort($vals);
            $P0 = $vals[(int)floor(0.9 * (count($vals) - 1))];

            $idx = new BuildingIndex(Osm::inBox($s, $w, $n, $e));
            $cache = [];
            $ins = [];
            foreach ($rows as $r) {
                $la = (float)$r['lat']; $lo = (float)$r['lon'];
                $f = self::freqMHz($r['tech'], $r['arfcn'] === null ? null : (int)$r['arfcn']);
                $key = round($la, 4) . ':' . round($lo, 4) . ':' . (int)$f;   // ~10 م
                if (!isset($cache[$key])) $cache[$key] = self::path($tLat, $tLon, $la, $lo, $f, $idx, $demOK);
                $p = $cache[$key];
                $sig = $r['rsrp'] !== null ? (float)$r['rsrp'] : (float)$r['rssi'];
                $excess = max(0.0, $P0 - Geo::fspl(max($p['d'], 20), $f) - $sig);
                $sum = $p['terrain'] + $p['buildings'] + $p['indoor'];
                $k = ($sum > $excess && $sum > 0) ? $excess / $sum : 1.0;
                $te = $p['terrain'] * $k; $bu = $p['buildings'] * $k; $in = $p['indoor'] * $k;
                $res = max(0.0, $excess - $te - $bu - $in);
                $ins[] = [(int)$r['id'], $cid, round($p['d'], 1), round($excess, 2), round($te, 2), round($bu, 2), round($in, 2), round($res, 2), $p['nb'], $demOK ? 1 : 0];
                if (count($ins) >= 300) { self::flush($ins); $ins = []; }
                if (microtime(true) - $t0 > $budget + 20) break;
            }
            if ($ins) self::flush($ins);
            $stats['cells']++; $stats['samples'] += count($rows);
        }
        $stats['seconds'] = round(microtime(true) - $t0, 1);
        return $stats;
    }

    private static function flush(array $rows): void
    {
        ss_db_alive();
        $ph = '(' . implode(',', array_fill(0, 10, '?')) . ')';
        $st = ss_db()->prepare('INSERT IGNORE INTO sample_loss (sample_id, cell_ref, dist_m, excess, terrain, buildings, indoor, residual, n_buildings, terrain_ok) VALUES '
            . implode(',', array_fill(0, count($rows), $ph)));
        $st->execute(array_merge(...$rows));
    }

    /** @return array{d:float,terrain:float,buildings:float,indoor:float,nb:int} */
    public static function path(float $tLat, float $tLon, float $pLat, float $pLon, float $fMHz, BuildingIndex $idx, bool $demOK): array
    {
        $d = Geo::dist($tLat, $tLon, $pLat, $pLon);
        $phoneB = $idx->at($pLat, $pLon);
        $indoor = $phoneB ? self::p2109($fMHz / 1000) : 0.0;
        if ($d < 30) return ['d' => $d, 'terrain' => 0.0, 'buildings' => 0.0, 'indoor' => $indoor, 'nb' => 0];

        $lam = 299.792458 / $fMHz;
        $gT = $demOK ? (Dem::elevation($tLat, $tLon) ?? 0.0) : 0.0;
        $gR = $demOK ? (Dem::elevation($pLat, $pLon) ?? 0.0) : 0.0;
        $hT = $gT + self::TOWER_H; $hR = $gR + self::PHONE_H;
        $nSteps = (int)max(8, min(250, $d / 15));
        $vT = -9.0; $vB = -9.0; $crossed = [];
        for ($i = 1; $i < $nSteps; $i++) {
            $fr = $i / $nSteps; $di = $d * $fr;
            $la = $tLat + ($pLat - $tLat) * $fr; $lo = $tLon + ($pLon - $tLon) * $fr;
            $hl = $hT + ($hR - $hT) * $fr;
            $g = $demOK ? (Dem::elevation($la, $lo) ?? ($gT + ($gR - $gT) * $fr)) : 0.0;
            $k = sqrt(2 * $d / ($lam * $di * ($d - $di)));
            $vT = max($vT, ($g - $hl) * $k);
            if ($di > 20 && $d - $di > 8) {
                $b = $idx->at($la, $lo);
                if ($b && (!$phoneB || $b['id'] !== $phoneB['id'])) {
                    $v = ($g + $b['h'] - $hl) * $k;
                    if ($v > $vB) $vB = $v;
                    if ($v > -0.78) $crossed[$b['id']] = true;
                }
            }
        }
        $nb = count($crossed);
        $Lb = $vB > -0.78 ? self::J($vB) + min(10.0, 1.5 * max(0, $nb - 1)) : 0.0;
        return ['d' => $d, 'terrain' => self::J($vT), 'buildings' => $Lb, 'indoor' => $indoor, 'nb' => $nb];
    }

    /** إحصاءات مجمعة لأي مجموعة قراءات */
    public static function summary(string $where, array $args): array
    {
        $st = ss_db()->prepare("SELECT COUNT(*) n, AVG(l.excess) ex, AVG(l.terrain) te, AVG(l.buildings) bu, AVG(l.indoor) ind, AVG(l.residual) res,
                SUM(l.indoor > 0) n_indoor, SUM(l.n_buildings > 0) n_blocked, MAX(l.terrain_ok) dem
            FROM sample_loss l JOIN samples s ON s.id = l.sample_id WHERE $where");
        $st->execute($args);
        $r = $st->fetch();
        $n = (int)$r['n'];
        if (!$n) return ['n' => 0];
        $r2 = fn($v) => round((float)$v, 1);
        return ['n' => $n, 'excess' => $r2($r['ex']), 'terrain' => $r2($r['te']), 'buildings' => $r2($r['bu']),
                'indoor' => $r2($r['ind']), 'residual' => $r2($r['res']),
                'indoor_pct' => round(100 * (int)$r['n_indoor'] / $n, 1), 'blocked_pct' => round(100 * (int)$r['n_blocked'] / $n, 1),
                'terrain_data' => (bool)$r['dem']];
    }

    /** شبكة مربعات بمتوسط مكونات الفقد والسبب الغالب */
    public static function grid(array $f): array
    {
        $cell = max(50, min(2000, (int)($f['size'] ?? 150)));
        $dLat = $cell / 111320;
        $w = ['1=1']; $a = [];
        if (!empty($f['mcc'])) { $w[] = 's.mcc = ?'; $a[] = (int)$f['mcc']; }
        if (isset($f['mnc']) && $f['mnc'] !== '') { $w[] = 's.mnc = ?'; $a[] = (int)$f['mnc']; }
        $latMid = (float)(ss_db()->query('SELECT AVG(lat) FROM samples WHERE lat IS NOT NULL')->fetchColumn() ?: 30.5);
        $dLon = $dLat / max(0.1, cos(deg2rad($latMid)));
        $st = ss_db()->prepare('SELECT FLOOR(s.lat / ?) gy, FLOOR(s.lon / ?) gx, COUNT(*) n, AVG(l.excess) ex, AVG(l.terrain) te, AVG(l.buildings) bu,
                AVG(l.indoor) ind, AVG(l.residual) res, AVG(COALESCE(s.rsrp, s.rssi)) sig
            FROM sample_loss l JOIN samples s ON s.id = l.sample_id WHERE ' . implode(' AND ', $w) . ' GROUP BY gy, gx LIMIT 50000');
        $st->execute(array_merge([$dLat, $dLon], $a));
        $features = [];
        foreach ($st as $r) {
            $y0 = $r['gy'] * $dLat; $x0 = $r['gx'] * $dLon;
            $parts = ['terrain' => (float)$r['te'], 'buildings' => (float)$r['bu'], 'indoor' => (float)$r['ind'], 'residual' => (float)$r['res']];
            arsort($parts);
            $dom = (float)$r['ex'] < 3 ? 'clear' : (string)array_key_first($parts);
            $features[] = ['type' => 'Feature',
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[round($x0, 6), round($y0, 6)], [round($x0 + $dLon, 6), round($y0, 6)],
                    [round($x0 + $dLon, 6), round($y0 + $dLat, 6)], [round($x0, 6), round($y0 + $dLat, 6)], [round($x0, 6), round($y0, 6)]]]],
                'properties' => ['n' => (int)$r['n'], 'excess' => round((float)$r['ex'], 1), 'terrain' => round((float)$r['te'], 1),
                    'buildings' => round((float)$r['bu'], 1), 'indoor' => round((float)$r['ind'], 1), 'residual' => round((float)$r['res'], 1),
                    'signal' => round((float)$r['sig'], 1), 'dominant' => $dom,
                    'lat' => round($y0 + $dLat / 2, 6), 'lon' => round($x0 + $dLon / 2, 6)]];
        }
        return ['type' => 'FeatureCollection', 'features' => $features, 'cell_m' => $cell];
    }

    public static function status(): array
    {
        $db = ss_db();
        $q = fn(string $sql) => $db->query($sql)->fetchColumn();
        $dem = glob(Dem::dir() . '/*.hgt') ?: [];
        return [
            'analyzed' => (int)$q('SELECT COUNT(*) FROM sample_loss'),
            'samples_with_location' => (int)$q('SELECT COUNT(*) FROM samples WHERE lat IS NOT NULL'),
            'cells_eligible' => (int)$q("SELECT COUNT(*) FROM cells WHERE est_lat IS NOT NULL AND est_method IN ('pathloss','pathloss+ta','centroid') AND est_radius_m <= " . self::MAX_RADIUS),
            'osm_tiles' => (int)$q("SELECT COUNT(*) FROM osm_tiles WHERE status = 'ok'"),
            'osm_errors' => (int)$q("SELECT COUNT(*) FROM osm_tiles WHERE status <> 'ok'"),
            'buildings' => (int)$q('SELECT COUNT(*) FROM buildings'),
            'dem_tiles' => array_map('basename', $dem),
        ];
    }
}
