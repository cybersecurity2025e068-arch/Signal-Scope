#!/bin/bash
# إعداد تلقائي على cPanel بعد كل Deploy (آمن للتكرار):
#  1) أول مرة: قاعدة MySQL ومستخدم بكلمة مرور عشوائية و config.php
#  2) الجداول وحساب المدير الأول ← ~/isppanel/SETUP-INFO.txt
#  3) مهمة cron اليومية
set -u
umask 077
HOME_DIR="$(cd ~ && pwd)"
APP="$HOME_DIR/isppanel"
CFG="$APP/config.php"
INFO="$APP/SETUP-INFO.txt"
U="$(id -un)"
cd "$APP" || exit 0
log() { echo "[$(date '+%F %T')] $*"; }
rand() { head -c 192 /dev/urandom | base64 | tr -dc 'A-HJ-NP-Za-km-np-z2-9' | head -c "${1:-24}"; }

PHP=""
for v in 84 83 82 81 80 74; do
  [ -x "/opt/cpanel/ea-php$v/root/usr/bin/php" ] && { PHP="/opt/cpanel/ea-php$v/root/usr/bin/php"; break; }
done
[ -z "$PHP" ] && PHP="$(command -v php || echo /usr/local/bin/php)"
PHPX=("$PHP" -d session.gc_divisor=100 -d display_errors=stderr -d error_log="$APP/php_error.log")
if ! "$PHP" -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);' 2>/dev/null; then
  log "PHP 7.4+ غير موجود ($PHP)."; exit 0
fi
jget() { "$PHP" -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(array_slice($argv,1) as $k){$v=$v[$k]??null;} echo is_scalar($v)?$v:json_encode($v);' "$@"; }

UAPI="$(command -v uapi || echo /usr/bin/uapi)"
DOMAIN=""
[ -x "$UAPI" ] && DOMAIN="$("$UAPI" --output=json DomainInfo list_domains 2>/dev/null | jget result data main_domain)"

# إن كان النطاق يقرأ من مجلد غير public_html ننسخ isp إليه أيضاً
PUB="$HOME_DIR/public_html"
DOCROOT=""
[ -x "$UAPI" ] && [ -n "$DOMAIN" ] && DOCROOT="$("$UAPI" --output=json DomainInfo single_domain_data domain="$DOMAIN" 2>/dev/null | jget result data documentroot)"
if [ -n "$DOCROOT" ] && [ -d "$DOCROOT" ] && [ "$(cd "$DOCROOT" && pwd -P)" != "$(cd "$PUB" && pwd -P)" ]; then
  mkdir -p "$DOCROOT/isp" && cp -R "$PUB/isp/." "$DOCROOT/isp/"
  sed -i "s#getenv('ISP_APP') ?: ''#getenv('ISP_APP') ?: '$APP'#" "$DOCROOT/isp/index.php"
  log "نُسخت isp إلى $DOCROOT"
fi

# ---- 1) قاعدة البيانات ----
if [ -f "$CFG" ] && grep -q "CHANGE_ME\|CPANELUSER" "$CFG"; then
  mv "$CFG" "$CFG.unfilled.bak"
fi
if [ ! -f "$CFG" ]; then
  [ -x "$UAPI" ] || { log "uapi غير متاح: أنشئ القاعدة و config.php يدوياً (README)."; exit 0; }
  DB="${U}_isp"; DBU="${U}_ispapp"; DBP="$(rand 28)"
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
  sed -e "s#CPANELUSER_isp;#${DB};#" -e "s#CPANELUSER_ispapp#${DBU}#" -e "s#CHANGE_ME#${DBP}#" "$APP/config.example.php" > "$CFG"
  chmod 600 "$CFG"
  log "أُنشئت القاعدة $DB و config.php"
fi

# ---- 2) الجداول وحساب المدير ----
OUT="$("${PHPX[@]}" "$APP/bin/install.php" 2>&1 | grep -v "PHP Startup")"
log "install: ${OUT%% *}"
if [[ "$OUT" == admin_created* ]]; then
  read -r _ AUSER APASS <<< "$OUT"
  {
    echo "لوحة إدارة المشتركين — بيانات الدخول (أُنشئ $(date '+%F %T'))"
    echo "احذف هذا الملف بعد حفظ المعلومات في مكان آمن، وغيّر كلمة المرور من الملف الشخصي."
    echo
    echo "اللوحة:          https://${DOMAIN:-نطاقك}/isp/"
    echo "اسم المستخدم:    $AUSER"
    echo "كلمة المرور:     $APASS"
    echo
    echo "بوابة المشترك:   https://${DOMAIN:-نطاقك}/isp/index.php?p=portal"
  } > "$INFO"
  chmod 600 "$INFO"
  log "أُنشئ حساب المدير؛ التفاصيل في $INFO"
elif [[ "$OUT" != ok* ]]; then
  log "خطأ في التثبيت: $OUT"
fi

# ---- 3) cron ----
if command -v crontab >/dev/null 2>&1; then
  CUR="$(crontab -l 2>/dev/null || true)"
  if ! echo "$CUR" | grep -q "isppanel/cron/daily.php"; then
    { [ -n "$CUR" ] && echo "$CUR"; echo "23 4 * * * $PHP -d session.gc_divisor=100 $APP/cron/daily.php >/dev/null 2>&1"; } | crontab -
    log "أُضيفت مهمة cron اليومية"
  fi
fi
log "انتهى الإعداد"
