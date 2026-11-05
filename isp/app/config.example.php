<?php
// انسخه إلى config.php (يُنشأ تلقائياً عند أول Deploy على cPanel).
return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=CPANELUSER_isp;charset=utf8mb4',
        'user' => 'CPANELUSER_ispapp',
        'pass' => 'CHANGE_ME',
    ],
    'timezone' => 'Asia/Baghdad',
    // ربط اختياري مع FreeRADIUS (جداول radcheck / radreply / radacct) لتفعيل وإيقاف حسابات PPPoE فعلياً
    'radius' => [
        'enabled' => false,
        'dsn'  => '',          // فارغ = نفس قاعدة اللوحة
        'user' => '',
        'pass' => '',
        'rate_attr' => 'Mikrotik-Rate-Limit',  // صيغة القيمة: 5M/20M (رفع/تنزيل)
        'simultaneous_use' => 1,
        'bind_mac' => false,   // true = يُقبل الاتصال من عنوان MAC المسجّل للمشترك فقط
    ],
];
