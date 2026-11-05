<?php
// للنشر ضمن مستودع راصد الإشارة: إن لم يوجد config.php يُنشأ باستخدام قاعدة بيانات راصد الإشارة
// (جداول اللوحة لا تتعارض مع جداوله)، ويُثبَّت مسار التطبيق في public_html/isp/index.php.
// يُستدعى من سطر الأوامر: php link-config.php /path/to/public_html/isp/index.php
// أو بـ include من Updater مع تعريف $isp_index مسبقاً.
$isp_app = dirname(__DIR__);
$isp_cfg = $isp_app . '/config.php';
$isp_ss = dirname($isp_app) . '/signalscope/config.php';
$isp_out = [];
if (!is_file($isp_cfg) && is_file($isp_ss)) {
    $isp_c = require $isp_ss;
    if (is_array($isp_c) && !empty($isp_c['db']['dsn'])) {
        $isp_conf = [
            'db' => ['dsn' => $isp_c['db']['dsn'], 'user' => $isp_c['db']['user'], 'pass' => $isp_c['db']['pass']],
            'timezone' => 'Asia/Baghdad',
            'radius' => ['enabled' => false, 'dsn' => '', 'user' => '', 'pass' => '', 'rate_attr' => 'Mikrotik-Rate-Limit', 'simultaneous_use' => 1, 'bind_mac' => false],
        ];
        file_put_contents($isp_cfg, "<?php\n// أُنشئ تلقائياً " . date('Y-m-d H:i') . ": يستخدم قاعدة بيانات راصد الإشارة. يمكنك تعديله.\nreturn " . var_export($isp_conf, true) . ";\n");
        @chmod($isp_cfg, 0600);
        $isp_out[] = 'config created';
    }
}
$isp_index = isset($isp_index) ? $isp_index : (isset($argv[1]) ? $argv[1] : '');
if ($isp_index !== '' && is_file($isp_index)) {
    $isp_src = (string) file_get_contents($isp_index);
    $isp_new = str_replace("getenv('ISP_APP') ?: ''", "getenv('ISP_APP') ?: " . var_export($isp_app, true), $isp_src);
    if ($isp_new !== $isp_src) {
        file_put_contents($isp_index, $isp_new);
        $isp_out[] = 'index linked';
    }
}
if (PHP_SAPI === 'cli' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'link-config.php') {
    echo ($isp_out ? implode(', ', $isp_out) : 'nothing to do') . "\n";
}
return $isp_out;
