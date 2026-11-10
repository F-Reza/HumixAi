<?php

namespace App\Services;

use App\Services\Exceptions\HumanizationException;

/** Deterministic span locking; protects surface text, not the truth of claims or complete semantics. */
class ProtectedTextService
{
    public function protect(string $text, array $terms = []): array
    {
        $patterns = [
            '/```[\s\S]*?```/u',
            '/https?:\/\/[^\s<>]+|\bdoi:\s*[^\s<>]+/iu',
            '/[\p{Lu}][\p{L}\p{M}\x{2019}\x{0027}-]+(?:\s+(?:et al\.|and\s+[\p{Lu}][\p{L}\p{M}-]+))?\s*\([^()\r\n]*\p{N}{4}[^()\r\n]*\)/u',
            '/\([^()\r\n]*\p{N}{4}[^()\r\n]*\)/u',
            '/\[\p{N}+(?:\s*[-\x{2013}\x{2014},;]\s*\p{N}+)*\]/u',
            '/"[^"\r\n]+"|\x{201C}[^\x{201D}\r\n]+\x{201D}|\x{2018}[^\x{2019}\r\n]+\x{2019}/u',
            '/(?<!\p{L})\x{0027}[^\x{0027}\r\n]+\x{0027}(?!\p{L})/u',
            '/(?<![\p{L}\p{M}])(?:not|never|no|without|cannot|[a-z]+n[\x{0027}\x{2019}]t|না|নয়|নয়|নেই|নন|হয়নি|হয়নি)(?![\p{L}\p{M}])/iu',
            '/\b(?:Dr|Mr|Mrs|Ms|Prof)\.?\s+[\p{Lu}][\p{L}\p{M}\x{2019}\x{0027}-]+(?:\s+[\p{Lu}][\p{L}\p{M}\x{2019}\x{0027}-]+)*/u',
            '/\b[A-Z]{2,}[0-9]*\b|\b[A-Z][a-z]+(?:[A-Z][A-Za-z]*)+\b/u',
            '/[+\x{2212}-]?\p{N}+(?:[.,:\/\x{2013}-]\p{N}+)*(?:\s?[%\x{2030}])?/u',
        ];
        foreach ($terms as $term) {
            if (is_string($term) && trim($term) !== '') {
                $patterns[] = '/'.preg_quote($term, '/').'/u';
            }
        }
        $ranges = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] ?? [] as [$match, $start]) {
                $ranges[] = ['start' => $start, 'end' => $start + strlen($match)];
            }
        }
        usort($ranges, fn ($a, $b) => ($a['start'] <=> $b['start']) ?: ($b['end'] <=> $a['end']));
        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range['start'] < $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $range['end']);
            } else {
                $merged[] = $range;
            }
        }
        $nonce = bin2hex(random_bytes(8));
        $map = [];
        $prepared = $text;
        foreach ($merged as $index => $range) {
            $map['__HUMIX_LOCK_'.$nonce.'_'.$index.'__'] = substr($text, $range['start'], $range['end'] - $range['start']);
        }
        for ($index = count($merged) - 1; $index >= 0; $index--) {
            $range = $merged[$index];
            $token = '__HUMIX_LOCK_'.$nonce.'_'.$index.'__';
            $prepared = substr_replace($prepared, $token, $range['start'], $range['end'] - $range['start']);
        }
        return ['text' => $prepared, 'map' => $map];
    }

    public function restoreAndValidate(string $rewritten, array $map, string $original): array
    {
        $violations = [];
        $previous = -1;
        foreach ($map as $token => $value) {
            if (substr_count($rewritten, $token) !== 1) {
                $violations[] = 'A protected passage was removed, duplicated or changed.';
                continue;
            }
            $position = strpos($rewritten, $token);
            if ($position < $previous) {
                $violations[] = 'Protected passages were reordered.';
            }
            $previous = $position;
        }
        if ($violations !== []) {
            throw new HumanizationException('The draft changed protected content. Your original text is unchanged; try a lighter rewrite.', 'preservation', array_values(array_unique($violations)));
        }
        $restored = strtr($rewritten, $map);
        if (preg_match('/__HUMIX_LOCK_[a-f0-9]+_\d+__/', $restored)) {
            throw new HumanizationException('The draft contains an invalid protected-content marker. Please try again.', 'preservation', ['Unexpected protection marker.']);
        }
        foreach (array_unique(array_values($map)) as $passage) {
            if ($this->occurrences($original, $passage) !== $this->occurrences($restored, $passage)) {
                throw new HumanizationException('The draft duplicated or removed protected content. Your original text is unchanged.', 'preservation', ['Protected passage counts differ from the original.']);
            }
        }
        // Detect newly introduced numbers, including numbers outside the locked spans.
        if ($this->numbers($original) !== $this->numbers($restored)) {
            throw new HumanizationException('The draft changed or introduced numbers. Your original text is unchanged; review the passage and try again.', 'preservation', ['Numbers differ from the original text.']);
        }
        return ['text' => $restored, 'preservation' => ['passed' => true, 'violations' => [], 'protected_count' => count($map)]];
    }

    private function occurrences(string $text, string $passage): int
    {
        $before = preg_match('/^[\p{L}\p{M}\p{N}]/u', $passage) ? '(?<![\p{L}\p{M}\p{N}])' : '';
        $after = preg_match('/[\p{L}\p{M}\p{N}]$/u', $passage) ? '(?![\p{L}\p{M}\p{N}])' : '';
        return preg_match_all('/'.$before.preg_quote($passage, '/').$after.'/u', $text);
    }

    private function numbers(string $text): array
    {
        preg_match_all('/[+\x{2212}-]?\p{N}+(?:[.,:\/\x{2013}-]\p{N}+)*(?:\s?[%\x{2030}])?/u', $text, $matches);
        return $matches[0] ?? [];
    }
}
