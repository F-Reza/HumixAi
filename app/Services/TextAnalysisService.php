<?php

namespace App\Services;

/** Unicode-aware writing statistics. These never infer AI authorship. */
class TextAnalysisService
{
    public function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\x{200C}\x{200D}]*(?:[\x{2019}\x{0027}-][\p{L}\p{N}][\p{L}\p{M}\p{N}\x{200C}\x{200D}]*)*/u', $text, $matches);
        return $matches[0] ?? [];
    }

    public function language(string $text, string $requested = 'auto'): string
    {
        return $this->languageInfo($text, $requested)['language'];
    }

    public function languageInfo(string $text, string $requested = 'auto'): array
    {
        $requested = $this->normalizeLanguage($requested);
        $scripts = [];
        foreach (['Latin', 'Bengali', 'Devanagari', 'Arabic'] as $script) {
            if (preg_match('/(?=\p{L})\p{'.$script.'}/u', $text)) { $scripts[] = $script; }
        }
        preg_match_all('/\p{L}/u', $text, $letters);
        $known = 0;
        foreach (['Latin', 'Bengali', 'Devanagari', 'Arabic'] as $script) {
            $known += preg_match_all('/(?=\p{L})\p{'.$script.'}/u', $text);
        }
        if ($known < count($letters[0])) { $scripts[] = 'Other'; }
        $expected = match ($requested) { 'en', 'es', 'fr', 'de', 'pt' => 'Latin', 'bn' => 'Bengali', 'hi', 'ne' => 'Devanagari', 'ur', 'ar' => 'Arabic', default => null };
        $compatible = $expected !== null && ($scripts === [] || (in_array($expected, $scripts, true) && array_diff($scripts, [$expected, 'Latin']) === []));
        if ($compatible && $requested !== 'auto') {
            return ['language' => $requested, 'requested_language' => $requested, 'language_source' => 'requested', 'language_mismatch' => false, 'language_candidates' => [$requested], 'scripts' => $scripts];
        }
        $nonLatin = array_values(array_diff($scripts, ['Latin']));
        $candidates = match (true) {
            $nonLatin === ['Bengali'] => ['bn'], $nonLatin === ['Devanagari'] => ['hi', 'ne'],
            $nonLatin === ['Arabic'] => ['ar', 'ur'], $scripts === ['Latin'] => ['en'], default => [],
        };
        return ['language' => count($candidates) === 1 ? $candidates[0] : 'und', 'requested_language' => $requested,
            'language_source' => count($candidates) === 1 ? 'script_hint' : 'undetermined',
            'language_mismatch' => $requested !== 'auto' && !$compatible, 'language_candidates' => $candidates, 'scripts' => $scripts];
    }

    public function normalizeLanguage(string $language): string
    {
        return match (strtolower(trim($language))) {
            '', 'auto' => 'auto', 'english' => 'en', 'bengali', 'bangla' => 'bn', 'hindi' => 'hi',
            'urdu' => 'ur', 'arabic' => 'ar', 'nepali' => 'ne', default => strtolower(trim($language)),
        };
    }

    public function sentences(string $text): array
    {
        preg_match_all('/[.!?\x{061F}\x{06D4}\x{0964}\x{0965}]+["\x{201D}\x{2019}\x{00BB}\)\]]*(?=\s|$)|\r?\n+/u', $text, $boundaries, PREG_OFFSET_CAPTURE);
        $sentences = [];
        $cursor = 0;
        foreach ($boundaries[0] as [$ending, $offset]) {
            $end = $offset + strlen($ending);
            $part = substr($text, $cursor, $end - $cursor);
            if (preg_match('/(?:\b(?:Mr|Mrs|Ms|Dr|Prof|Fig|vs|vol|pp|approx|dept)|\bet al|\be\.g|\bi\.e)\.$/iu', rtrim($part))) {
                continue;
            }
            $this->appendSentence($sentences, $text, $cursor, $part);
            $cursor = $end;
        }
        $this->appendSentence($sentences, $text, $cursor, substr($text, $cursor));
        return $sentences;
    }

    private function appendSentence(array &$sentences, string $text, int $byteStart, string $part): void
    {
        preg_match('/^\s*/u', $part, $leading);
        $part = preg_replace('/^\s+|\s+$/u', '', $part) ?? $part;
        if ($part === '') {
            return;
        }
        $start = mb_strlen(substr($text, 0, $byteStart + strlen($leading[0] ?? '')), 'UTF-8');
        $sentences[] = ['id' => count($sentences) + 1, 'text' => $part, 'start' => $start,
            'end' => $start + mb_strlen($part, 'UTF-8'), 'score' => null, 'highlight' => false, 'reason' => null];
    }

    public function analyze(string $text, string $language = 'auto'): array
    {
        $words = $this->words($text);
        $sentences = $this->sentences($text);
        $counts = array_map(fn ($sentence) => count($this->words($sentence['text'])), $sentences);
        $average = count($counts) ? array_sum($counts) / count($counts) : 0;
        $variance = count($counts) ? array_sum(array_map(fn ($count) => ($count - $average) ** 2, $counts)) / count($counts) : 0;
        $frequency = array_count_values(array_map(fn ($word) => mb_strtolower($word, 'UTF-8'), $words));
        arsort($frequency);
        $repeated = [];
        foreach ($frequency as $word => $count) {
            if ($count >= 3 && mb_strlen((string) $word, 'UTF-8') > 3) {
                $repeated[] = ['word' => (string) $word, 'count' => $count];
            }
            if (count($repeated) >= 8) {
                break;
            }
        }
        return [
            'kind' => 'local-writing-statistics', ...$this->languageInfo($text, $language),
            'word_count' => count($words), 'character_count' => mb_strlen($text, 'UTF-8'),
            'sentence_count' => count($sentences),
            'paragraph_count' => trim($text) === '' ? 0 : count(preg_split('/\R\s*\R/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)),
            'average_sentence_words' => round($average, 2),
            'sentence_length_standard_deviation' => round(sqrt($variance), 2),
            'long_sentence_count' => count(array_filter($counts, fn ($count) => $count > 30)),
            'vocabulary_diversity' => count($words) ? round(count($frequency) / count($words), 3) : null,
            'repeated_words' => $repeated,
            'reading_time_seconds' => count($words) ? (int) ceil(count($words) / 200 * 60) : 0,
            'notes' => [__('Writing statistics describe style; they do not measure AI authorship.'),
                __('Word counts use Unicode letter/number groups; reading time is a rough estimate of 200 words per minute, not a language-specific readability grade.'),
                __('Script hints cannot distinguish Hindi from Nepali or Arabic from Urdu. Select the source language when it is known.')],
        ];
    }
}
