<?php
declare(strict_types=1);

/**
 * إضافة روابط «منظومة راصد الإشارة» إلى بوابة القسم (/amu/index.html) على الاستضافة.
 * - إضافة فقط: كل كتلة محاطة بعلامتين <!-- raasid:BEGIN:x --> ... <!-- raasid:END:x -->
 *   فالتطبيق آمن للتكرار، والتراجع يزيل الكتل نفسها فقط ويعيد الملف كما كان.
 * - نسخة احتياطية: الأصل يُحفظ أول مرة في data/amu-index.original.html، وقبل كل تعديل في data/amu-index.before.html
 * - تستخدم أنماط البوابة الموجودة حرفياً (btn-gold، glass-box ...) فلا تحتاج CSS جديداً.
 */
final class AmuLink
{
    private const ICON = '<svg class="%s" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 13v8M8.2 7.2a5.4 5.4 0 000 7.6M15.8 7.2a5.4 5.4 0 010 7.6M5.4 4.4a9.4 9.4 0 000 13.2M18.6 4.4a9.4 9.4 0 010 13.2"/><circle cx="12" cy="11" r="1.7" stroke-width="2"/></svg>';

    /** @return array<string,string> مفتاح الكتلة => [المرساة, الموضع, المحتوى] */
    private static function blocks(): array
    {
        $header = '<a href="/raasid/" class="btn-gold flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs" aria-label="منظومة راصد الإشارة">'
            . "\n          " . sprintf(self::ICON, 'w-4 h-4') . "\n          <span>راصد الإشارة</span>\n        </a>";
        $card = '<a href="/raasid/" class="glass-box p-5 rounded-2xl flex items-center gap-4">'
            . "\n          " . '<div class="w-12 h-12 rounded-xl bg-amber-500/20 text-amber-900 dark:text-amber-400 flex items-center justify-center shrink-0 border border-amber-500/30">'
            . "\n            " . sprintf(self::ICON, 'w-6 h-6') . "\n          </div>\n          <div>"
            . "\n            " . '<h5 class="text-sm font-display font-extrabold text-gray-900 dark:text-white">منظومة راصد الإشارة</h5>'
            . "\n            " . '<p class="text-xs text-gray-700 dark:text-gray-400 font-medium">لوحة الراصد، وقياس الإشارة الخلوية وتشخيصها، وحوض الأمواج</p>'
            . "\n          </div>\n        </a>";
        $footer = '<a href="/raasid/" class="hover:text-amber-600 transition">راصد الإشارة</a>';
        return [
            'header' => ['<!-- Exam Committee / Kontrol Button -->', 'before', $header],
            'card'   => ['aria-label="خدمات البوابة"', 'section-end', $card],
            'footer' => ['<a href="library/" class="hover:text-amber-600 transition">الكتب الإلكترونية</a>', 'after', $footer],
        ];
    }

    public static function file(string $docRoot): string
    {
        return rtrim($docRoot, '/') . '/amu/index.html';
    }

    /** يزيل كل كتل راصد الإشارة السابقة */
    public static function strip(string $html): string
    {
        return (string)preg_replace('/\n?[ \t]*<!-- raasid:BEGIN:[a-z]+ -->.*?<!-- raasid:END:[a-z]+ -->/s', '', $html);
    }

    /** @return array<string,mixed> */
    public static function apply(string $docRoot): array
    {
        $f = self::file($docRoot);
        if (!is_file($f)) throw new RuntimeException('لم أجد ' . $f);
        $orig = (string)file_get_contents($f);
        $html = self::strip($orig);
        $done = [];
        foreach (self::blocks() as $key => [$anchor, $where, $content]) {
            $block = "<!-- raasid:BEGIN:$key -->\n        $content\n        <!-- raasid:END:$key -->";
            $pos = strpos($html, $anchor);
            if ($pos === false) { $done[$key] = 'anchor not found'; continue; }
            // كل كتلة تُدرج كـ "\n<مسافات>كتلة" عند نهاية سطر موجود، فإزالتها (strip) تعيد الملف بايتاً ببايت
            if ($where === 'after') {
                $at = $pos + strlen($anchor);
                $html = substr($html, 0, $at) . "\n            " . $block . substr($html, $at);
            } else {
                $target = $where === 'before' ? $pos : strpos($html, '</section>', $pos);
                if ($target === false) { $done[$key] = 'section end not found'; continue; }
                $nl = strrpos(substr($html, 0, $target), "\n");   // بداية سطر المرساة
                if ($nl === false) { $done[$key] = 'line not found'; continue; }
                $html = substr($html, 0, $nl) . "\n        " . $block . substr($html, $nl);
            }
            $done[$key] = 'ok';
        }
        if (!in_array('ok', $done, true)) throw new RuntimeException('لم تُطبَّق أي إضافة: ' . json_encode($done));
        self::backup($orig);
        self::write($f, $html);
        return ['ok' => true, 'blocks' => $done, 'bytes_before' => strlen($orig), 'bytes_after' => strlen($html)];
    }

    /** @return array<string,mixed> */
    public static function revert(string $docRoot): array
    {
        $f = self::file($docRoot);
        if (!is_file($f)) throw new RuntimeException('لم أجد ' . $f);
        $orig = (string)file_get_contents($f);
        $html = self::strip($orig);
        if ($html === $orig) return ['ok' => true, 'message' => 'لا توجد إضافات لإزالتها'];
        self::backup($orig);
        self::write($f, $html);
        return ['ok' => true, 'message' => 'أُزيلت إضافات راصد الإشارة', 'bytes_after' => strlen($html)];
    }

    private static function backup(string $content): void
    {
        $d = SS_APP_DIR . '/data';
        if (!is_file("$d/amu-index.original.html")) file_put_contents("$d/amu-index.original.html", self::strip($content));
        file_put_contents("$d/amu-index.before.html", $content);
    }

    private static function write(string $f, string $html): void
    {
        $tmp = $f . '.raasid-tmp';
        if (file_put_contents($tmp, $html) === false) throw new RuntimeException('تعذّر الكتابة في مجلد amu');
        @chmod($tmp, fileperms($f) & 0777);
        if (!rename($tmp, $f)) { @unlink($tmp); throw new RuntimeException('تعذّر استبدال index.html'); }
    }
}
