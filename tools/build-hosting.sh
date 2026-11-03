#!/usr/bin/env bash
# يجهّز مجلد dist/hosting بنفس بنية حساب cPanel (مجلد المنزل /home/CPANELUSER):
#   public_html/api/        واجهة API (PHP)
#   public_html/dashboard/  لوحة التحكم
#   public_html/app/        نسخة الويب من التطبيق (PWA)
#   signalscope/            الكود الخاص والإعدادات (خارج public_html)
# الاستخدام: tools/build-hosting.sh [--zip]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/hosting"
rm -rf "$OUT" && mkdir -p "$OUT/public_html" "$OUT/signalscope"
cp -r "$ROOT/server/public/api" "$ROOT/server/public/dashboard" "$OUT/public_html/"
cp -r "$ROOT/web" "$OUT/public_html/app"
cp -r "$ROOT/server/app/." "$OUT/signalscope/"
rm -f "$OUT/signalscope/config.php"   # لا يُرفع أبداً: يُنشأ مرة واحدة على الخادم
if [[ "${1:-}" == "--zip" ]]; then
  rm -f "$ROOT/dist/signalscope-hosting.zip"
  (cd "$OUT" && zip -qr "$ROOT/dist/signalscope-hosting.zip" . -x '*.DS_Store')
  echo "$ROOT/dist/signalscope-hosting.zip"
fi
