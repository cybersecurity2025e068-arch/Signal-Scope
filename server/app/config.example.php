<?php
// انسخ هذا الملف باسم config.php في المجلد نفسه وعدّل القيم.
// هذا المجلد يجب أن يكون خارج public_html حتى لا تُكشف كلمات المرور.
return [
    // من cPanel ← MySQL Databases: أنشئ قاعدة ومستخدماً وامنحه ALL PRIVILEGES
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=CPANELUSER_signalscope;charset=utf8mb4',
        'user' => 'CPANELUSER_ss',
        'pass' => 'CHANGE_ME',
    ],

    // رمز التسجيل الذي يُدخَل في التطبيق لإضافة جهاز جديد. غيّره متى شئت؛
    // الأجهزة المسجّلة سابقاً لا تتأثر.
    'enroll_key' => 'CHANGE-ME-ENROLL',

    // رمز يُطلب مرة واحدة لإنشاء أول حساب مدير من لوحة التحكم.
    'setup_key' => 'CHANGE-ME-SETUP',

    // اختياري: مفتاح OpenCelliD (مجاني من opencellid.org) لجلب مواقع الأبراج المعروفة
    'opencellid_key' => '',

    // حذف القراءات الخام بعد هذه المدة (بالأيام). تبقى الجلسات والخلايا وتقديرات الأبراج.
    'retention_days' => 365,

    // أقصى عدد قراءات في طلب رفع واحد
    'max_batch' => 5000,

    // تجاهل نقاط GPS الأسوأ دقة من هذا (متر) عند تقدير موقع البرج
    'max_gps_accuracy_m' => 50,

    // رمز التحديث الذاتي من GitHub (40 حرفاً؛ يُنشئه الإعداد التلقائي). فارغ = معطّل
    'deploy_token' => '',
];
