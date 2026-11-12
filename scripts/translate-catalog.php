<?php
// Developer utility. Sends only public UI strings to the configured Gemini provider.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['services.gemini.model'=>'gemini-3.1-flash-lite', 'services.gemini.timeout'=>120, 'services.gemini.max_output_tokens'=>16384]);
Illuminate\Support\Facades\Http::globalResponseMiddleware(function ($response) {
    if ($response->getStatusCode() >= 400) fwrite(STDERR, 'Provider HTTP '.$response->getStatusCode()."\n");
    return $response;
});
$locale = $argv[1] ?? '';
if (!in_array($locale, ['bn','hi','ur','ar','ne'], true)) { exit(2); }
$english = json_decode(file_get_contents(lang_path('en.json')), true);
$path = lang_path($locale.'.json');
$existing = is_file($path) ? json_decode(file_get_contents($path), true) : [];
$existing = array_intersect_key($existing, $english);
$missing = array_diff_key($english, $existing);
foreach (array_chunk(array_keys($missing), 40) as $chunk) {
    try {
        $result = retry(8, fn () => app(App\Services\GeminiApiService::class)->generateJson(
            json_encode(array_values($chunk), JSON_UNESCAPED_UNICODE),
            'Translate these public HumixAi interface strings into '.App\Support\Locales::all()[$locale].' ('.$locale.'). Return a JSON ARRAY of translated strings in the exact same order, one translation per input. Preserve placeholders such as :value1, :value2, :attribute EXACTLY in ASCII. Never translate placeholder identifiers. Keep product names and shortcuts. Translate every string naturally and professionally. This is UI localization, not user writing.', 0.2), 20000);
        if (count($result) !== count($chunk)) throw new RuntimeException('Wrong translation count.');
        $result = array_combine($chunk, array_values($result));
        foreach ($chunk as $key) {
            if (!isset($result[$key]) || !is_string($result[$key]) || trim($result[$key]) === '') throw new RuntimeException('Missing translation.');
            preg_match_all('/:[A-Za-z][A-Za-z0-9_]*/', $key, $before);
            preg_match_all('/:[A-Za-z][A-Za-z0-9_]*/', $result[$key], $after);
            sort($before[0]); sort($after[0]);
            if ($before[0] !== $after[0]) throw new RuntimeException('Placeholder mismatch.');
        }
        $existing = array_replace($existing, array_intersect_key($result, array_flip($chunk)));
        ksort($existing);
        file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
        echo $locale.' '.count($existing).'/'.count($english)."\n";
    } catch (Throwable $e) { fwrite(STDERR, $locale.' translation stopped: '.$e->getMessage()."\n"); exit(1); }
}
