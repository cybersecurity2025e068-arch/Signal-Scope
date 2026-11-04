<?php
// يضمن وجود deploy_token في config.php (يُنشئه مرة واحدة) ويطبعه. يُستدعى من auto-setup.sh.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../bootstrap.php';
$file = getenv('SIGNALSCOPE_CONFIG') ?: SS_APP_DIR . '/config.php';
$cur = (string)(ss_config()['deploy_token'] ?? '');
if (strlen($cur) >= 24) { echo "exists $cur\n"; exit(0); }
$abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
$tok = '';
for ($i = 0; $i < 40; $i++) $tok .= $abc[random_int(0, strlen($abc) - 1)];
$src = (string)file_get_contents($file);
if (preg_match("/'deploy_token'\\s*=>/", $src)) {
    $new = preg_replace("/('deploy_token'\\s*=>\\s*)'[^']*'/", "\${1}'$tok'", $src, 1, $n);
} else {
    $new = preg_replace('/\];\s*$/', "    // رمز التحديث الذاتي من GitHub (POST /api/index.php?r=/v1/admin/deploy مع ترويسة X-Deploy-Token)\n    'deploy_token' => '$tok',\n];\n", $src, 1, $n);
}
if ($new === null || $n !== 1) { fwrite(STDERR, "could not write deploy_token\n"); exit(1); }
file_put_contents($file, $new);
chmod($file, 0600);
echo "created $tok\n";
