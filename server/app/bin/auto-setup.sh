#!/bin/bash
# إعداد تلقائي على cPanel. يُشغَّل بعد كل Deploy من .cpanel.yml، وهو آمن للتكرار:
#  1) أول مرة فقط: ينشئ قاعدة MySQL ومستخدماً بكلمة مرور عشوائية، ويكتب config.php
#  2) ينشئ الجداول وحساب المدير الأول
#  3) يضيف مهام cron إن لم تكن موجودة
# النتيجة (الروابط وبيانات الدخول) تُكتب في ~/signalscope/SETUP-INFO.txt (مقروء لك فقط)
set -u
umask 077
APP="$(cd ~ && pwd)/signalscope"
CFG="$APP/config.php"
INFO="$APP/SETUP-INFO.txt"
U="$(id -un)"
log() { echo "[$(date '+%F %T')] $*"; }
rand() { head -c 96 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "${1:-24}"; }

# أحدث PHP 8 متاح (الأوامر تحتاج 8.1+)
PHP=""
for v in 84 83 82 81; do
  [ -x "/opt/cpanel/ea-php$v/root/usr/bin/php" ] && { PHP="/opt/cpanel/ea-php$v/root/usr/bin/php"; break; }
done
[ -z "$PHP" ] && PHP="$(command -v php || echo /usr/local/bin/php)"
# إعداد الاستضافة فيه session.gc_divisor=0 فيطبع PHP تحذيراً مع كل تشغيل؛ نصحّحه لأوامرنا
PHPX=("$PHP" -d session.gc_divisor=100 -d display_errors=stderr)
if ! "$PHP" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null; then
  log "PHP 8.1+ غير موجود ($PHP). فعّله من MultiPHP Manager ثم أعد Deploy."; exit 0
fi
jget() { "$PHP" -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(array_slice($argv,1) as $k){$v=$v[$k]??null;} echo is_scalar($v)?$v:json_encode($v);' "$@"; }

UAPI="$(command -v uapi || echo /usr/bin/uapi)"
DOMAIN=""
[ -x "$UAPI" ] && DOMAIN="$("$UAPI" --output=json DomainInfo list_domains 2>/dev/null | jget result data main_domain)"

# ---- PHP 8 لمجلد api فقط (لا يغيّر إصدار PHP لبقية الموقع) ----
HT="$(cd ~ && pwd)/public_html/api/.htaccess"
if [[ "$PHP" == /opt/cpanel/ea-php* ]] && [ -f "$HT" ] && ! grep -q "signalscope-php" "$HT"; then
  EA="$(echo "$PHP" | sed -E 's#^/opt/cpanel/(ea-php[0-9]+)/.*#\1#')"
  printf '\n# signalscope-php: يفرض %s على واجهة API\n<IfModule mime_module>\n  AddHandler application/x-httpd-%s .php\n</IfModule>\n' "$EA" "$EA" >> "$HT"
  log "api/ يعمل الآن على $EA"
fi
# ---- 1) قاعدة البيانات و config.php (مرة واحدة) ----
# config.php منسوخ من المثال ولم يُعدَّل بعد؟ نحتفظ به جانباً وننشئ واحداً صحيحاً
if [ -f "$CFG" ] && grep -q "CHANGE_ME\|CPANELUSER" "$CFG"; then
  mv "$CFG" "$CFG.unfilled.bak"; log "config.php لم يكن مكتملاً: نُقل إلى config.php.unfilled.bak"
fi
if [ ! -f "$CFG" ]; then
  if [ ! -x "$UAPI" ]; then
    log "أداة uapi غير متاحة: أنشئ القاعدة و config.php يدوياً (README)."; exit 0
  fi
  DB="${U}_signalscope"; DBU="${U}_ssapp"; DBP="$(rand 28)"
  ok() { [ "$(echo "$1" | jget result status)" = "1" ]; }
  R="$("$UAPI" --output=json Mysql create_database name="$DB")"
  ok "$R" || echo "$R" | grep -qi "exist" || { log "فشل إنشاء القاعدة: $(echo "$R" | jget result errors)"; exit 0; }
  R="$("$UAPI" --output=json Mysql create_user name="$DBU" password="$DBP")"
  if ! ok "$R"; then
    R="$("$UAPI" --output=json Mysql set_password user="$DBU" password="$DBP")"
    ok "$R" || { log "فشل إنشاء مستخدم القاعدة: $(echo "$R" | jget result errors)"; exit 0; }
  fi
  R="$("$UAPI" --output=json Mysql set_privileges_on_database user="$DBU" database="$DB" privileges="ALL%20PRIVILEGES")"
  ok "$R" || { log "فشل منح الصلاحيات: $(echo "$R" | jget result errors)"; exit 0; }

  ENROLL="$(rand 20)"; SETUP="$(rand 24)"
  cat > "$CFG" <<CONF
<?php
// أنشأه auto-setup.sh تلقائياً في $(date '+%F %T'). يمكنك تعديله يدوياً.
return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=$DB;charset=utf8mb4',
        'user' => '$DBU',
        'pass' => '$DBP',
    ],
    'enroll_key' => '$ENROLL',
    'setup_key' => '$SETUP',
    'opencellid_key' => '',
    'retention_days' => 365,
    'max_batch' => 5000,
    'max_gps_accuracy_m' => 50,
];
CONF
  chmod 600 "$CFG"
  log "أُنشئت القاعدة $DB والمستخدم $DBU و config.php"
fi

# ---- 2) الجداول وحساب المدير ----
OUT="$("${PHPX[@]}" "$APP/bin/install.php" 2>&1 | grep -v "PHP Startup")"
log "install: ${OUT%% *}"
ENROLL="$("$PHP" -r '$c=require $argv[1]; echo $c["enroll_key"];' "$CFG")"
write_info() { # $1 user  $2 password  $3 enroll key
  {
    echo "راصد الإشارة — بيانات التشغيل (أُنشئ $(date '+%F %T'))"
    echo "احذف هذا الملف بعد حفظ المعلومات في مكان آمن."
    echo
    echo "لوحة التحكم:   https://${DOMAIN:-نطاقك}/dashboard/"
    echo "اسم المستخدم:  $1"
    echo "كلمة المرور:   $2"
    echo
    echo "ربط الهاتف (التطبيق ← الإعدادات ← المزامنة مع الخادم):"
    echo "عنوان الخادم:  https://${DOMAIN:-نطاقك}"
    echo "رمز التسجيل:   $3"
    echo
    echo "فحص الخادم:    https://${DOMAIN:-نطاقك}/api/v1/ping"
  } > "$INFO"
  chmod 600 "$INFO"
}
if [[ "$OUT" == admin_created* ]]; then
  read -r _ AUSER APASS <<< "$OUT"
  write_info "$AUSER" "$APASS" "$ENROLL"
  log "أُنشئ حساب المدير؛ التفاصيل في $INFO"
fi

# ---- تدوير الأسرار عند تغيّر rotate.id ----
ROT_WANT="$(head -c 64 "$APP/rotate.id" 2>/dev/null | awk '{print $1}')"
ROT_DONE="$(cat "$APP/.rotated" 2>/dev/null || true)"
if [ -n "$ROT_WANT" ] && [ "$ROT_WANT" != "$ROT_DONE" ]; then
  if [[ "$OUT" == admin_created* ]]; then
    echo "$ROT_WANT" > "$APP/.rotated"   # تثبيت جديد: الأسرار جديدة أصلاً
  else
    R="$("${PHPX[@]}" "$APP/bin/rotate.php" 2>&1 | grep -v "PHP Startup")"
    if [[ "$R" == rotated* ]]; then
      read -r _ AUSER APASS ENROLL <<< "$R"
      write_info "$AUSER" "$APASS" "$ENROLL"
      echo "$ROT_WANT" > "$APP/.rotated"
      log "دُوّرت كلمة مرور المدير ورمز التسجيل؛ القيم الجديدة في $INFO"
    else
      log "فشل تدوير الأسرار: $R"
    fi
  fi
fi

# ---- 3) مهام cron ----
if command -v crontab >/dev/null 2>&1; then
  CUR="$(crontab -l 2>/dev/null || true)"
  if ! echo "$CUR" | grep -q "signalscope/cron/estimate_towers.php"; then
    { [ -n "$CUR" ] && echo "$CUR"
      echo "*/15 * * * * $PHP -d session.gc_divisor=100 $APP/cron/estimate_towers.php >/dev/null 2>&1"
      echo "17 3 * * * $PHP -d session.gc_divisor=100 $APP/cron/cleanup.php >/dev/null 2>&1"; } | crontab -
    log "أُضيفت مهام cron"
  fi
else
  log "crontab غير متاح: أضف مهام cron يدوياً (README)."
fi
if [ -n "$DOMAIN" ] && command -v curl >/dev/null 2>&1; then
  log "فحص: https://$DOMAIN/api/v1/ping → $(curl -s -m 15 "https://$DOMAIN/api/v1/ping" | head -c 200)"
fi

log "انتهى الإعداد"
