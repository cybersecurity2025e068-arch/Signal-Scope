<?php
declare(strict_types=1);

/**
 * التحقق من رمز دخول Firebase (ID token) على الخادم، بلا مكتبات خارجية.
 * الرمز JWT موقّع بـ RS256 بمفاتيح Google العامة (securetoken@system.gserviceaccount.com).
 * نتحقق من: التوقيع، والجمهور aud = معرّف المشروع، والمُصدر iss، وأوقات exp/iat/auth_time، و sub.
 * المرجع: https://firebase.google.com/docs/auth/admin/verify-id-tokens#verify_id_tokens_using_a_third-party_jwt_library
 */
final class FirebaseToken
{
    public const CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    public static function project(): string
    {
        return (string)(ss_config()['firebase_project'] ?? 'almaqal-uni');
    }

    private static function b64(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($r === false) throw new HttpError(401, 'رمز الدخول تالف', 'bad_id_token');
        return $r;
    }

    /** مفاتيح Google العامة، مخزنة ساعة (تُجدَّد فوراً إن ظهر مفتاح جديد) */
    private static function certs(bool $refresh = false): array
    {
        $f = SS_APP_DIR . '/data/firebase-certs.json';
        if (!$refresh && is_file($f) && time() - (int)filemtime($f) < 3600) {
            $j = json_decode((string)file_get_contents($f), true);
            if (is_array($j) && $j) return $j;
        }
        $url = (string)(ss_config()['firebase_certs_url'] ?? self::CERTS_URL);
        try { $j = json_decode(Net::get($url, 20), true); }
        catch (Throwable $e) { $j = null; }
        if (!is_array($j) || !$j) {
            if (is_file($f)) return (array)json_decode((string)file_get_contents($f), true);
            throw new HttpError(503, 'تعذّر جلب مفاتيح التحقق من Google. حاول بعد قليل', 'certs_unavailable');
        }
        @file_put_contents($f, json_encode($j));
        return $j;
    }

    /** @return array<string,mixed> بيانات الرمز بعد التحقق */
    public static function verify(string $jwt): array
    {
        $p = explode('.', trim($jwt));
        if (count($p) !== 3) throw new HttpError(401, 'رمز الدخول تالف', 'bad_id_token');
        $h = json_decode(self::b64($p[0]), true);
        $c = json_decode(self::b64($p[1]), true);
        if (!is_array($h) || !is_array($c) || ($h['alg'] ?? '') !== 'RS256' || empty($h['kid'])) throw new HttpError(401, 'رمز الدخول تالف', 'bad_id_token');
        $certs = self::certs();
        if (!isset($certs[$h['kid']])) $certs = self::certs(true);
        if (!isset($certs[$h['kid']])) throw new HttpError(401, 'مفتاح التوقيع غير معروف', 'bad_id_token');
        $key = openssl_pkey_get_public((string)$certs[$h['kid']]);
        if (!$key || openssl_verify($p[0] . '.' . $p[1], self::b64($p[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new HttpError(401, 'توقيع رمز الدخول غير صحيح', 'bad_id_token');
        }
        $proj = self::project();
        $now = time();
        if (($c['aud'] ?? '') !== $proj || ($c['iss'] ?? '') !== 'https://securetoken.google.com/' . $proj) throw new HttpError(401, 'الرمز ليس لمشروع البوابة', 'bad_id_token');
        if ((int)($c['exp'] ?? 0) < $now - 30) throw new HttpError(401, 'انتهت صلاحية رمز الدخول. أعد تسجيل الدخول', 'expired_id_token');
        if ((int)($c['iat'] ?? PHP_INT_MAX) > $now + 120 || (int)($c['auth_time'] ?? PHP_INT_MAX) > $now + 120) throw new HttpError(401, 'وقت رمز الدخول غير صالح', 'bad_id_token');
        if (!is_string($c['sub'] ?? null) || $c['sub'] === '' || strlen($c['sub']) > 128) throw new HttpError(401, 'رمز الدخول تالف', 'bad_id_token');
        return $c;
    }
}
