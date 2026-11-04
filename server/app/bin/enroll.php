<?php
// يولّد رمز تسجيل هواتف جديداً قصيراً (ABCDE-23456) ويحفظه في config.php، ويطبعه.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../bootstrap.php';
$file = getenv('SIGNALSCOPE_CONFIG') ?: SS_APP_DIR . '/config.php';
$cur = (string)(ss_config()['enroll_key'] ?? '');
if (($argv[1] ?? '') !== '--force' && preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $cur)) { echo "unchanged $cur\n"; exit(0); }
$code = Auth::newCode();
$src = (string)file_get_contents($file);
$new = preg_replace("/('enroll_key'\\s*=>\\s*)'[^']*'/", "\${1}'$code'", $src, 1, $n);
if ($new === null || $n !== 1) { fwrite(STDERR, "enroll_key not found in config.php\n"); exit(1); }
file_put_contents($file, $new);
chmod($file, 0600);
echo "changed $code\n";
