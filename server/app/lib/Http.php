<?php
declare(strict_types=1);

final class HttpError extends Exception
{
    public function __construct(public int $status, string $message, public string $errCode = 'error')
    {
        parent::__construct($message);
    }
}

final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    public static function body(int $maxBytes = 8_000_000): array
    {
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false) $raw = '';
        if (strlen($raw) > $maxBytes) throw new HttpError(413, 'الطلب أكبر من المسموح', 'too_large');

        $enc = strtolower($_SERVER['HTTP_CONTENT_ENCODING'] ?? '');
        if ($enc === 'gzip' || (strlen($raw) > 2 && $raw[0] === "\x1f" && $raw[1] === "\x8b")) {
            // بعض الخوادم تفك الضغط تلقائياً، لذلك نتحقق من التوقيع
            $dec = @gzdecode($raw, 64_000_000);
            if ($dec === false) throw new HttpError(400, 'تعذّر فك ضغط gzip', 'bad_gzip');
            $raw = $dec;
        }
        if ($raw === '') return [];
        $data = json_decode($raw, true, 32);
        if (!is_array($data)) throw new HttpError(400, 'JSON غير صالح', 'bad_json');
        return $data;
    }

    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
            }
        }
        // بديل لبعض استضافات cPanel التي تحذف ترويسة Authorization
        if ($h === '' && !empty($_SERVER['HTTP_X_DEVICE_TOKEN'])) return trim($_SERVER['HTTP_X_DEVICE_TOKEN']);
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m)) return $m[1];
        return null;
    }

    public static function ip(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
    }

    public static function intParam(string $name, int $default, int $min, int $max): int
    {
        $v = filter_var($_GET[$name] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v === null) return $default;
        return max($min, min($max, $v));
    }
}
