<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Official Walter web-similarity adapter; this does not infer AI authorship. */
class PlagiarismReviewService
{
    public function capabilities(): array
    {
        return [
            'configured' => trim((string) config('services.walter.api_key', '')) !== '' && $this->endpoint() !== null,
            'provider' => 'walter', 'score_type' => 'similarity', 'min_characters' => 50, 'max_words' => 25000,
            'requires_external_consent' => true,
            'documentation_url' => 'https://docs.walterwrites.ai/api-reference/plagiarism-checker',
            'coverage' => 'Provider web-source matching; coverage is not exhaustive.',
        ];
    }

    public function status(): array { return $this->capabilities(); }

    public function review(string $text): array
    {
        $base = [
            'status' => 'unavailable', 'provider' => 'walter', 'score_type' => 'similarity',
            'similarity_score' => null, 'scan_id' => null, 'source_hash' => hash('sha256', $text),
            'checked_at' => null, 'matches' => [],
            'summary' => 'Web similarity checking is not configured for this workspace.',
            'limitations' => [
                'Textual similarity is not an AI-authorship score or proof of plagiarism or academic misconduct.',
                'Matches depend on the provider’s web coverage and may omit paywalled, private, recent or unindexed sources.',
                'Quoted material, citations and common phrases can legitimately match. Review the original sources and context.',
                'Source titles and matches are provider data; this workspace does not independently fetch or verify those pages.',
            ],
        ];
        if (!mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text, 'UTF-8') < 50) {
            return array_replace($base, ['status' => 'insufficient_text', 'summary' => 'Add at least 50 characters of complete text for a web-similarity scan.']);
        }
        if (mb_strlen($text, 'UTF-8') > (int) config('studio.max_characters', 60000) || $this->providerWordCount($text) > 25000) {
            return array_replace($base, ['status' => 'error', 'summary' => 'The text exceeds the scan limits. No truncated scan was submitted.']);
        }
        if (!$this->capabilities()['configured']) { return $base; }
        try {
            $response = Http::acceptJson()->asJson()->withHeaders([
                'X-API-Key' => (string) config('services.walter.api_key'), 'Idempotency-Key' => (string) Str::uuid(),
            ])->connectTimeout(10)->timeout(max(5, min(120, (int) config('services.walter.timeout', 60))))
                ->withOptions(['allow_redirects' => false])->post($this->endpoint(), ['content' => $text]);
            if (!$response->successful()) {
                $code = $response->json('code');
                $message = match (true) {
                    in_array($response->status(), [401, 403], true) => 'The similarity provider is unavailable. Its key, scope or scan allowance needs administrator attention.',
                    $response->status() === 429 => 'The similarity provider is rate limited. Try again later.',
                    in_array($code, ['insufficient_content', 'invalid_payload'], true) => 'The provider could not scan this text. Submit complete sentences within its documented limits.',
                    default => 'The similarity provider could not complete the scan. No similarity score was reported.',
                };
                return array_replace($base, ['summary' => $message]);
            }
            if (strlen($response->body()) > 2 * 1024 * 1024) {
                return array_replace($base, ['status' => 'error', 'summary' => 'The provider response exceeded the safe response limit. No score was reported.']);
            }
            $data = $response->json();
            $normalized = is_array($data) ? $this->normalize($data, $text) : null;
            if ($normalized === null) {
                return array_replace($base, ['status' => 'error', 'summary' => 'The provider returned an invalid or incomplete similarity result. No score was reported.']);
            }
            return array_replace($base, $normalized, ['status' => 'completed', 'checked_at' => now()->utc()->toIso8601String()]);
        } catch (Throwable) {
            return array_replace($base, ['summary' => 'The similarity provider could not be reached. Your text has not been assigned a similarity score.']);
        }
    }

    private function endpoint(): ?string
    {
        $base = rtrim((string) config('services.walter.base_url', 'https://developer-portal.walterwrites.ai/api'), '/');
        $parts = parse_url($base);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) { return null; }
        return $base.(str_ends_with($base, '/api') ? '' : '/api').'/plagiarism/scan/';
    }

    private function normalize(array $data, string $text): ?array
    {
        if (($data['status'] ?? null) !== 'success' || !$this->fraction($data['plagiarism_score'] ?? null)
            || !is_array($data['sources'] ?? null) || !array_is_list($data['sources']) || count($data['sources']) > 200) { return null; }
        $matches = [];
        $textLength = mb_strlen($text, 'UTF-8');
        foreach ($data['sources'] as $source) {
            if (!is_array($source) || !is_string($source['url'] ?? null) || !$this->safeLink($source['url'])
                || !is_string($source['title'] ?? null) || mb_strlen($source['title'], 'UTF-8') > 1000
                || !is_array($source['matches'] ?? null) || !array_is_list($source['matches']) || count($source['matches']) > 200) { return null; }
            $excerpts = [];
            foreach ($source['matches'] as $match) {
                $range = is_array($match) ? ($match['submitted_range'] ?? null) : null;
                if (!is_array($range) || !is_int($range['start'] ?? null) || !is_int($range['end'] ?? null)
                    || $range['start'] < 0 || $range['end'] <= $range['start'] || $range['end'] > $textLength) { return null; }
                if (count($excerpts) < 8) {
                    $excerpts[] = mb_substr($text, $range['start'], min(1500, $range['end'] - $range['start']), 'UTF-8');
                }
            }
            $matches[] = [
                'source_url' => $source['url'], 'title' => $source['title'],
                'similarity' => $this->fraction($source['similarity_score'] ?? null) ? round($source['similarity_score'] * 100, 2) : null,
                'excerpts' => array_values(array_unique($excerpts)),
            ];
        }
        $score = round($data['plagiarism_score'] * 100, 2);
        return ['similarity_score' => $score, 'scan_id' => is_string($data['scan_id'] ?? null) ? mb_substr($data['scan_id'], 0, 200) : null,
            'matches' => $matches, 'summary' => 'The provider reported '.$score.'% textual similarity. Review the matched sources and context.'];
    }

    private function fraction(mixed $number): bool
    {
        return (is_float($number) || is_int($number)) && is_finite((float) $number) && $number >= 0 && $number <= 1;
    }

    private function safeLink(string $url): bool
    {
        if (strlen($url) > 4096 || !filter_var($url, FILTER_VALIDATE_URL)) { return false; }
        $parts = parse_url($url);
        return $parts && in_array($parts['scheme'] ?? '', ['https', 'http'], true) && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    private function providerWordCount(string $text): int
    {
        $spaced = preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', ' $0 ', $text);
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\x{200C}\x{200D}\x{2019}\x{0027}-]*/u', $spaced ?? $text, $matches);
        return count($matches[0]);
    }
}
