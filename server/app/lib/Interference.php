<?php
declare(strict_types=1);

/**
 * عزل التشويش: كم يخفض التشويش جودة الإشارة، ومن أين يأتي، وما علاجه.
 *
 * لكل قراءة LTE/5G:
 *  1) الضوضاء الحرارية لكل عنصر مورد (RE):  N = −174 + 10·log10(SCS) + NF   (NF الهاتف ≈ 7 dB)
 *  2) SINR المتوقع بلا تشويش = حد الضوضاء مع سقف أداء المستقبِل (يُعايَر لكل جهاز من قراءاته القوية):
 *        SINR_exp = 1 / (1/SNR + 1/SINR_max)،   SNR = RSRP − N
 *  3) تراجع SINR بسبب التشويش  deg = SINR_exp − SINR_measured
 *     وقدرة التشويش لكل RE:      I = S/SINR_meas − S/SINR_exp   (تُعرض بوحدة dBm/MHz)
 *     إن غاب SINR يُشتق من RSRQ بمنحنى يُعايَر لكل جهاز من قراءاته التي فيها القيمتان معاً
 *     (وإلا بالعلاقة النظرية بفرض حمل كامل، وهي الأكثر تحفظاً).
 *  4) التشويش المتوقع من الخلايا المجاورة على التردد نفسه (EARFCN):  I_nb = Σ RSRP_j  (حمل كامل = أسوأ حالة)
 *     ما لا تفسّره الخلايا المجاورة حتى بحملها الكامل = تشويش خارجي محتمل.
 *  5) التصنيف: نظيف · ضعف تغطية · ازدحام · تداخل خلايا · تصادم PCI · تلوث إشارة · خارجي · غير مفسَّر · مختلط
 *
 * مصادر التشويش الخارجي تُجمَّع في مناطق متجاورة، ويُقدَّر موقعها بملاءمة نموذج تضاؤل القدرة مع المسافة
 * (نفس طريقة تقدير الأبراج)، وقوتها المكافئة (EIRP) بإضافة فقد الفضاء الحر إلى القدرة المستلمة.
 * النتائج تقديرية من قياسات الهاتف وليست بديلاً عن محلل طيف، لكنها تحدد أين يُبحث وعن ماذا.
 */
final class Interference
{
    public const MODEL_VERSION = 2;
    /** حمل الخلايا المجاورة المفترض: 100% (أسوأ حالة) حتى لا يُنسب إلى «الخارجي» إلا ما لا تفسّره الشبكة حتى في ذروتها */
    public const NB_LOAD = 1.0;

    /** @return array<string,float|int> */
    public static function cfg(): array
    {
        $c = ss_config();
        return [
            'nf' => (float)($c['intf_nf_db'] ?? 7.0),
            'sinr_max' => (float)($c['intf_sinr_max'] ?? 25.0),
            'deg_min' => (float)($c['intf_deg_db'] ?? 3.0),
            'ext_min' => (float)($c['intf_ext_db'] ?? 6.0),
            'ext_rsrp' => (float)($c['intf_ext_rsrp'] ?? -105.0),
            'close_db' => (float)($c['intf_close_db'] ?? 6.0),
            'pilot_n' => (int)($c['intf_pilot_n'] ?? 3),
            'grid_m' => (int)($c['intf_grid_m'] ?? 150),
        ];
    }

    public const LABELS = [
        'clean' => 'إشارة نظيفة', 'coverage' => 'ضعف تغطية', 'load' => 'ازدحام الخلية',
        'cochannel' => 'تداخل خلايا مجاورة', 'mod3' => 'تصادم PCI mod 3', 'pilot' => 'تلوث الإشارة',
        'external' => 'تشويش خارجي', 'unexplained' => 'تشويش غير مفسَّر', 'mixed' => 'تشويش مختلط',
    ];
    public const INTERFERING = ['cochannel', 'mod3', 'pilot', 'external', 'unexplained', 'mixed'];

    // ------------------------------------------------------------------ التحليل لكل قراءة

    /** عرض عنصر المورد بالكيلوهرتز: 5G FR1 TDD (n77/n78) بتباعد 30 kHz، والبقية 15 kHz */
    public static function scsKHz(string $tech, ?int $arfcn): float
    {
        return ($tech === 'NR' && $arfcn !== null && $arfcn >= 600000) ? 30.0 : 15.0;
    }

    private static function lin(float $db): float { return 10 ** ($db / 10); }
    private static function db(float $x): float { return 10 * log10(max($x, 1e-30)); }

    /**
     * @param array<string,mixed> $s قراءة (tech, rsrp, rsrq, sinr, pci, arfcn, neighbors)
     * @return array<string,mixed>|null
     */
    public static function analyze(array $s, float $sinrMax, array $k, ?array $rqMap = null): ?array
    {
        $tech = (string)($s['tech'] ?? '');
        if (($tech !== 'LTE' && $tech !== 'NR') || $s['rsrp'] === null) return null;
        $rsrp = (float)$s['rsrp'];
        $arfcn = $s['arfcn'] === null ? null : (int)$s['arfcn'];
        $scs = self::scsKHz($tech, $arfcn);
        $N = -174 + 10 * log10($scs * 1000) + $k['nf'];
        $snr = $rsrp - $N;
        $sinrExp = self::db(1 / (1 / self::lin($snr) + 1 / self::lin($sinrMax)));

        if ($s['sinr'] !== null) { $sm = (float)$s['sinr']; $src = 's'; }
        elseif ($s['rsrq'] !== null) { $sm = min($sinrMax, self::sinrFromRsrq((float)$s['rsrq'], $rqMap)); $src = 'r'; }
        else return null;

        $deg = max(0.0, $sinrExp - $sm);
        $S = self::lin($rsrp);
        $I = $S / self::lin($sm) - $S / self::lin($sinrExp);
        $perMHz = 10 * log10(1000 / $scs);   // عدد عناصر المورد في 1 MHz
        $iDbm = $I > 0 ? self::db($I) + $perMHz : null;

        // الخلايا المجاورة على التردد نفسه
        $spci = $s['pci'] === null ? null : (int)$s['pci'];
        $nbs = is_string($s['neighbors'] ?? null) ? json_decode($s['neighbors'], true) : null;
        $hasNb = is_array($nbs) && $nbs;
        $Inb = 0.0; $nCo = 0; $nClose = 0; $agg = null;
        if ($hasNb && $arfcn !== null) {
            foreach ($nbs as $n) {
                if (!is_array($n) || ($n['tech'] ?? '') !== $tech) continue;
                $nf = $n['earfcn'] ?? $n['arfcn'] ?? null;
                $nr = $n['rsrp'] ?? null;
                if ($nf === null || (int)$nf !== $arfcn || !is_numeric($nr) || $nr < -156 || $nr > -31) continue;
                $npci = isset($n['pci']) && is_numeric($n['pci']) ? (int)$n['pci'] : null;
                if ($npci !== null && $npci === $spci) continue;   // الخلية الخادمة نفسها
                $gap = $rsrp - (float)$nr;
                $m3 = $npci !== null && $spci !== null && $npci % 3 === $spci % 3;
                $Inb += self::NB_LOAD * self::lin((float)$nr);
                $nCo++;
                if ($gap <= $k['close_db']) $nClose++;
                if ($agg === null || $gap < $agg['gap']) $agg = ['pci' => $npci, 'gap' => $gap, 'm3' => $m3];
            }
        }
        $nbDbm = $Inb > 0 ? self::db($Inb) + $perMHz : null;
        $Iext = $I - $Inb;
        $extDbm = $Iext > 0 ? self::db($Iext) + $perMHz : null;

        if ($deg < $k['deg_min']) {
            if ($s['sinr'] !== null && $s['rsrq'] !== null && (float)$s['rsrq'] <= -14 && $sm >= 10) $cls = 'load';
            elseif ($rsrp < -110) $cls = 'coverage';
            else $cls = 'clean';
        } elseif ($nClose >= $k['pilot_n']) $cls = 'pilot';
        elseif ($agg && $agg['m3'] && $agg['gap'] <= $k['close_db']) $cls = 'mod3';
        elseif ($I > 0 && $Inb >= 0.5 * $I) $cls = 'cochannel';
        // خارجي: تشويش قوي لا تفسّر الخلايا المرئية ثلثه حتى بحملها الكامل، مع إشارة خادمة جيدة
        // (عند حافة الخلية قد يأتي التشويش من خلايا بعيدة لا يبلّغ عنها الهاتف ← «غير مفسَّر»)
        elseif ($deg >= $k['ext_min'] && $hasNb && $rsrp >= $k['ext_rsrp'] && $Inb <= $I / 3) $cls = 'external';
        elseif ($deg >= $k['ext_min']) $cls = 'unexplained';
        else $cls = 'mixed';

        return ['cls' => $cls, 'src' => $src, 'sinr_meas' => round($sm, 1), 'sinr_exp' => round($sinrExp, 1), 'deg' => round($deg, 1),
            'i_dbm' => $iDbm === null ? null : round($iDbm, 1), 'nb_dbm' => $nbDbm === null ? null : round($nbDbm, 1),
            'ext_dbm' => $extDbm === null ? null : round($extDbm, 1), 'n_cochan' => min(255, $nCo), 'n_close' => min(255, $nClose),
            'agg_pci' => $agg['pci'] ?? null, 'agg_gap' => $agg === null ? null : round($agg['gap'], 1), 'agg_mod3' => $agg && $agg['m3'] ? 1 : 0];
    }

    /** SINR من RSRQ: منحنى الجهاز المعايَر (وسيط SINR لكل 1 dB من RSRQ)، وإلا العلاقة النظرية بحمل كامل */
    public static function sinrFromRsrq(float $rsrq, ?array $map): float
    {
        if ($map) {
            $b = (int)floor($rsrq);
            for ($d = 0; $d <= 2; $d++) {
                foreach ([$b - $d, $b + $d] as $x) if (isset($map[(string)$x])) return (float)$map[(string)$x];
            }
        }
        $den = 1 / self::lin($rsrq) - 12;
        return $den <= 0.01 ? 30.0 : self::db(12 / $den);
    }

    /**
     * منحنى RSRQ ← SINR لكل جهاز من قراءاته التي فيها القيمتان (30 قراءة على الأقل لكل 1 dB).
     * العلاقة النظرية تعتمد على حمل الخلية المجهول، فالمنحنى المقاس من الجهاز نفسه أدق.
     * @return array<int,array<string,float>>
     */
    public static function rsrqMaps(): array
    {
        $db = ss_db();
        $st = $db->prepare("SELECT FLOOR(rsrq) q, sinr FROM samples WHERE device_id = ? AND tech = 'LTE' AND sinr IS NOT NULL AND rsrq IS NOT NULL ORDER BY id DESC LIMIT 40000");
        $out = [];
        foreach ($db->query('SELECT id FROM devices')->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $st->execute([(int)$d]);
            $bins = [];
            foreach ($st as $r) $bins[(int)$r['q']][] = (float)$r['sinr'];
            $m = [];
            foreach ($bins as $q => $v) {
                if (count($v) < 30) continue;
                sort($v);
                $m[(string)$q] = round($v[intdiv(count($v), 2)], 1);
            }
            if (count($m) >= 4) $out[(int)$d] = $m;
        }
        return $out;
    }

    /**
     * سقف SINR لكل جهاز: المئين 95 لقراءات الإشارة القوية (RSRP > −90)، بين 18 و 32 dB.
     * المودمات تختلف: بعضها يتوقف عند 20 dB وبعضها عند 30 dB، ولولا هذه المعايرة لظهر سقف الجهاز كأنه تشويش.
     * @return array<int,float>
     */
    public static function calibrate(): array
    {
        $db = ss_db();
        $def = self::cfg()['sinr_max'];
        $out = [];
        $st = $db->prepare("SELECT sinr FROM samples WHERE device_id = ? AND tech IN ('LTE','NR') AND rsrp > -90 AND sinr IS NOT NULL ORDER BY id DESC LIMIT 5000");
        foreach ($db->query('SELECT id FROM devices')->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $st->execute([(int)$d]);
            $v = array_map('floatval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (count($v) < 200) { $out[(int)$d] = $def; continue; }
            sort($v);
            $out[(int)$d] = max(18.0, min(32.0, $v[(int)floor(0.95 * (count($v) - 1))]));
        }
        return $out;
    }

    // ------------------------------------------------------------------ التشغيل التدريجي

    /** @return array<string,mixed> */
    public static function run(float $budget): array
    {
        $t0 = microtime(true);
        $db = ss_db_alive();
        $dir = SS_APP_DIR . '/data';
        $vf = "$dir/intf.version"; $cf = "$dir/intf.cursor"; $calF = "$dir/intf.calib.json";
        $reset = (int)@file_get_contents($vf) !== self::MODEL_VERSION;
        // إعادة المعايرة يومياً؛ إن تغيّر سقف جهاز أكثر من 1 dB يُعاد التحليل من البداية
        $cal = is_file($calF) ? json_decode((string)file_get_contents($calF), true) : null;
        if (!is_array($cal) || time() - (int)($cal['at'] ?? 0) > 86400 || $reset) {
            $new = self::calibrate();
            $old = is_array($cal) ? ($cal['caps'] ?? []) : [];
            foreach ($new as $d => $v) if (isset($old[$d]) && abs((float)$old[$d] - $v) > 1.0) $reset = true;
            if (!$old) $reset = true;
            $cal = ['at' => time(), 'caps' => $new, 'rq' => self::rsrqMaps()];
            @file_put_contents($calF, json_encode($cal));
        }
        $caps = $cal['caps'] ?? []; $rq = $cal['rq'] ?? [];
        if ($reset) {
            $db->exec('DELETE FROM sample_intf');
            @file_put_contents($cf, '0');
            @file_put_contents($vf, (string)self::MODEL_VERSION);
        }
        $k = self::cfg();
        $last = (int)@file_get_contents($cf);
        $stats = ['analyzed' => 0, 'skipped' => 0, 'reset' => $reset];
        $q = $db->prepare('SELECT id, device_id, tech, rsrp, rsrq, sinr, pci, arfcn, neighbors FROM samples WHERE id > ? ORDER BY id LIMIT 2000');
        while (microtime(true) - $t0 < $budget) {
            $q->execute([$last]);
            $batch = $q->fetchAll();
            if (!$batch) break;
            $rows = [];
            foreach ($batch as $s) {
                $last = (int)$s['id'];
                $dv = (int)$s['device_id'];
                $r = self::analyze($s, (float)($caps[$dv] ?? $k['sinr_max']), $k, $rq[$dv] ?? null);
                if ($r === null) { $stats['skipped']++; continue; }
                $rows[] = array_merge([(int)$s['id']], array_values($r));
            }
            if ($rows) self::flush($rows);
            $stats['analyzed'] += count($rows);
            @file_put_contents($cf, (string)$last);
            if (count($batch) < 2000) {
                // قراءات حُذفت (حذف جلسة أو تنظيف دوري): نحذف نتائجها
                $db->exec('DELETE i FROM sample_intf i LEFT JOIN samples s ON s.id = i.sample_id WHERE s.id IS NULL');
                break;
            }
        }
        $stats['time'] = round(microtime(true) - $t0, 1);
        return $stats;
    }

    private static function flush(array $rows): void
    {
        $sql = 'REPLACE INTO sample_intf (sample_id, cls, src, sinr_meas, sinr_exp, deg, i_dbm, nb_dbm, ext_dbm, n_cochan, n_close, agg_pci, agg_gap, agg_mod3) VALUES ';
        $ph = '(' . implode(',', array_fill(0, 14, '?')) . ')';
        $st = ss_db()->prepare($sql . implode(',', array_fill(0, count($rows), $ph)));
        $st->execute(array_merge(...$rows));
    }

    // ------------------------------------------------------------------ التقارير

    /** شرط التصفية المشترك: المشغّل والفترة */
    private static function where(array $f): array
    {
        $w = ['1=1']; $a = [];
        if (!empty($f['mcc'])) { $w[] = 's.mcc = ?'; $a[] = (int)$f['mcc']; }
        if (isset($f['mnc']) && $f['mnc'] !== '') { $w[] = 's.mnc = ?'; $a[] = (int)$f['mnc']; }
        $days = (int)($f['days'] ?? 0);
        if ($days > 0) { $w[] = 's.ts >= ?'; $a[] = (int)(microtime(true) * 1000) - $days * 86400000; }
        if (!empty($f['device'])) { $w[] = 's.device_id = ?'; $a[] = (int)$f['device']; }
        return [implode(' AND ', $w), $a];
    }

    private static function rows(string $sql, array $a): array
    {
        $st = ss_db()->prepare($sql);
        $st->execute($a);
        return $st->fetchAll();
    }

    /** @return array<string,mixed> */
    public static function summary(array $f): array
    {
        [$w, $a] = self::where($f);
        $J = "FROM sample_intf i JOIN samples s ON s.id = i.sample_id WHERE $w";
        $byCls = self::rows("SELECT i.cls, COUNT(*) n, AVG(i.deg) deg, AVG(i.sinr_meas) sinr, AVG(s.rsrp) rsrp,
                10 * LOG10(AVG(POW(10, i.i_dbm / 10))) i_dbm $J GROUP BY i.cls", $a);
        $total = array_sum(array_map(function ($r) { return (int)$r['n']; }, $byCls));
        $classes = [];
        foreach ($byCls as $r) $classes[$r['cls']] = ['n' => (int)$r['n'], 'pct' => $total ? round(100 * $r['n'] / $total, 1) : 0,
            'deg' => round((float)$r['deg'], 1), 'sinr' => round((float)$r['sinr'], 1), 'rsrp' => round((float)$r['rsrp'], 1),
            'i_dbm' => $r['i_dbm'] === null ? null : round((float)$r['i_dbm'], 1), 'label' => self::LABELS[$r['cls']] ?? $r['cls']];
        $intf = array_sum(array_map(function ($c) { return $c['n']; }, array_intersect_key($classes, array_flip(self::INTERFERING))));
        $ops = self::rows("SELECT s.mcc, s.mnc, COUNT(*) n, SUM(i.cls IN ('" . implode("','", self::INTERFERING) . "')) n_intf, AVG(i.deg) deg,
                SUM(i.cls IN ('external','unexplained')) n_ext, SUM(i.cls IN ('cochannel','mod3','pilot')) n_net, AVG(i.sinr_meas) sinr $J GROUP BY s.mcc, s.mnc ORDER BY n DESC", $a);
        foreach ($ops as &$o) foreach (['mcc', 'mnc', 'n', 'n_intf', 'n_ext', 'n_net'] as $x) $o[$x] = (int)$o[$x];
        unset($o);
        // ساعات اليوم بتوقيت بغداد (UTC+3)
        $hours = self::rows("SELECT FLOOR(MOD(s.ts / 1000 + 10800, 86400) / 3600) h, COUNT(*) n, AVG(i.deg) deg,
                SUM(i.cls IN ('" . implode("','", self::INTERFERING) . "')) n_intf, SUM(i.cls IN ('external','unexplained')) n_ext $J GROUP BY h ORDER BY h", $a);
        $hourly = array_map(function ($r) { return ['h' => (int)$r['h'], 'n' => (int)$r['n'], 'deg' => round((float)$r['deg'], 1),
            'intf_pct' => round(100 * $r['n_intf'] / max(1, $r['n']), 1), 'ext_pct' => round(100 * $r['n_ext'] / max(1, $r['n']), 1)]; }, $hours);
        $src = self::rows("SELECT i.src, COUNT(*) n $J GROUP BY i.src", $a);
        return ['n' => $total, 'interfered' => $intf, 'interfered_pct' => $total ? round(100 * $intf / $total, 1) : 0,
            'classes' => $classes, 'operators' => $ops, 'hourly' => $hourly,
            'from_rsrq' => (int)(array_column($src, 'n', 'src')['r'] ?? 0)];
    }

    /** خريطة مربعات: السبب الغالب ومتوسط تراجع SINR وقدرة التشويش */
    public static function grid(array $f): array
    {
        $size = max(50, min(2000, (int)($f['size'] ?? self::cfg()['grid_m'])));
        [$w, $a] = self::where($f);
        [$dLat, $dLon] = self::cellDeg($size);
        $cols = implode(', ', array_map(function ($c) { return "SUM(i.cls = '$c') c_$c"; }, array_keys(self::LABELS)));
        $st = ss_db()->prepare("SELECT FLOOR(s.lat / ?) gy, FLOOR(s.lon / ?) gx, COUNT(*) n, AVG(i.deg) deg, AVG(i.sinr_meas) sinr,
                10 * LOG10(AVG(POW(10, i.i_dbm / 10))) i_dbm, MAX(i.i_dbm) i_max, COUNT(DISTINCT s.mnc) ops, $cols
            FROM sample_intf i JOIN samples s ON s.id = i.sample_id WHERE s.lat IS NOT NULL AND $w GROUP BY gy, gx LIMIT 50000");
        $st->execute(array_merge([$dLat, $dLon], $a));
        $feat = [];
        foreach ($st as $r) {
            $n = (int)$r['n'];
            $cnt = [];
            foreach (array_keys(self::LABELS) as $c) $cnt[$c] = (int)$r["c_$c"];
            $ni = array_sum(array_intersect_key($cnt, array_flip(self::INTERFERING)));
            $sub = array_intersect_key($cnt, array_flip(self::INTERFERING));
            arsort($sub);
            if ($ni / max(1, $n) >= 0.25) $dom = (string)array_key_first($sub);
            else { $base = ['clean' => $cnt['clean'], 'coverage' => $cnt['coverage'], 'load' => $cnt['load']]; arsort($base); $dom = (string)array_key_first($base); }
            $y0 = $r['gy'] * $dLat; $x0 = $r['gx'] * $dLon;
            $feat[] = ['type' => 'Feature',
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[round($x0, 6), round($y0, 6)], [round($x0 + $dLon, 6), round($y0, 6)],
                    [round($x0 + $dLon, 6), round($y0 + $dLat, 6)], [round($x0, 6), round($y0 + $dLat, 6)], [round($x0, 6), round($y0, 6)]]]],
                'properties' => ['n' => $n, 'deg' => round((float)$r['deg'], 1), 'sinr' => round((float)$r['sinr'], 1),
                    'i_dbm' => $r['i_dbm'] === null ? null : round((float)$r['i_dbm'], 1), 'i_max' => $r['i_max'] === null ? null : round((float)$r['i_max'], 1),
                    'intf_pct' => round(100 * $ni / max(1, $n), 1), 'dominant' => $dom, 'label' => self::LABELS[$dom] ?? $dom,
                    'ops' => (int)$r['ops'], 'counts' => array_filter($cnt),
                    'lat' => round($y0 + $dLat / 2, 6), 'lon' => round($x0 + $dLon / 2, 6)]];
        }
        return ['type' => 'FeatureCollection', 'features' => $feat, 'cell_m' => $size];
    }

    private static function cellDeg(int $size): array
    {
        $latMid = (float)(ss_db()->query('SELECT AVG(lat) FROM samples WHERE lat IS NOT NULL')->fetchColumn() ?: 30.5);
        $dLat = $size / 111320;
        return [$dLat, $dLat / max(0.1, cos(deg2rad($latMid)))];
    }

    /**
     * مصادر التشويش الخارجي: مربعات متجاورة يغلب فيها تشويش لا تفسّره الخلايا المجاورة،
     * ثم تقدير موقع المصدر وقوته ونوعه المرجّح ونمطه الزمني.
     * @return array<int,array<string,mixed>>
     */
    public static function sources(array $f): array
    {
        $k = self::cfg();
        $size = $k['grid_m'];
        [$w, $a] = self::where($f);
        [$dLat, $dLon] = self::cellDeg($size);
        $st = ss_db()->prepare("SELECT FLOOR(s.lat / ?) gy, FLOOR(s.lon / ?) gx, COUNT(*) n,
                SUM(i.cls IN ('external','unexplained')) ne, AVG(CASE WHEN i.cls IN ('external','unexplained') THEN i.deg END) deg
            FROM sample_intf i JOIN samples s ON s.id = i.sample_id WHERE s.lat IS NOT NULL AND (s.acc IS NULL OR s.acc <= 50) AND $w GROUP BY gy, gx");
        $st->execute(array_merge([$dLat, $dLon], $a));
        $hot = [];
        foreach ($st as $r) {
            if ((int)$r['ne'] >= 8 && $r['ne'] / $r['n'] >= 0.3 && (float)$r['deg'] >= $k['ext_min']) $hot[$r['gy'] . ':' . $r['gx']] = [(int)$r['gy'], (int)$r['gx']];
        }
        // تجميع المربعات المتجاورة (8 اتجاهات)
        $seen = []; $clusters = [];
        foreach ($hot as $key => $g) {
            if (isset($seen[$key])) continue;
            $stack = [$g]; $seen[$key] = true; $cl = [];
            while ($stack) {
                [$y, $x] = array_pop($stack); $cl[] = [$y, $x];
                for ($i = -1; $i <= 1; $i++) for ($j = -1; $j <= 1; $j++) {
                    $nk = ($y + $i) . ':' . ($x + $j);
                    if (isset($hot[$nk]) && !isset($seen[$nk])) { $seen[$nk] = true; $stack[] = [$y + $i, $x + $j]; }
                }
            }
            $clusters[] = $cl;
        }
        $out = [];
        foreach ($clusters as $ci => $cl) {
            $ys = array_column($cl, 0); $xs = array_column($cl, 1);
            // نوسّع الإطار مربعاً واحداً حول المنطقة حتى تدخل القراءات الأضعف في ملاءمة التضاؤل
            $box = [(min($ys) - 1) * $dLat, (max($ys) + 2) * $dLat, (min($xs) - 1) * $dLon, (max($xs) + 2) * $dLon];
            $q = ss_db()->prepare("SELECT s.lat, s.lon, s.ts, s.mcc, s.mnc, s.arfcn, s.tech, s.cell_ref, s.session_id, s.device_id, i.cls, i.deg, i.ext_dbm, i.i_dbm
                FROM sample_intf i JOIN samples s ON s.id = i.sample_id
                WHERE s.lat BETWEEN ? AND ? AND s.lon BETWEEN ? AND ? AND (s.acc IS NULL OR s.acc <= 50) AND $w LIMIT 20000");
            $q->execute(array_merge($box, $a));
            $all = $q->fetchAll();
            $ext = array_values(array_filter($all, function ($r) { return in_array($r['cls'], ['external', 'unexplained'], true) && $r['ext_dbm'] !== null; }));
            if (count($ext) < 8) continue;
            $pts = array_map(function ($r) { return ['lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'rsrp' => (float)$r['ext_dbm'], 'ta' => null, 'tech' => null]; }, $ext);
            $e = TowerEstimator::estimate($pts);
            if (!$e) continue;
            // القوة المكافئة: القدرة المستلمة + فقد الفضاء الحر حتى الموقع المقدّر (حد أدنى، لأن المباني تزيد الفقد)
            $eirp = []; $near = INF; $peak = -INF;
            foreach ($ext as $r) {
                $d = Geo::dist($e['lat'], $e['lon'], (float)$r['lat'], (float)$r['lon']);
                $fm = Obstacles::freqMHz($r['tech'], $r['arfcn'] === null ? null : (int)$r['arfcn']);
                $eirp[] = (float)$r['ext_dbm'] + Geo::fspl(max(10.0, $d), $fm);
                $near = min($near, $d); $peak = max($peak, (float)$r['ext_dbm']);
            }
            sort($eirp);
            $ops = []; $bands = []; $cellsHit = []; $hours = array_fill(0, 24, [0, 0]); $t1 = PHP_INT_MAX; $t2 = 0;
            $opsSeen = []; $sess = []; $days = []; $devs = [];
            foreach ($all as $r) {
                $h = (int)floor(fmod($r['ts'] / 1000 + 10800, 86400) / 3600);
                $isExt = in_array($r['cls'], ['external', 'unexplained'], true);
                $hours[$h][0]++; if ($isExt) $hours[$h][1]++;
                $ok = (int)$r['mcc'] . '-' . (int)$r['mnc'];
                $opsSeen[$ok][0] = ($opsSeen[$ok][0] ?? 0) + 1;
                $opsSeen[$ok][1] = ($opsSeen[$ok][1] ?? 0) + ($isExt ? 1 : 0);
                if (!$isExt) continue;
                $sess[(int)$r['session_id']] = true; $devs[(int)$r['device_id']] = true;
                $days[gmdate('Ymd', (int)($r['ts'] / 1000) + 10800)] = true;
                $ops[(int)$r['mcc'] . '-' . (int)$r['mnc']] = true;
                $fm = Obstacles::freqMHz($r['tech'], $r['arfcn'] === null ? null : (int)$r['arfcn']);
                $bands[self::bandName($r['tech'], $fm)] = true;
                if ($r['cell_ref']) $cellsHit[(int)$r['cell_ref']] = true;
                $t1 = min($t1, (int)$r['ts']); $t2 = max($t2, (int)$r['ts']);
            }
            $prof = []; $active = 0; $measured = 0;
            foreach ($hours as $h => [$n, $ne]) {
                $prof[] = ['h' => $h, 'n' => $n, 'pct' => $n ? round(100 * $ne / $n, 1) : null];
                if ($n >= 5) { $measured++; if ($ne / $n >= 0.3) $active++; }
            }
            $pattern = self::pattern($prof, $measured, $active);
            // مشغّلون قيسوا هنا وبقوا سليمين (أقل من 10% تشويش خارجي): دليل على أن المصدر يخص مشغّلاً بعينه
            $clean = array_keys(array_filter($opsSeen, function ($v) { return $v[0] >= 20 && $v[1] / $v[0] < 0.1; }));
            $type = self::guessType(count($ops), count($bands), $pattern['code'], count($clean));
            $degs = array_map(function ($r) { return (float)$r['deg']; }, $ext);
            // الثقة من الأدلة المستقلة، لا من عدد القراءات وحده
            $fitOk = $e['method'] === 'pathloss' && $e['exponent'] !== null && $e['exponent'] > 1.7 && $e['exponent'] < 4.8 && (float)$e['radius'] <= 600;
            $ev = [
                'fit' => $fitOk, 'operators' => count($ops) >= 2, 'days' => count($days) >= 2,
                'sessions' => count($sess) >= 2 || count($devs) >= 2, 'area' => count($cl) >= 3, 'samples' => count($ext) >= 50,
            ];
            $conf = ($fitOk ? 30 : 0) + ($ev['operators'] ? 25 : 0) + ($ev['days'] ? 15 : 0) + ($ev['sessions'] ? 10 : 0) + ($ev['area'] ? 10 : 0) + ($ev['samples'] ? 10 : 0);
            $need = [];
            if (!$fitOk) $need[] = 'تحرّك حول المنطقة في عدة اتجاهات: التضاؤل الحالي لا يحدد موقعاً واضحاً (الأس ' . ($e['exponent'] ?? '—') . ').';
            if (!$ev['operators']) $need[] = $clean ? 'مشغّل آخر قيس هنا وكان سليماً: المصدر يخص هذا المشغّل (تداخل من شبكته أو مقوٍّ لنطاقه)، لا جهاز تشويش عام.' : 'قِس بشريحة مشغّل آخر في المكان نفسه: إن تأثر هو أيضاً فالمصدر خارجي مؤكد.';
            if (!$ev['days']) $need[] = 'كرر القياس في يوم آخر للتأكد أن المصدر ليس مؤقتاً.';
            if (!$ev['sessions']) $need[] = 'سجّل جلسة أخرى أو استخدم جهازاً آخر لاستبعاد خلل في الجهاز.';
            $out[] = [
                'id' => $ci + 1, 'lat' => round($e['lat'], 6), 'lon' => round($e['lon'], 6),
                'radius_m' => (int)round(min(2000, (float)$e['radius'])), 'method' => $e['method'], 'exponent' => $e['exponent'],
                'n' => count($ext), 'n_area' => count($all), 'squares' => count($cl),
                'deg_avg' => round(array_sum($degs) / count($degs), 1), 'deg_max' => round(max($degs), 1),
                'peak_dbm' => round($peak, 1), 'eirp_dbm' => round($eirp[(int)floor(0.5 * (count($eirp) - 1))], 1),
                'nearest_m' => (int)round($near), 'operators' => array_keys($ops), 'bands' => array_keys($bands),
                'cells_affected' => count($cellsHit), 'first' => $t1, 'last' => $t2,
                'pattern' => $pattern, 'hourly' => $prof, 'type' => $type, 'confidence' => $conf, 'evidence' => $ev, 'need' => $need,
                'status' => $conf >= 60 ? 'likely' : 'candidate', 'ops_clean' => $clean,
                'sessions' => count($sess), 'days' => count($days), 'devices' => count($devs),
            ];
        }
        usort($out, function ($x, $y) { return [$y['confidence'], $y['deg_avg'] * $y['n']] <=> [$x['confidence'], $x['deg_avg'] * $x['n']]; });
        foreach ($out as $i => &$s) $s['id'] = $i + 1;
        return $out;
    }

    public static function bandName(?string $tech, float $fMHz): string
    {
        $b = [[791, 821, 'B20 800'], [758, 803, 'B28 700'], [925, 960, 'B8 900'], [1805, 1880, 'B3 1800'], [2110, 2170, 'B1 2100'],
              [2300, 2400, 'B40 2300'], [2496, 2690, 'B41/B7 2600'], [3300, 3800, 'n78 3500']];
        foreach ($b as [$lo, $hi, $n]) if ($fMHz >= $lo && $fMHz <= $hi) return ($tech === 'NR' ? '5G ' : '') . $n;
        return ($tech ?: '') . ' ' . (int)round($fMHz) . ' MHz';
    }

    /** النمط الزمني: دائم، نهاري، ليلي، متقطع — من نسبة القراءات المشوَّشة في كل ساعة قيست */
    private static function pattern(array $prof, int $measured, int $active): array
    {
        if ($measured < 4) return ['code' => 'unknown', 'text' => 'القياسات في ساعات قليلة؛ لا يمكن تحديد نمط زمني بعد. قِس في أوقات مختلفة من اليوم.'];
        $day = 0; $dayN = 0; $night = 0; $nightN = 0;
        foreach ($prof as $p) {
            if ($p['n'] < 5) continue;
            $on = $p['pct'] >= 30;
            if ($p['h'] >= 7 && $p['h'] < 19) { $dayN++; if ($on) $day++; } else { $nightN++; if ($on) $night++; }
        }
        $ratio = $active / $measured;
        if ($ratio >= 0.8) return ['code' => 'constant', 'text' => 'يظهر في معظم الساعات المقيسة: مصدر ثابت يعمل باستمرار (مقوٍّ أو مكرّر مثبَّت، أو معدات تعمل دائماً).'];
        if ($dayN && $nightN && $day / $dayN >= 0.6 && $night / $nightN <= 0.3) return ['code' => 'daytime', 'text' => 'يتركّز في ساعات النهار (7 صباحاً – 7 مساءً): يتوافق مع معدات تعمل بدوام، أو أجهزة تشويش تُشغَّل في أوقات محددة (مثل قاعات الامتحانات).'];
        if ($dayN && $nightN && $night / $nightN >= 0.6 && $day / $dayN <= 0.3) return ['code' => 'night', 'text' => 'يتركّز ليلاً: يتوافق مع مولدات أو معدات تعمل مساءً، أو مقويات تُشغَّل وقت الذروة المنزلية.'];
        return ['code' => 'intermittent', 'text' => 'متقطع: يظهر في بعض الساعات دون نمط واضح. يتوافق مع معدات كهربائية تعمل وتتوقف، أو مصدر متنقل.'];
    }

    private static function guessType(int $ops, int $bands, string $pattern, int $clean = 0): array
    {
        if ($ops === 1 && $bands >= 2) return ['code' => 'network', 'title' => 'تشويش على عدة نطاقات لمشغّل واحد',
            'why' => 'أصاب نطاقات مشغّل واحد فقط' . ($clean ? '، وبقي مشغّل آخر قيس هنا سليماً' : '') . '. الأرجح تداخل من خلايا المشغّل نفسه لا يراها الهاتف في شبكة مزدحمة، أو مقوٍّ متعدد النطاقات لهذا المشغّل. جهاز التشويش العام يصيب كل المشغّلين.'];
        if ($ops >= 2 && $bands >= 2) return ['code' => 'jammer', 'title' => 'جهاز تشويش واسع النطاق (Jammer) مرجّح',
            'why' => 'التشويش يصيب أكثر من مشغّل وأكثر من نطاق ترددي في المكان نفسه، وشبكات المشغّلين لا تتداخل فيما بينها لأنها على ترددات مختلفة.'];
        if ($ops >= 2) return ['code' => 'wideband', 'title' => 'مصدر واسع النطاق يصيب عدة مشغّلين',
            'why' => 'أكثر من مشغّل متأثر على نطاق واحد: مصدر يبث على النطاق كله (مقوٍّ متعدد المشغّلين، أو جهاز تشويش لنطاق واحد).'];
        if ($pattern === 'constant') return ['code' => 'booster', 'title' => 'مقوٍّ أو مكرّر إشارة غير مرخص أو معطوب مرجّح',
            'why' => 'تشويش ثابت على نطاق مشغّل واحد. المقويات غير المرخصة (Boosters) الشائعة في المنازل والمحال تتذبذب ذاتياً وترفع الضوضاء على تردد المشغّل.'];
        return ['code' => 'other', 'title' => 'مصدر خارجي غير محدد',
            'why' => 'تشويش لا تفسّره خلايا المشغّل. من المصادر الشائعة: معدات كهربائية معطوبة (مولدات، محولات، إنارة LED، عواكس)، تشويش التشكيل البيني السلبي (PIM) في الهوائيات، أو مقوٍّ يعمل أحياناً.'];
    }

    /**
     * الخلايا المسببة للتداخل (من خلايا المشغّل نفسه): مجمّعة حسب التردد و PCI،
     * مع ربطها بالخلية المعروفة وموقع برجها المقدّر إن وُجد، وتوصية لكل منها.
     * @return array<string,mixed>
     */
    public static function aggressors(array $f): array
    {
        [$w, $a] = self::where($f);
        $rows = self::rows("SELECT s.mcc, s.mnc, s.tech, s.arfcn, i.agg_pci pci, COUNT(*) n, AVG(i.agg_gap) gap, SUM(i.agg_mod3) n_mod3,
                SUM(i.agg_gap < 0) n_stronger, SUM(i.cls = 'pilot') n_pilot, AVG(i.deg) deg, COUNT(DISTINCT s.cell_ref) victims,
                AVG(s.lat) lat, AVG(s.lon) lon, GROUP_CONCAT(DISTINCT s.pci ORDER BY s.pci SEPARATOR ',') victim_pcis
            FROM sample_intf i JOIN samples s ON s.id = i.sample_id
            WHERE i.cls IN ('cochannel','mod3','pilot') AND i.agg_pci IS NOT NULL AND $w
            GROUP BY s.mcc, s.mnc, s.tech, s.arfcn, i.agg_pci HAVING COUNT(*) >= 5 ORDER BY COUNT(*) * AVG(i.deg) DESC LIMIT 60", $a);
        $find = ss_db()->prepare('SELECT id, cid, est_lat, est_lon, est_radius_m FROM cells WHERE mcc = ? AND mnc = ? AND tech = ? AND arfcn = ? AND pci = ?');
        foreach ($rows as &$r) {
            foreach (['mcc', 'mnc', 'arfcn', 'pci', 'n', 'n_mod3', 'n_stronger', 'n_pilot', 'victims'] as $x) $r[$x] = $r[$x] === null ? null : (int)$r[$x];
            foreach (['gap', 'deg', 'lat', 'lon'] as $x) $r[$x] = $r[$x] === null ? null : round((float)$r[$x], $x === 'lat' || $x === 'lon' ? 6 : 1);
            $r['band'] = self::bandName($r['tech'], Obstacles::freqMHz($r['tech'], $r['arfcn']));
            // الخلية المعروفة بنفس التردد و PCI الأقرب إلى مكان التداخل
            $find->execute([$r['mcc'], $r['mnc'], $r['tech'], $r['arfcn'], $r['pci']]);
            $best = null;
            foreach ($find as $c) {
                $d = $c['est_lat'] === null || $r['lat'] === null ? null : Geo::dist((float)$c['est_lat'], (float)$c['est_lon'], $r['lat'], $r['lon']);
                if ($best === null || ($d !== null && ($best['d'] === null || $d < $best['d']))) $best = ['id' => (int)$c['id'], 'cid' => (int)$c['cid'], 'd' => $d,
                    'lat' => $c['est_lat'] === null ? null : (float)$c['est_lat'], 'lon' => $c['est_lon'] === null ? null : (float)$c['est_lon']];
            }
            $r['cell'] = $best;
            $r['dist_m'] = $best && $best['d'] !== null ? (int)round($best['d']) : null;
            $r['advice'] = self::adviceFor($r);
        }
        unset($r);
        $pci = self::rows("SELECT s.mcc, s.mnc, s.arfcn, s.pci serving_pci, i.agg_pci neighbor_pci, COUNT(*) n, AVG(i.agg_gap) gap, AVG(i.deg) deg
            FROM sample_intf i JOIN samples s ON s.id = i.sample_id WHERE i.agg_mod3 = 1 AND i.agg_gap <= 6 AND $w
            GROUP BY s.mcc, s.mnc, s.arfcn, s.pci, i.agg_pci HAVING COUNT(*) >= 3 ORDER BY COUNT(*) DESC LIMIT 60", $a);
        foreach ($pci as &$p) {
            foreach (['mcc', 'mnc', 'arfcn', 'serving_pci', 'neighbor_pci', 'n'] as $x) $p[$x] = (int)$p[$x];
            $p['gap'] = round((float)$p['gap'], 1); $p['deg'] = round((float)$p['deg'], 1); $p['mod3'] = $p['serving_pci'] % 3;
        }
        return ['aggressors' => $rows, 'pci_conflicts' => $pci];
    }

    /** @param array<string,mixed> $r */
    private static function adviceFor(array $r): array
    {
        $n = max(1, $r['n']);
        $out = [];
        if ($r['n_mod3'] / $n >= 0.5) $out[] = 'إعادة تخطيط PCI: الخلية ' . $r['pci'] . ' تتصادم بقيمة mod 3 مع الخلايا الخادمة (' . $r['victim_pcis'] . '). اختر PCI يختلف باقي قسمته على 3 عن الخلايا المجاورة على التردد نفسه.';
        if ($r['n_stronger'] / $n >= 0.3) $out[] = 'الخلية المجاورة أقوى من الخادمة في ' . round(100 * $r['n_stronger'] / $n) . '% من الحالات: تأخر في التسليم. راجع إزاحة A3 و CIO وقائمة الجيران، أو تأكد أن الخلية معرّفة جاراً.';
        if ($r['n_pilot'] / $n >= 0.3) $out[] = 'تلوث إشارة: خلايا كثيرة متقاربة القوة بلا خلية مهيمنة. أنشئ هيمنة واضحة بإمالة الخلايا الزائدة للأسفل أو خفض قدرتها.';
        if ($r['dist_m'] !== null && $r['dist_m'] > 2500) $out[] = 'تجاوز التغطية (Overshooting): برج الخلية المقدّر على بعد ' . round($r['dist_m'] / 1000, 1) . ' كم من مكان التداخل. زد الإمالة الكهربائية (Downtilt) أو اخفض القدرة.';
        if (!$out) $out[] = 'تداخل تراكب بين خليتين على التردد نفسه. حسّن الحدود بينهما بضبط الإمالة أو الاتجاه (Azimuth)، أو فعّل تنسيق التداخل ICIC/eICIC، أو انقل إحداهما إلى تردد آخر.';
        return $out;
    }

    /** دليل الأسباب وطرق العلاج */
    public static function catalog(): array
    {
        return [
            'clean' => ['color' => '#16a34a', 'what' => 'SINR قريب من المتوقع لقوة الإشارة. لا تشويش يُذكر.', 'fix' => []],
            'coverage' => ['color' => '#64748b', 'what' => 'الإشارة ضعيفة (RSRP أقل من −110 dBm) لكن لا تشويش: المشكلة بعد البرج أو العوائق، لا التشويش.',
                'fix' => ['راجع صفحة «العوائق» لمعرفة سبب الضعف.', 'للمشغّل: موقع جديد أو خلية صغيرة أو رفع قدرة الخلية.', 'للمستخدم: الاقتراب من النافذة أو استخدام الاتصال عبر Wi-Fi.']],
            'load' => ['color' => '#0ea5e9', 'what' => 'SINR جيد لكن RSRQ منخفض: الخلية مزدحمة بالمستخدمين، وليس تشويشاً.',
                'fix' => ['إضافة حامل ترددي (Carrier) أو تجميع الحوامل (CA).', 'موازنة الحمل مع الخلايا المجاورة (MLB).', 'تقسيم الخلية إلى قطاعات أكثر.']],
            'cochannel' => ['color' => '#f59e0b', 'what' => 'خلية مجاورة من المشغّل نفسه على التردد نفسه قوية بما يكفي لتخفيض SINR (تراكب التغطية).',
                'fix' => ['إمالة الهوائي للأسفل (Downtilt) للخلية المسببة.', 'ضبط الاتجاه (Azimuth) أو خفض القدرة.', 'تفعيل تنسيق التداخل ICIC / eICIC.', 'ضبط معاملات التسليم إن كانت المجاورة أقوى من الخادمة.']],
            'mod3' => ['color' => '#ea580c', 'what' => 'الخلية المجاورة القوية لها PCI بنفس باقي القسمة على 3، فتقع إشاراتها المرجعية فوق إشارات الخلية الخادمة.',
                'fix' => ['إعادة تخطيط PCI بحيث تختلف قيمة mod 3 للخلايا المتجاورة على التردد نفسه.', 'قطاعات البرج الواحد يجب أن تأخذ mod 3 = 0 و 1 و 2.']],
            'pilot' => ['color' => '#a855f7', 'what' => 'أربع خلايا أو أكثر متقاربة القوة (ضمن 6 dB) بلا خلية مهيمنة. الهاتف يتنقل بينها ويتشوش منها.',
                'fix' => ['إنشاء خلية مهيمنة: إمالة الخلايا البعيدة للأسفل أو خفض قدرتها.', 'إزالة تجاوز التغطية (Overshooting).', 'مراجعة قوائم الجيران.']],
            'external' => ['color' => '#dc2626', 'what' => 'تشويش قوي لا تفسّره الخلايا المجاورة التي يراها الهاتف: مصدر من خارج شبكة المشغّل.',
                'fix' => ['حدّد المنطقة من صفحة «مصادر التشويش» ثم ابحث ميدانياً بمحلل طيف وهوائي اتجاهي (Yagi): اتجه حيث تزداد القدرة.',
                          'الأكثر شيوعاً: مقويات إشارة غير مرخصة أو معطوبة في المنازل والمحال. افصلها أو استبدلها بمقوٍّ مرخّص.',
                          'أجهزة التشويش (Jammers): بلّغ هيئة الإعلام والاتصالات (CMC) لأنها ممنوعة إلا بترخيص.',
                          'معدات كهربائية معطوبة (مولدات، عواكس، إنارة LED رديئة): جرّب فصلها والقياس مجدداً.',
                          'للمشغّل: راجع مستوى التشويش في الوصلة الصاعدة (UL RSSI/PRB) لخلايا المنطقة، وافحص PIM في الهوائيات والوصلات.']],
            'unexplained' => ['color' => '#f43f5e', 'what' => 'تشويش قوي لا تفسّره الخلايا المجاورة المرئية، لكن الإشارة ضعيفة (حافة الخلية) أو الجهاز لا يبلّغ عن الجيران، فلا يمكن الجزم: خلايا بعيدة غير مرئية، أو مصدر خارجي.',
                'fix' => ['قِس في المكان نفسه بجهاز يبلّغ عن الخلايا المجاورة (معظم هواتف Samsung و Pixel).', 'إن ظهر مع عدة مشغّلين في المكان نفسه فهو خارجي غالباً، ويظهر عندها في «مصادر التشويش».', 'إن كان عند حافة الخلية فقط: تحسين التغطية (موقع جديد أو خلية صغيرة) يرفع SINR.']],
            'mixed' => ['color' => '#eab308', 'what' => 'تشويش متوسط من مزيج من الخلايا المجاورة ومصادر أخرى.',
                'fix' => ['عالج الخلايا المسببة في «الخلايا المسببة للتداخل» أولاً، ثم أعد القياس.']],
        ];
    }

    /** @return array<string,mixed> */
    public static function status(): array
    {
        $db = ss_db();
        $cal = @json_decode((string)@file_get_contents(SS_APP_DIR . '/data/intf.calib.json'), true);
        return ['analyzed' => (int)$db->query('SELECT COUNT(*) FROM sample_intf')->fetchColumn(),
            'eligible' => (int)$db->query("SELECT COUNT(*) FROM samples WHERE tech IN ('LTE','NR') AND rsrp IS NOT NULL AND (sinr IS NOT NULL OR rsrq IS NOT NULL)")->fetchColumn(),
            'with_neighbors' => (int)$db->query("SELECT COUNT(*) FROM samples WHERE tech IN ('LTE','NR') AND neighbors IS NOT NULL")->fetchColumn(),
            'sinr_caps' => is_array($cal) ? ($cal['caps'] ?? []) : [],
            'rsrq_maps' => is_array($cal) ? array_map('count', $cal['rq'] ?? []) : [], 'model' => self::MODEL_VERSION, 'config' => self::cfg()];
    }

    /** تصدير القراءات المحللة CSV */
    public static function csv(array $f): void
    {
        [$w, $a] = self::where($f);
        $st = ss_db()->prepare("SELECT s.ts, s.lat, s.lon, s.mcc, s.mnc, s.tech, s.arfcn, s.pci, s.rsrp, s.rsrq, s.sinr, i.cls, i.src, i.sinr_exp, i.sinr_meas, i.deg,
                i.i_dbm, i.nb_dbm, i.ext_dbm, i.n_cochan, i.n_close, i.agg_pci, i.agg_gap, i.agg_mod3
            FROM sample_intf i JOIN samples s ON s.id = i.sample_id WHERE $w ORDER BY s.ts LIMIT 300000");
        $st->execute($a);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="interference-' . gmdate('Ymd') . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['time_utc', 'lat', 'lon', 'mcc', 'mnc', 'tech', 'arfcn', 'pci', 'rsrp', 'rsrq', 'sinr', 'class', 'class_ar', 'sinr_from',
            'sinr_expected', 'sinr_used', 'degradation_db', 'interference_dbm_mhz', 'neighbors_dbm_mhz', 'unexplained_dbm_mhz',
            'cochannel_neighbors', 'close_neighbors', 'aggressor_pci', 'aggressor_gap_db', 'aggressor_mod3']);
        foreach ($st as $r) {
            $row = array_values($r);
            $row[0] = gmdate('Y-m-d H:i:s', (int)($r['ts'] / 1000));
            array_splice($row, 12, 0, [self::LABELS[$r['cls']] ?? $r['cls']]);
            $row[13] = $r['src'] === 's' ? 'sinr' : 'rsrq';
            fputcsv($o, $row);
        }
        fclose($o);
        exit;
    }
}
