<?php

namespace App\Services;

/** Unicode-aware writing statistics. These never infer AI authorship. */
class TextAnalysisService
{
    public function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}\p{N}]+(?:[\x{2019}\x{0027}-][\p{L}\p{M}\p{N}]+)*/u', $text, $matches);
        return $matches[0] ?? [];
    }

    public function language(string $text, string $requested = 'auto'): string
    {
        if (preg_match('/[\x{0980}-\x{09FF}]/u', $text)) {
            return 'bn';
        }
        $requested = strtolower(trim($requested));
        $requested = match ($requested) { 'english' => 'en', 'bengali', 'bangla' => 'bn', default => $requested };
        if ($requested !== 'auto' && $requested !== '') {
            return $requested;
        }
        // A Latin script assumption, not a trained language classifier.
        if (preg_match('/\p{Latin}/u', $text) && !preg_match('/[^\p{Latin}\p{M}\p{N}\p{P}\p{S}\p{Z}\s]/u', $text)) {
            return 'en';
        }
        return 'und';
    }

    public function sentences(string $text): array
    {
        preg_match_all('/[.!?\x{0964}]+["\x{201D}\x{2019}\)\]]*(?=\s|$)|\r?\n+/u', $text, $boundaries, PREG_OFFSET_CAPTURE);
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
            'kind' => 'local-writing-statistics', 'language' => $this->language($text, $language),
            'word_count' => count($words), 'character_count' => mb_strlen($text, 'UTF-8'),
            'sentence_count' => count($sentences),
            'paragraph_count' => trim($text) === '' ? 0 : count(preg_split('/\R\s*\R/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)),
            'average_sentence_words' => round($average, 2),
            'sentence_length_standard_deviation' => round(sqrt($variance), 2),
            'long_sentence_count' => count(array_filter($counts, fn ($count) => $count > 30)),
            'vocabulary_diversity' => count($words) ? round(count($frequency) / count($words), 3) : null,
            'repeated_words' => $repeated,
            'reading_time_seconds' => count($words) ? (int) ceil(count($words) / 200 * 60) : 0,
            'notes' => ['Writing statistics describe style; they do not measure AI authorship.',
                'Word counts use Unicode letter/number groups; reading time assumes 200 words per minute.',
                'Automatic language selection recognizes Bengali script; other Latin text defaults to English unless you select a language.'],
        ];
    }
}
