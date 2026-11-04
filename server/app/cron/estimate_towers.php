<?php
// تقدير مواقع الأبراج للخلايا التي وصلتها قراءات جديدة.
// cPanel ← Cron Jobs، كل 15 دقيقة:
//   /usr/local/bin/php /home/CPANELUSER/signalscope/cron/estimate_towers.php >/dev/null 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../bootstrap.php';

$lock = fopen(sys_get_temp_dir() . '/signalscope_estimate.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0); // تشغيل سابق ما زال يعمل

$t0 = microtime(true);
$budget = (float)($argv[1] ?? 50); // ثوانٍ (أقل من حد زمن التنفيذ في الاستضافة)
$db = ss_db();
$maxAcc = (float)(ss_config()['max_gps_accuracy_m'] ?? 50);

$cells = $db->query('SELECT id, tech, est_lat, est_lon FROM cells WHERE dirty = 1 ORDER BY last_seen DESC LIMIT 500')->fetchAll();
$drop = $db->prepare('DELETE FROM sample_loss WHERE cell_ref = ?');
$q = $db->prepare('SELECT lat, lon, rsrp, rssi, ta, tech FROM samples
    WHERE cell_ref = ? AND lat IS NOT NULL AND (acc IS NULL OR acc <= ?) ORDER BY ts DESC LIMIT 20000');
$agg = $db->prepare('SELECT COUNT(*) n, AVG(COALESCE(rsrp, rssi)) r FROM samples WHERE cell_ref = ?');
$up = $db->prepare('UPDATE cells SET est_lat = ?, est_lon = ?, est_radius_m = ?, est_n = ?, est_method = ?, est_exponent = ?,
    est_updated = UTC_TIMESTAMP(), sample_count = ?, avg_rsrp = ?, dirty = 0 WHERE id = ?');
$done = 0;
foreach ($cells as $c) {
    if (microtime(true) - $t0 > $budget) break;
    $q->execute([$c['id'], $maxAcc]);
    $pts = [];
    foreach ($q as $r) {
        // لشبكات 2G/3G لا يوجد RSRP: نستخدم RSSI/RSCP بالمنطق نفسه
        $sig = $r['rsrp'] ?? $r['rssi'];
        $pts[] = ['lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'rsrp' => $sig === null ? null : (int)$sig,
                  'ta' => $r['ta'] === null ? null : (int)$r['ta'], 'tech' => $r['tech']];
    }
    $agg->execute([$c['id']]);
    $a = $agg->fetch();
    $e = TowerEstimator::estimate($pts);
    $up->execute([$e['lat'] ?? null, $e['lon'] ?? null, $e['radius'] ?? null, $e['n'] ?? 0, $e['method'] ?? null, $e['exponent'] ?? null,
                  (int)$a['n'], $a['r'] === null ? null : round((float)$a['r'], 1), $c['id']]);
    // موقع البرج تحرّك أكثر من 25 م ← تحليل العوائق السابق لهذه الخلية لم يعد صالحاً
    if ($c['est_lat'] !== null && isset($e['lat']) && Geo::dist((float)$c['est_lat'], (float)$c['est_lon'], $e['lat'], $e['lon']) > 25) $drop->execute([$c['id']]);
    $done++;
}

// OpenCelliD (اختياري): موقع البرج المعروف للمقارنة
$key = (string)(ss_config()['opencellid_key'] ?? '');
$ocid = 0;
if ($key !== '' && function_exists('curl_init')) {
    $rows = $db->query("SELECT id, tech, mcc, mnc, tac, cid FROM cells WHERE ocid_checked IS NULL AND tac IS NOT NULL LIMIT 40")->fetchAll();
    $set = $db->prepare('UPDATE cells SET ocid_lat = ?, ocid_lon = ?, ocid_range = ?, ocid_checked = UTC_TIMESTAMP() WHERE id = ?');
    foreach ($rows as $r) {
        if (microtime(true) - $t0 > $budget + 8) break;
        $radio = ['LTE' => 'LTE', 'NR' => 'NR', 'WCDMA' => 'UMTS', 'GSM' => 'GSM'][$r['tech']] ?? null;
        if (!$radio) { $set->execute([null, null, null, $r['id']]); continue; }
        $url = 'https://opencellid.org/cell/get?' . http_build_query(['key' => $key, 'mcc' => $r['mcc'], 'mnc' => $r['mnc'],
            'lac' => $r['tac'], 'cellid' => $r['cid'], 'radio' => $radio, 'format' => 'json']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'SignalScope/' . SS_VERSION]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 429) break; // تجاوز حد الطلبات اليومي: نكمل في الدورة التالية
        $j = is_string($body) ? json_decode($body, true) : null;
        if (is_array($j) && isset($j['lat'], $j['lon'])) {
            $set->execute([(float)$j['lat'], (float)$j['lon'], isset($j['range']) ? (int)$j['range'] : null, $r['id']]);
        } else {
            $set->execute([null, null, null, $r['id']]); // غير موجودة في القاعدة
        }
        $ocid++;
        usleep(200_000);
    }
}

// تنبيهات التدهور (مرة كل ساعة على الأكثر)
$alerts = null;
try { $alerts = Alerts::scanIfDue(); } catch (Throwable $ex) { $alerts = ['error' => $ex->getMessage()]; }
ss_cron_mark('estimate');

// عزل التشويش (تدريجي: القراءات الجديدة فقط)
$intf = null;
try { $intf = Interference::run(15.0); } catch (Throwable $ex) { $intf = ['error' => $ex->getMessage()]; }

// تحليل العوائق في الوقت المتبقي
$obs = [];
$olog = [];
try { $obs = Obstacles::run(max(10.0, $budget + 30 - (microtime(true) - $t0)), $olog); }
catch (Throwable $ex) { $olog[] = 'obstacles error: ' . $ex->getMessage(); }
printf("estimated=%d ocid=%d alerts=%s intf=%s obstacles=%s time=%.1fs\n%s\n", $done, $ocid, json_encode($alerts), json_encode($intf), json_encode($obs), microtime(true) - $t0, implode("\n", $olog));
