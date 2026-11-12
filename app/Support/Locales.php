<?php
namespace App\Support;
final class Locales {
    public static function all(): array { return ['en'=>'English','bn'=>'বাংলা','hi'=>'हिन्दी','ur'=>'اردو','ar'=>'العربية','ne'=>'नेपाली']; }
    public static function codes(): array { return array_keys(self::all()); }
    public static function normalize(?string $locale, string $default = 'en'): string { return in_array($locale, self::codes(), true) ? $locale : $default; }
    public static function direction(string $locale): string { return in_array($locale, ['ur','ar'], true) ? 'rtl' : 'ltr'; }
}
