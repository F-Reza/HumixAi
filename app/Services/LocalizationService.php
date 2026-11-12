<?php
namespace App\Services;
use App\Support\Locales;
class LocalizationService {
    public function catalog(string $locale): array {
        $read = fn ($code) => is_file(lang_path($code.'.json')) ? (json_decode(file_get_contents(lang_path($code.'.json')), true) ?: []) : [];
        return array_replace($read('en'), $read(Locales::normalize($locale)));
    }
}
