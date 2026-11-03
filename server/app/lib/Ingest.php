<?php
declare(strict_types=1);

/**
 * استقبال دفعات القراءات من الهاتف.
 * الرفع idempotent: (device_id, local_id) فريد، فإعادة إرسال الدفعة نفسها لا تكرر البيانات.
 */
final class Ingest
{
    private const TECHS = ['LTE', 'NR', 'WCDMA', 'GSM', 'CDMA', 'TDSCDMA', 'NONE'];

    public static function handle(array $device, array $body): array
    {
        $db = ss_db();
        $cfg = ss_config();
        $samples = $body['samples'] ?? [];
        $sessions = $body['sessions'] ?? [];
        if (!is_array($samples) || !is_array($sessions)) throw new HttpError(400, 'صيغة غير صحيحة', 'bad_payload');
        if (count($samples) > ($cfg['max_batch'] ?? 5000)) throw new HttpError(413, 'الدفعة كبيرة جداً', 'batch_too_large');

        $devId = (int)$device['id'];
        $db->beginTransaction();
        try {
            // 1) الجلسات
            $sessionIds = [];
            $upS = $db->prepare(
                'INSERT INTO sessions (device_id, uuid, name, started_at, ended_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                   name = COALESCE(VALUES(name), name),
                   ended_at = COALESCE(VALUES(ended_at), ended_at)'
            );
            foreach ($sessions as $s) {
                $uuid = self::uuid($s['uuid'] ?? null);
                if (!$uuid) continue;
                $upS->execute([
                    $devId, $uuid,
                    isset($s['name']) ? mb_substr((string)$s['name'], 0, 120) : null,
                    self::int($s['started_at'] ?? null) ?? (int)(microtime(true) * 1000),
                    self::int($s['ended_at'] ?? null),
                ]);
                $sessionIds[$uuid] = (int)$db->lastInsertId();
            }

            // 2) الخلايا المذكورة في الدفعة
            $cellIds = [];
            $upC = $db->prepare(
                'INSERT INTO cells (tech, mcc, mnc, tac, cid, pci, arfcn, first_seen, last_seen, dirty)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                   tac = COALESCE(VALUES(tac), tac), pci = COALESCE(VALUES(pci), pci),
                   arfcn = COALESCE(VALUES(arfcn), arfcn),
                   first_seen = LEAST(COALESCE(first_seen, VALUES(first_seen)), VALUES(first_seen)),
                   last_seen = GREATEST(COALESCE(last_seen, 0), VALUES(last_seen)),
                   dirty = 1'
            );

            $rows = [];
            $ack = [];
            foreach ($samples as $x) {
                if (!is_array($x)) continue;
                $localId = self::int($x['id'] ?? null);
                $ts = self::int($x['ts'] ?? null);
                $suuid = self::uuid($x['session'] ?? null);
                if ($localId === null || $ts === null || !$suuid) continue;

                if (!isset($sessionIds[$suuid])) {
                    // جلسة لم تُرسل بياناتها الوصفية: ننشئها بالحد الأدنى
                    $upS->execute([$devId, $suuid, null, $ts, null]);
                    $sessionIds[$suuid] = (int)$db->lastInsertId();
                }

                $lat = self::float($x['lat'] ?? null);
                $lon = self::float($x['lon'] ?? null);
                if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
                if ($lon !== null && ($lon < -180 || $lon > 180)) $lon = null;
                if ($lat === null || $lon === null) { $lat = $lon = null; }

                $tech = strtoupper((string)($x['tech'] ?? ''));
                if (!in_array($tech, self::TECHS, true)) $tech = null;
                $mcc = self::int($x['mcc'] ?? null);
                $mnc = self::int($x['mnc'] ?? null);
                $cid = self::int($x['cid'] ?? null);
                if ($cid !== null && $cid < 0) $cid = null;

                $cellRef = null;
                if ($tech && $tech !== 'NONE' && $mcc !== null && $mnc !== null && $cid !== null) {
                    $key = "$mcc-$mnc-$tech-$cid";
                    if (!isset($cellIds[$key])) {
                        $upC->execute([$tech, $mcc, $mnc, self::int($x['tac'] ?? null), $cid,
                            self::int($x['pci'] ?? null), self::int($x['arfcn'] ?? null), $ts, $ts]);
                        $cellIds[$key] = (int)$db->lastInsertId();
                    }
                    $cellRef = $cellIds[$key];
                }

                $nb = $x['neighbors'] ?? null;
                $nbJson = null;
                if (is_array($nb) && $nb) {
                    $nbJson = json_encode(array_slice($nb, 0, 16), JSON_UNESCAPED_SLASHES);
                    if (strlen($nbJson) > 8000) $nbJson = null;
                }

                $rows[] = [
                    $devId, $sessionIds[$suuid], $localId, $ts,
                    $lat, $lon,
                    self::float($x['acc'] ?? null), self::float($x['alt'] ?? null), self::float($x['speed'] ?? null),
                    $tech, $mcc, $mnc, self::int($x['tac'] ?? null), $cid,
                    self::int($x['pci'] ?? null), self::int($x['arfcn'] ?? null),
                    self::range(self::int($x['rsrp'] ?? null), -156, -31),
                    self::range(self::int($x['rsrq'] ?? null), -43, 20),
                    self::rangeF(self::float($x['sinr'] ?? null), -23, 40),
                    self::range(self::int($x['rssi'] ?? null), -120, 0),
                    self::range(self::int($x['ta'] ?? null), 0, 3846),
                    $cellRef, $nbJson,
                ];
                $ack[] = $localId;
            }

            $inserted = 0;
            $cols = '(device_id, session_id, local_id, ts, lat, lon, acc, alt, speed, tech, mcc, mnc, tac, cid, pci, arfcn, rsrp, rsrq, sinr, rssi, ta, cell_ref, neighbors)';
            foreach (array_chunk($rows, 400) as $chunk) {
                $ph = '(' . implode(',', array_fill(0, 23, '?')) . ')';
                $sql = "INSERT IGNORE INTO samples $cols VALUES " . implode(',', array_fill(0, count($chunk), $ph));
                $st = $db->prepare($sql);
                $st->execute(array_merge(...$chunk));
                $inserted += $st->rowCount();
            }

            // 3) تحديث ملخصات الجلسات المتأثرة
            if ($sessionIds) {
                $ids = implode(',', array_map('intval', array_values($sessionIds)));
                $db->exec("UPDATE sessions s JOIN (
                    SELECT session_id, COUNT(*) c, MIN(ts) a, MAX(ts) b FROM samples
                    WHERE session_id IN ($ids) GROUP BY session_id) t ON t.session_id = s.id
                    SET s.sample_count = t.c, s.first_ts = t.a, s.last_ts = t.b");
            }

            $db->prepare('UPDATE devices SET last_seen = UTC_TIMESTAMP(), last_ip = ?, app_version = COALESCE(?, app_version) WHERE id = ?')
               ->execute([Http::ip(), isset($body['app_version']) ? substr((string)$body['app_version'], 0, 20) : null, $devId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['ok' => true, 'received' => count($ack), 'inserted' => $inserted, 'ack' => $ack];
    }

    private static function int($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v)) return $v;
        if (is_float($v) && is_finite($v)) return (int)round($v);
        if (is_string($v) && preg_match('/^-?\d{1,19}$/', $v)) return (int)$v;
        return null;
    }

    private static function float($v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $f = (float)$v;
        return is_finite($f) ? $f : null;
    }

    private static function range(?int $v, int $min, int $max): ?int
    {
        return ($v === null || $v < $min || $v > $max) ? null : $v;
    }

    private static function rangeF(?float $v, float $min, float $max): ?float
    {
        return ($v === null || $v < $min || $v > $max) ? null : $v;
    }

    private static function uuid($v): ?string
    {
        $v = strtolower((string)$v);
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) ? $v : null;
    }
}
