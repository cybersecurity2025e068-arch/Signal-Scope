<?php
declare(strict_types=1);

final class Reports
{
    /** فئات جودة RSRP (dBm) */
    public const BUCKETS = [
        ['key' => 'excellent', 'label' => 'ممتاز',   'min' => -80],
        ['key' => 'good',      'label' => 'جيد',     'min' => -90],
        ['key' => 'fair',      'label' => 'متوسط',   'min' => -100],
        ['key' => 'poor',      'label' => 'ضعيف',    'min' => -110],
        ['key' => 'bad',       'label' => 'ضعيف جداً', 'min' => -999],
    ];

    public static function bucket(?int $rsrp): string
    {
        if ($rsrp === null) return 'none';
        foreach (self::BUCKETS as $b) if ($rsrp >= $b['min']) return $b['key'];
        return 'bad';
    }

    public static function overview(): array
    {
        $db = ss_db();
        $q = fn(string $sql) => $db->query($sql)->fetchColumn();
        $since = (int)((microtime(true) - 86400) * 1000);
        return [
            'devices'        => (int)$q('SELECT COUNT(*) FROM devices WHERE revoked = 0'),
            'sessions'       => (int)$q('SELECT COUNT(*) FROM sessions'),
            'samples'        => (int)$q('SELECT COUNT(*) FROM samples'),
            'samples_24h'    => (int)$db->query("SELECT COUNT(*) FROM samples WHERE ts > $since")->fetchColumn(),
            'cells'          => (int)$q('SELECT COUNT(*) FROM cells'),
            'cells_located'  => (int)$q("SELECT COUNT(*) FROM cells WHERE est_lat IS NOT NULL AND est_method <> 'insufficient'"),
            'operators'      => array_map(fn($r) => array_map('intval', $r),
                $db->query('SELECT mcc, mnc, COUNT(*) cells, COALESCE(SUM(sample_count), 0) samples FROM cells GROUP BY mcc, mnc ORDER BY samples DESC')->fetchAll()),
            'version'        => SS_VERSION,
        ];
    }

    public static function sessions(?int $deviceId, int $limit, int $offset): array
    {
        $db = ss_db();
        $where = $deviceId ? 'WHERE s.device_id = ' . (int)$deviceId : '';
        $rows = $db->query("SELECT s.id, s.uuid, s.name, s.started_at, s.ended_at, s.sample_count, s.first_ts, s.last_ts,
                d.id device_id, d.name device_name
            FROM sessions s JOIN devices d ON d.id = s.device_id $where
            ORDER BY s.started_at DESC LIMIT $limit OFFSET $offset")->fetchAll();
        foreach ($rows as &$r) foreach (['id', 'sample_count', 'device_id'] as $k) $r[$k] = (int)$r[$k];
        return $rows;
    }

    public static function sessionRow(int $id): array
    {
        $st = ss_db()->prepare('SELECT s.*, d.name device_name, d.model device_model FROM sessions s JOIN devices d ON d.id = s.device_id WHERE s.id = ?');
        $st->execute([$id]);
        $s = $st->fetch();
        if (!$s) throw new HttpError(404, 'الجلسة غير موجودة', 'not_found');
        return $s;
    }

    public static function sessionSamples(int $id): array
    {
        $st = ss_db()->prepare('SELECT ts, lat, lon, acc, speed, tech, mcc, mnc, tac, cid, pci, arfcn, rsrp, rsrq, sinr, rssi, ta, cell_ref
            FROM samples WHERE session_id = ? ORDER BY ts');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /** تقرير الجلسة: ملخص، توزيع الجودة، التبديلات، الخلايا، التشخيصات */
    public static function sessionReport(int $id): array
    {
        $s = self::sessionRow($id);
        $rows = self::sessionSamples($id);
        $n = count($rows);

        $dist = 0.0; $prev = null; $handovers = []; $prevCell = null;
        $buckets = array_fill_keys(array_merge(array_column(self::BUCKETS, 'key'), ['none']), 0);
        $rsrp = []; $sinr = []; $rsrq = []; $techs = []; $cells = [];
        $gaps = 0; $prevTs = null;

        foreach ($rows as $r) {
            $rv = $r['rsrp'] !== null ? (int)$r['rsrp'] : null;
            $noService = $r['tech'] === null || $r['tech'] === 'NONE';
            $buckets[$noService ? 'none' : self::bucket($rv)]++;
            if ($rv !== null) $rsrp[] = $rv;
            if ($r['sinr'] !== null) $sinr[] = (float)$r['sinr'];
            if ($r['rsrq'] !== null) $rsrq[] = (int)$r['rsrq'];
            if ($r['tech']) $techs[$r['tech']] = ($techs[$r['tech']] ?? 0) + 1;

            if ($r['lat'] !== null && (float)$r['acc'] <= 50) {
                if ($prev) {
                    $d = Geo::dist((float)$prev['lat'], (float)$prev['lon'], (float)$r['lat'], (float)$r['lon']);
                    if ($d < 500) $dist += $d; // تجاهل القفزات الكبيرة في GPS
                }
                $prev = $r;
            }
            if ($prevTs !== null && $r['ts'] - $prevTs > 30000) $gaps++;
            $prevTs = (int)$r['ts'];

            if ($r['cell_ref']) {
                $c = (int)$r['cell_ref'];
                $cells[$c] ??= ['cell_ref' => $c, 'tech' => $r['tech'], 'cid' => (int)$r['cid'], 'pci' => $r['pci'] !== null ? (int)$r['pci'] : null, 'samples' => 0, 'rsrp_sum' => 0, 'rsrp_n' => 0];
                $cells[$c]['samples']++;
                if ($rv !== null) { $cells[$c]['rsrp_sum'] += $rv; $cells[$c]['rsrp_n']++; }
                if ($prevCell !== null && $prevCell !== $c) {
                    $handovers[] = ['ts' => (int)$r['ts'], 'from' => $prevCell, 'to' => $c, 'lat' => $r['lat'], 'lon' => $r['lon'], 'rsrp' => $rv];
                }
                $prevCell = $c;
            }
        }

        $cellList = array_values(array_map(function ($c) {
            $c['avg_rsrp'] = $c['rsrp_n'] ? round($c['rsrp_sum'] / $c['rsrp_n'], 1) : null;
            unset($c['rsrp_sum'], $c['rsrp_n']);
            return $c;
        }, $cells));
        usort($cellList, fn($a, $b) => $b['samples'] <=> $a['samples']);

        // ping-pong: عودة لخلية سابقة خلال أقل من 10 ثوانٍ
        $pingpong = 0;
        for ($i = 1; $i < count($handovers); $i++) {
            if ($handovers[$i]['to'] === $handovers[$i - 1]['from'] && $handovers[$i]['ts'] - $handovers[$i - 1]['ts'] < 10000) $pingpong++;
        }

        $duration = $n ? ((int)$rows[$n - 1]['ts'] - (int)$rows[0]['ts']) / 1000 : 0;
        $stats = [
            'samples'      => $n,
            'duration_s'   => $duration,
            'distance_m'   => round($dist),
            'rsrp'         => self::stat($rsrp),
            'rsrq'         => self::stat($rsrq),
            'sinr'         => self::stat($sinr),
            'buckets'      => $buckets,
            'no_service_pct' => $n ? round(100 * $buckets['none'] / $n, 1) : 0,
            'techs'        => $techs,
            'handovers'    => count($handovers),
            'pingpong'     => $pingpong,
            'gaps'         => $gaps,
            'cells'        => count($cellList),
        ];

        return [
            'obstacles'   => Obstacles::summary('s.session_id = ?', [$id]),
            'session'     => self::castSession($s),
            'stats'       => $stats,
            'cells'       => $cellList,
            'handovers'   => array_slice($handovers, 0, 500),
            'diagnostics' => self::diagnose($stats),
            'bucket_defs' => self::BUCKETS,
        ];
    }

    private static function castSession(array $s): array
    {
        foreach (['id', 'device_id', 'sample_count'] as $k) $s[$k] = (int)$s[$k];
        foreach (['started_at', 'ended_at', 'first_ts', 'last_ts'] as $k) $s[$k] = $s[$k] === null ? null : (int)$s[$k];
        return $s;
    }

    /** تشخيصات نصية مبنية على عتبات معروفة في هندسة الشبكات الخلوية */
    public static function diagnose(array $st): array
    {
        $out = [];
        $r = $st['rsrp']; $q = $st['sinr'];
        if ($st['samples'] === 0) return [['level' => 'info', 'text' => 'لا توجد قراءات في هذه الجلسة.']];
        if ($st['no_service_pct'] >= 5) $out[] = ['level' => 'bad', 'text' => "انقطاع الخدمة في {$st['no_service_pct']}% من القراءات."];
        if ($r['p10'] !== null && $r['p10'] < -110) $out[] = ['level' => 'bad', 'text' => "10% من القراءات أضعف من {$r['p10']} dBm: تغطية ضعيفة جداً في أجزاء من المسار."];
        elseif ($r['median'] !== null && $r['median'] < -100) $out[] = ['level' => 'warn', 'text' => "الوسيط {$r['median']} dBm: التغطية ضعيفة عموماً."];
        if ($q['median'] !== null && $r['median'] !== null && $q['median'] < 3 && $r['median'] > -95) {
            $out[] = ['level' => 'warn', 'text' => "الإشارة قوية (وسيط RSRP {$r['median']}) لكن SINR منخفض ({$q['median']} dB): مؤشر تداخل من خلايا مجاورة لا ضعف تغطية."];
        }
        if ($st['handovers'] > 0 && $st['pingpong'] / max(1, $st['handovers']) > 0.3) {
            $out[] = ['level' => 'warn', 'text' => "{$st['pingpong']} من {$st['handovers']} تبديلات من نوع ذهاب وإياب سريع (ping-pong): حدود خلايا غير مستقرة."];
        }
        if ($st['gaps'] > 0) $out[] = ['level' => 'info', 'text' => "{$st['gaps']} فجوة في التسجيل أطول من 30 ثانية (قد يكون الهاتف أوقف التطبيق)."];
        if (!$out) $out[] = ['level' => 'good', 'text' => 'لا مشكلات واضحة في هذه الجلسة.'];
        return $out;
    }

    public static function stat(array $v): array
    {
        $n = count($v);
        if (!$n) return ['n' => 0, 'min' => null, 'max' => null, 'avg' => null, 'median' => null, 'p10' => null, 'p90' => null];
        sort($v);
        $p = fn(float $q) => $v[(int)min($n - 1, floor($q * ($n - 1) + 0.5))];
        return ['n' => $n, 'min' => $v[0], 'max' => $v[$n - 1], 'avg' => round(array_sum($v) / $n, 1),
                'median' => $p(0.5), 'p10' => $p(0.1), 'p90' => $p(0.9)];
    }

    public static function sessionGeoJson(int $id): array
    {
        $features = [];
        foreach (self::sessionSamples($id) as $r) {
            if ($r['lat'] === null) continue;
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round((float)$r['lon'], 6), round((float)$r['lat'], 6)]],
                'properties' => [
                    'ts' => (int)$r['ts'], 'tech' => $r['tech'],
                    'rsrp' => $r['rsrp'] === null ? null : (int)$r['rsrp'],
                    'rsrq' => $r['rsrq'] === null ? null : (int)$r['rsrq'],
                    'sinr' => $r['sinr'] === null ? null : round((float)$r['sinr'], 1),
                    'pci' => $r['pci'] === null ? null : (int)$r['pci'],
                    'cid' => $r['cid'] === null ? null : (int)$r['cid'],
                    'cell_ref' => $r['cell_ref'] === null ? null : (int)$r['cell_ref'],
                    'acc' => $r['acc'] === null ? null : round((float)$r['acc']),
                ],
            ];
        }
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    public static function sessionCsv(int $id): void
    {
        $s = self::sessionRow($id);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="session-' . $id . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM حتى يفتحه Excel بالترميز الصحيح
        fputcsv($out, ['time_utc', 'ts_ms', 'lat', 'lon', 'acc_m', 'speed_mps', 'tech', 'mcc', 'mnc', 'tac', 'cid', 'pci', 'arfcn', 'rsrp', 'rsrq', 'sinr', 'rssi', 'ta']);
        foreach (self::sessionSamples($id) as $r) {
            fputcsv($out, [gmdate('Y-m-d H:i:s', intdiv((int)$r['ts'], 1000)), $r['ts'], $r['lat'], $r['lon'], $r['acc'], $r['speed'],
                $r['tech'], $r['mcc'], $r['mnc'], $r['tac'], $r['cid'], $r['pci'], $r['arfcn'], $r['rsrp'], $r['rsrq'], $r['sinr'], $r['rssi'], $r['ta']]);
        }
        fclose($out);
        exit;
    }

    public static function cells(array $f): array
    {
        $w = ['1=1']; $a = [];
        if (!empty($f['mcc'])) { $w[] = 'mcc = ?'; $a[] = (int)$f['mcc']; }
        if (isset($f['mnc']) && $f['mnc'] !== '') { $w[] = 'mnc = ?'; $a[] = (int)$f['mnc']; }
        if (!empty($f['tech'])) { $w[] = 'tech = ?'; $a[] = strtoupper((string)$f['tech']); }
        $st = ss_db()->prepare('SELECT id, tech, mcc, mnc, tac, cid, pci, arfcn, first_seen, last_seen, sample_count, avg_rsrp,
                est_lat, est_lon, est_radius_m, est_n, est_method, est_exponent, est_updated, ocid_lat, ocid_lon, ocid_range
            FROM cells WHERE ' . implode(' AND ', $w) . ' ORDER BY sample_count DESC LIMIT 5000');
        $st->execute($a);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) self::castCell($r);
        return $rows;
    }

    private static function castCell(array &$r): void
    {
        foreach (['id', 'mcc', 'mnc', 'tac', 'cid', 'pci', 'arfcn', 'first_seen', 'last_seen', 'sample_count', 'est_n', 'ocid_range'] as $k) {
            if (array_key_exists($k, $r) && $r[$k] !== null) $r[$k] = (int)$r[$k];
        }
        foreach (['avg_rsrp', 'est_lat', 'est_lon', 'est_radius_m', 'est_exponent', 'ocid_lat', 'ocid_lon'] as $k) {
            if (array_key_exists($k, $r) && $r[$k] !== null) $r[$k] = (float)$r[$k];
        }
        if (isset($r['cid']) && $r['tech'] === 'LTE') {
            $r['enb'] = $r['cid'] >> 8;           // رقم المحطة (eNodeB)
            $r['sector'] = $r['cid'] & 0xFF;     // رقم القطاع
        }
    }

    /** تقرير الخلية: الموقع المقدّر، منطقة الخدمة الفعلية، الجودة حسب المسافة */
    public static function cellReport(int $id): array
    {
        $db = ss_db();
        $st = $db->prepare('SELECT * FROM cells WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) throw new HttpError(404, 'الخلية غير موجودة', 'not_found');
        self::castCell($c);
        unset($c['dirty']);

        $st = $db->prepare('SELECT lat, lon, rsrp, sinr, ta, ts FROM samples WHERE cell_ref = ? AND lat IS NOT NULL ORDER BY ts DESC LIMIT 20000');
        $st->execute([$id]);
        $pts = $st->fetchAll();

        // نقاط الخدمة مجمّعة في مربعات ~50 m للخريطة
        $grid = [];
        foreach ($pts as $p) {
            $k = round((float)$p['lat'] / 0.00045) . ':' . round((float)$p['lon'] / 0.00055);
            $g = &$grid[$k];
            $g ??= ['lat' => 0.0, 'lon' => 0.0, 'r' => 0, 'rn' => 0, 'n' => 0];
            $g['lat'] += (float)$p['lat']; $g['lon'] += (float)$p['lon']; $g['n']++;
            if ($p['rsrp'] !== null) { $g['r'] += (int)$p['rsrp']; $g['rn']++; }
            unset($g);
        }
        $footprint = [];
        foreach ($grid as $g) {
            $footprint[] = ['lat' => round($g['lat'] / $g['n'], 6), 'lon' => round($g['lon'] / $g['n'], 6), 'n' => $g['n'],
                            'rsrp' => $g['rn'] ? round($g['r'] / $g['rn'], 1) : null];
        }

        // الجودة حسب المسافة من الموقع المقدّر
        $byDist = [];
        if ($c['est_lat'] !== null) {
            foreach ($pts as $p) {
                if ($p['rsrp'] === null) continue;
                $d = Geo::dist($c['est_lat'], $c['est_lon'], (float)$p['lat'], (float)$p['lon']);
                $k = (int)min(20, floor($d / 250));
                $byDist[$k] ??= ['from_m' => $k * 250, 'n' => 0, 'sum' => 0];
                $byDist[$k]['n']++; $byDist[$k]['sum'] += (int)$p['rsrp'];
            }
            ksort($byDist);
            $byDist = array_values(array_map(fn($b) => ['from_m' => $b['from_m'], 'n' => $b['n'], 'avg_rsrp' => round($b['sum'] / $b['n'], 1)], $byDist));
        }

        $maxDist = 0.0;
        if ($c['est_lat'] !== null) foreach ($footprint as $f) $maxDist = max($maxDist, Geo::dist($c['est_lat'], $c['est_lon'], $f['lat'], $f['lon']));

        return [
            'cell'      => $c,
            'stats'     => [
                'rsrp' => self::stat(array_map('intval', array_filter(array_column($pts, 'rsrp'), fn($v) => $v !== null))),
                'sinr' => self::stat(array_map('floatval', array_filter(array_column($pts, 'sinr'), fn($v) => $v !== null))),
                'max_service_distance_m' => round($maxDist),
                'ta_samples' => count(array_filter(array_column($pts, 'ta'), fn($v) => $v !== null)),
            ],
            'footprint' => $footprint,
            'by_distance' => $byDist,
            'ocid_offset_m' => ($c['est_lat'] !== null && $c['ocid_lat'] !== null)
                ? round(Geo::dist($c['est_lat'], $c['est_lon'], $c['ocid_lat'], $c['ocid_lon'])) : null,
        ];
    }

    /** خريطة التغطية: شبكة مربعات بمتوسط RSRP وSINR */
    public static function coverage(array $f): array
    {
        $cell = max(50, min(2000, (int)($f['size'] ?? 150))); // متر
        $dLat = $cell / 111320;
        $w = ['lat IS NOT NULL', 'rsrp IS NOT NULL']; $a = [];
        if (!empty($f['bbox'])) {
            $b = array_map('floatval', explode(',', (string)$f['bbox']));
            if (count($b) === 4) { $w[] = 'lon BETWEEN ? AND ? AND lat BETWEEN ? AND ?'; array_push($a, $b[0], $b[2], $b[1], $b[3]); }
        }
        if (!empty($f['mcc'])) { $w[] = 'mcc = ?'; $a[] = (int)$f['mcc']; }
        if (isset($f['mnc']) && $f['mnc'] !== '') { $w[] = 'mnc = ?'; $a[] = (int)$f['mnc']; }
        if (!empty($f['tech'])) { $w[] = 'tech = ?'; $a[] = strtoupper((string)$f['tech']); }
        if (!empty($f['from'])) { $w[] = 'ts >= ?'; $a[] = (int)$f['from']; }
        if (!empty($f['to'])) { $w[] = 'ts <= ?'; $a[] = (int)$f['to']; }
        if (!empty($f['hour_from']) || !empty($f['hour_to'])) {
            // ساعات بتوقيت بغداد (UTC+3)
            $w[] = 'MOD(FLOOR(ts / 3600000) + 3, 24) BETWEEN ? AND ?';
            $a[] = (int)($f['hour_from'] ?? 0); $a[] = (int)($f['hour_to'] ?? 23);
        }
        // عرض الخلية بالطول يُصحَّح بخط العرض المتوسط
        $latMid = 33.3;
        if (!empty($b) && count($b) === 4) $latMid = ($b[1] + $b[3]) / 2;
        $dLon = $dLat / max(0.1, cos(deg2rad($latMid)));

        $sql = 'SELECT FLOOR(lat / ?) gy, FLOOR(lon / ?) gx, COUNT(*) n, AVG(rsrp) rsrp, AVG(sinr) sinr, MIN(rsrp) rmin
                FROM samples WHERE ' . implode(' AND ', $w) . ' GROUP BY gy, gx LIMIT 50000';
        $st = ss_db()->prepare($sql);
        $st->execute(array_merge([$dLat, $dLon], $a));
        $features = [];
        foreach ($st as $r) {
            $y0 = $r['gy'] * $dLat; $x0 = $r['gx'] * $dLon;
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                    [round($x0, 6), round($y0, 6)], [round($x0 + $dLon, 6), round($y0, 6)],
                    [round($x0 + $dLon, 6), round($y0 + $dLat, 6)], [round($x0, 6), round($y0 + $dLat, 6)], [round($x0, 6), round($y0, 6)],
                ]]],
                'properties' => ['n' => (int)$r['n'], 'rsrp' => round((float)$r['rsrp'], 1),
                                 'sinr' => $r['sinr'] === null ? null : round((float)$r['sinr'], 1), 'rsrp_min' => (int)$r['rmin']],
            ];
        }
        return ['type' => 'FeatureCollection', 'features' => $features, 'cell_m' => $cell];
    }

    public static function devices(): array
    {
        $rows = ss_db()->query('SELECT d.id, d.name, d.model, d.android, d.app_version, d.created_at, d.last_seen, d.revoked,
                (SELECT COUNT(*) FROM sessions s WHERE s.device_id = d.id) sessions
            FROM devices d ORDER BY d.revoked, d.last_seen DESC')->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['revoked'] = (bool)$r['revoked']; $r['sessions'] = (int)$r['sessions']; }
        return $rows;
    }
}
