<?php
declare(strict_types=1);

/**
 * تنبيهات تدهور الإشارة.
 * لكل مربع على الخريطة ولكل مشغّل: نقارن «آخر 7 أيام» بـ «28 يوماً قبلها» في المكان نفسه،
 * فلا يختلط تغيّر الشبكة بتغيّر مكان القياس. يُفتح التنبيه عند:
 *   تراجع متوسط RSRP ≥ 6 dB، أو تراجع SINR ≥ 5 dB، أو زيادة انقطاع الخدمة ≥ 10 نقاط مئوية،
 * بشرط 10 قراءات على الأقل في كل فترة. يُغلق تلقائياً حين يزول التدهور.
 * الإعدادات قابلة للتغيير من config.php (alert_*).
 */
final class Alerts
{
    /** @return array<string,float|int> */
    public static function cfg(): array
    {
        $c = ss_config();
        return [
            'size' => (int)($c['alert_grid_m'] ?? 300),
            'recent_days' => (int)($c['alert_recent_days'] ?? 7),
            'base_days' => (int)($c['alert_base_days'] ?? 28),
            'min_n' => (int)($c['alert_min_samples'] ?? 10),
            'drop_db' => (float)($c['alert_drop_db'] ?? 6.0),
            'sinr_db' => (float)($c['alert_sinr_db'] ?? 5.0),
            'nosvc_pts' => (float)($c['alert_nosvc_pts'] ?? 10.0),
        ];
    }

    /** يُستدعى من cron كل 15 دقيقة، ويفحص مرة كل ساعة على الأكثر */
    public static function scanIfDue(): ?array
    {
        $f = SS_APP_DIR . '/data/alerts.last';
        if (is_file($f) && time() - (int)@file_get_contents($f) < 3600) return null;
        @file_put_contents($f, (string)time());
        return self::scan();
    }

    /** @return array<string,int> */
    public static function scan(?int $nowMs = null): array
    {
        $db = ss_db();
        $k = self::cfg();
        $now = $nowMs ?? (int)(microtime(true) * 1000);
        $day = 86400000;
        $rFrom = $now - $k['recent_days'] * $day;
        $bFrom = $rFrom - $k['base_days'] * $day;
        $latMid = (float)($db->query('SELECT AVG(lat) FROM samples WHERE lat IS NOT NULL')->fetchColumn() ?: 30.5);
        $dLat = $k['size'] / 111320;
        $dLon = $dLat / max(0.1, cos(deg2rad($latMid)));

        $win = function (int $from, int $to) use ($db, $dLat, $dLon) {
            $st = $db->prepare("SELECT mcc, mnc, FLOOR(lat / ?) gy, FLOOR(lon / ?) gx, COUNT(*) n, AVG(rsrp) r, AVG(sinr) s,
                    100 * SUM(tech = 'NONE' OR tech IS NULL) / COUNT(*) ns, AVG(lat) la, AVG(lon) lo
                FROM samples WHERE ts >= ? AND ts < ? AND lat IS NOT NULL AND mcc IS NOT NULL GROUP BY mcc, mnc, gy, gx");
            $st->execute([$dLat, $dLon, $from, $to]);
            $out = [];
            foreach ($st as $r) $out[(int)$r['mcc'] . '-' . (int)$r['mnc'] . ':' . (int)$r['gy'] . ':' . (int)$r['gx']] = $r;
            return $out;
        };
        $base = $win($bFrom, $rFrom);
        $recent = $win($rFrom, $now + 1);

        $stats = ['compared' => 0, 'opened' => 0, 'updated' => 0, 'resolved' => 0, 'reopened' => 0];
        $seen = [];
        $get = $db->prepare('SELECT id, status, delta_rsrp FROM alerts WHERE akey = ?');
        foreach ($recent as $key => $r) {
            if (!isset($base[$key])) continue;
            $b = $base[$key];
            if ((int)$r['n'] < $k['min_n'] || (int)$b['n'] < $k['min_n']) continue;
            $stats['compared']++;
            $akey = 'area:' . $key . ':' . $k['size'];
            $seen[$akey] = true;
            $dR = ($r['r'] !== null && $b['r'] !== null) ? (float)$r['r'] - (float)$b['r'] : null;
            $dS = ($r['s'] !== null && $b['s'] !== null) ? (float)$r['s'] - (float)$b['s'] : null;
            $dN = (float)$r['ns'] - (float)$b['ns'];
            $why = [];
            if ($dR !== null && $dR <= -$k['drop_db']) $why[] = sprintf('تراجع RSRP %.1f dB (من %.0f إلى %.0f dBm)', -$dR, $b['r'], $r['r']);
            if ($dS !== null && $dS <= -$k['sinr_db']) $why[] = sprintf('تراجع SINR %.1f dB', -$dS);
            if ($dN >= $k['nosvc_pts']) $why[] = sprintf('انقطاع الخدمة من %.0f%% إلى %.0f%%', $b['ns'], $r['ns']);
            $get->execute([$akey]);
            $old = $get->fetch();

            if (!$why) {
                // تعافت المنطقة: نغلق التنبيه إن زال التدهور بهامش (نصف العتبة) حتى لا يتذبذب
                $calm = ($dR === null || $dR > -$k['drop_db'] / 2) && ($dS === null || $dS > -$k['sinr_db'] / 2) && $dN < $k['nosvc_pts'] / 2;
                if ($old && $old['status'] !== 'resolved' && $calm) {
                    $db->prepare("UPDATE alerts SET status = 'resolved', resolved_at = UTC_TIMESTAMP(), recent_rsrp = ?, delta_rsrp = ?, n_recent = ? WHERE id = ?")
                       ->execute([$r['r'], $dR, (int)$r['n'], $old['id']]);
                    $stats['resolved']++;
                }
                continue;
            }
            $sev = (($dR !== null && $dR <= -10) || (float)$r['ns'] >= 25) ? 'high' : 'medium';
            $vals = [(float)$r['la'], (float)$r['lo'], $sev, mb_substr(implode('، ', $why), 0, 250), $b['r'], $r['r'], $dR, $b['s'], $r['s'], $b['ns'], $r['ns'], (int)$b['n'], (int)$r['n']];
            if (!$old) {
                [$mcc, $mnc] = array_map('intval', explode('-', explode(':', $key)[0]));
                $db->prepare("INSERT INTO alerts (akey, mcc, mnc, size_m, lat, lon, severity, reason, base_rsrp, recent_rsrp, delta_rsrp, base_sinr, recent_sinr, base_nosvc, recent_nosvc, n_base, n_recent, status, first_seen, last_seen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                   ->execute(array_merge([$akey, $mcc, $mnc, $k['size']], $vals));
                $stats['opened']++;
            } else {
                // تنبيه مُطّلع عليه يُعاد فتحه إن ساءت المنطقة 3 dB إضافية، والمغلق يُفتح من جديد
                $status = $old['status'];
                if ($status === 'resolved' || ($status === 'ack' && $dR !== null && $old['delta_rsrp'] !== null && $dR <= (float)$old['delta_rsrp'] - 3)) {
                    $status = 'open'; $stats['reopened']++;
                } else { $stats['updated']++; }
                $db->prepare("UPDATE alerts SET lat = ?, lon = ?, severity = ?, reason = ?, base_rsrp = ?, recent_rsrp = ?, delta_rsrp = ?, base_sinr = ?, recent_sinr = ?,
                        base_nosvc = ?, recent_nosvc = ?, n_base = ?, n_recent = ?, status = ?, last_seen = UTC_TIMESTAMP(), resolved_at = IF(? = 'open', NULL, resolved_at) WHERE id = ?")
                   ->execute(array_merge($vals, [$status, $status, $old['id']]));
            }
        }
        return $stats;
    }

    /** @return array<int,array<string,mixed>> */
    public static function list(string $which): array
    {
        $where = $which === 'all' ? '1=1' : ($which === 'resolved' ? "status = 'resolved'" : "status IN ('open','ack')");
        $rows = ss_db()->query("SELECT * FROM alerts WHERE $where ORDER BY FIELD(status,'open','ack','resolved'), FIELD(severity,'high','medium'), delta_rsrp LIMIT 500")->fetchAll();
        foreach ($rows as &$r) {
            foreach (['id', 'mcc', 'mnc', 'size_m', 'n_base', 'n_recent'] as $f) $r[$f] = $r[$f] === null ? null : (int)$r[$f];
            foreach (['lat', 'lon', 'base_rsrp', 'recent_rsrp', 'delta_rsrp', 'base_sinr', 'recent_sinr', 'base_nosvc', 'recent_nosvc'] as $f) $r[$f] = $r[$f] === null ? null : round((float)$r[$f], 2);
        }
        return $rows;
    }

    public static function openCount(): int
    {
        return (int)ss_db()->query("SELECT COUNT(*) FROM alerts WHERE status = 'open'")->fetchColumn();
    }

    public static function ack(int $id, string $who): void
    {
        ss_db()->prepare("UPDATE alerts SET status = 'ack', ack_at = UTC_TIMESTAMP(), ack_by = ? WHERE id = ? AND status = 'open'")->execute([$who, $id]);
    }
}
