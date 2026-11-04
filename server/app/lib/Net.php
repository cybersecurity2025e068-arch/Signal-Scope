<?php
declare(strict_types=1);

/** طلبات HTTP من الخادم (curl إن وُجد، وإلا file_get_contents) */
final class Net
{
    public static function get(string $url, int $timeout = 60): string
    {
        return self::req('GET', $url, null, $timeout);
    }

    public static function post(string $url, string $body, int $timeout = 90): string
    {
        return self::req('POST', $url, $body, $timeout);
    }

    private static function req(string $method, string $url, ?string $body, int $timeout): string
    {
        $ua = 'SignalScope/' . SS_VERSION . ' (+https://github.com/cybersecurity2025e068-arch/Signal-Scope)';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout,
                  CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_USERAGENT => $ua];
            if ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $body; }
            curl_setopt_array($ch, $o);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($res === false || $code >= 400) throw new RuntimeException("HTTP $code $err");
            return (string)$res;
        }
        $ctx = stream_context_create(['http' => ['method' => $method, 'timeout' => $timeout, 'ignore_errors' => false,
            'header' => "User-Agent: $ua\r\n" . ($body !== null ? "Content-Type: application/x-www-form-urlencoded\r\n" : ''),
            'content' => $body ?? '']]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) throw new RuntimeException('تعذّر الاتصال: ' . $url);
        return $res;
    }
}
