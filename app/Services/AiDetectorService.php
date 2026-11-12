<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/** GPTZero is the only source of authorship probabilities. Local statistics are quality signals only. */
class AiDetectorService
{
    private TextAnalysisService $analysis;

    public function __construct(?TextAnalysisService $analysis = null)
    {
        $this->analysis = $analysis ?? new TextAnalysisService();
    }

    public function status(): array
    {
        return [
            'provider' => 'gptzero', 'engine' => 'gptzero-api',
            'configured' => filled(config('services.gptzero.api_key')),
            'model_version' => config('services.gptzero.model_version') ?: null,
            'min_words' => max(1, (int) config('services.gptzero.min_words', 80)),
            'max_characters' => max(1, (int) config('services.gptzero.max_characters', 60000)),
            'supported_languages' => array_values(array_intersect(['en', 'bn', 'hi', 'ur', 'ar', 'ne', 'es', 'fr', 'de', 'pt'], (array) config('services.gptzero.languages', ['en', 'bn', 'hi', 'ur', 'ar', 'ne', 'es', 'fr', 'de', 'pt']))),
            'score_type' => 'ai_class_probability',
            'score_label' => __('Probability of GPTZero’s AI class'),
            'notes' => ['Configuration does not confirm provider connectivity or remaining quota.',
                'An AI probability is not the percentage of words written by AI or proof of authorship.',
                'Multilingual detection uses the provider model. Accuracy has not been independently evaluated for these languages.'],
        ];
    }

    public function analyzeLocal(string $text, string $language = 'auto'): array
    {
        $quality = $this->analysis->analyze($text, $language);
        return [
            'status' => 'unavailable', 'provider' => 'gptzero', 'engine' => 'local-writing-analysis',
            'model_version' => null, 'text_hash' => hash('sha256', $text),
            'language' => $quality['language'], 'word_count' => $quality['word_count'],
            'character_count' => $quality['character_count'], 'analyzed_characters' => 0,
            'scanned_at' => now()->utc()->toIso8601String(),
            'overall_score' => null, 'score_label' => __('Probability of GPTZero’s AI class'),
            'score_type' => 'ai_class_probability', 'classification' => null,
            'class_probabilities' => ['human' => null, 'mixed' => null, 'ai' => null],
            'confidence' => null, 'sentences' => $this->analysis->sentences($text),
            'language_evaluated' => false, 'language_notice' => __('Provider probabilities are estimates, not proof of authorship. Language-specific accuracy has not been evaluated by HumixAi.'),
            'quality' => $quality, 'message' => __('Local writing statistics only. No external authorship scan was performed.'),
        ];
    }

    public function detect(string $text, string $language = 'auto'): array
    {
        $result = $this->analyzeLocal($text, $language);
        $settings = $this->status();
        if ($result['word_count'] < $settings['min_words']) {
            return array_replace($result, ['status' => 'insufficient_text', 'message' => "Add at least {$settings['min_words']} words for an authorship scan. Local writing statistics are shown below."]);
        }
        if ($result['character_count'] > $settings['max_characters']) {
            return array_replace($result, ['status' => 'error', 'message' => "The text exceeds the {$settings['max_characters']}-character scan limit. No truncated scan was performed."]);
        }
        if (($result['quality']['language_mismatch'] ?? false) || !in_array($result['language'], $settings['supported_languages'], true)) {
            return array_replace($result, ['status' => 'unsupported_language', 'message' => __('Authorship detection is not enabled for this language. Local writing statistics do not establish authorship.')]);
        }
        if (!$settings['configured']) {
            return array_replace($result, ['message' => __('The AI detection provider is not configured. Only local writing statistics are available.')]);
        }
        $payload = ['document' => $text];
        if ($settings['model_version']) {
            $payload['modelVersion'] = $settings['model_version'];
        }
        try {
            // No retries: a scan can be billable. Never silently truncate a document.
            $response = Http::acceptJson()->withHeaders(['x-api-key' => config('services.gptzero.api_key')])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(10)->timeout(max(1, (int) config('services.gptzero.timeout', 45)))
                ->post(rtrim((string) config('services.gptzero.base_url', 'https://api.gptzero.me'), '/').'/v2/predict/text', $payload);
            if (!$response->successful()) {
                $unavailable = in_array($response->status(), [401, 403, 429], true) || $response->serverError();
                return array_replace($result, ['status' => $unavailable ? 'unavailable' : 'error',
                    'message' => $unavailable ? 'The detection provider is temporarily unavailable or has no scan allowance. Try again later.' : 'The detection provider could not analyze this document. Check its length and language.']);
            }
            $body = $response->json();
            $document = is_array($body) ? ($body['documents'][0] ?? null) : null;
            if (!is_array($document)) {
                return array_replace($result, ['status' => 'error', 'message' => __('The detection provider returned an invalid result. No authorship score is available.')]);
            }
            $probabilities = [];
            foreach (['human', 'mixed', 'ai'] as $class) {
                $value = $document['class_probabilities'][$class] ?? null;
                if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 1) {
                    return array_replace($result, ['status' => 'error', 'message' => __('The detection provider returned incomplete probabilities. No authorship score is available.')]);
                }
                $probabilities[$class] = round((float) $value * 100, 2);
            }
            if (abs(array_sum($probabilities) - 100) > 1) {
                return array_replace($result, ['status' => 'error', 'message' => __('The detection provider returned inconsistent probabilities. No authorship score is available.')]);
            }
            $classification = match ($document['predicted_class'] ?? $document['document_classification'] ?? null) {
                'ai', 'AI_ONLY' => 'AI_ONLY', 'mixed', 'MIXED' => 'MIXED', 'human', 'HUMAN_ONLY' => 'HUMAN_ONLY', default => null,
            };
            if ($classification === null) {
                return array_replace($result, ['status' => 'error', 'message' => __('The detection provider returned an unknown classification. No authorship score is available.')]);
            }
            $confidence = $document['confidence_category'] ?? null;
            return array_replace($result, [
                'status' => 'completed', 'engine' => 'gptzero-api',
                // A requested version does not prove which version actually ran.
                'model_version' => is_string($body['version'] ?? null) ? $body['version'] : null,
                'analyzed_characters' => $result['character_count'], 'overall_score' => $probabilities['ai'],
                'classification' => $classification, 'class_probabilities' => $probabilities,
                'confidence' => in_array($confidence, ['high', 'medium', 'low'], true) ? $confidence : null,
                'sentences' => $this->mapSentences($text, $document['sentences'] ?? []),
                'message' => __('Provider probabilities are estimates, not proof of authorship. Review the three classes and the writing context together.'),
            ]);
        } catch (Throwable) {
            // Never expose request URLs, credentials, submitted text or provider bodies.
            return array_replace($result, ['status' => 'unavailable', 'message' => __('The detection provider could not be reached. Local writing statistics remain available.')]);
        }
    }

    private function mapSentences(string $text, mixed $providerSentences): array
    {
        if (!is_array($providerSentences) || $providerSentences === []) {
            return $this->analysis->sentences($text);
        }
        $mapped = [];
        $cursor = 0;
        foreach ($providerSentences as $sentence) {
            if (!is_array($sentence) || !is_string($sentence['sentence'] ?? null) || $sentence['sentence'] === '') {
                continue;
            }
            $part = $sentence['sentence'];
            $start = mb_strpos($text, $part, $cursor, 'UTF-8');
            if ($start === false) {
                continue; // Do not invent offsets when provider text has been normalized.
            }
            $end = $start + mb_strlen($part, 'UTF-8');
            $probability = $sentence['generated_prob'] ?? null;
            $valid = is_numeric($probability) && is_finite((float) $probability) && $probability >= 0 && $probability <= 1;
            $highlight = ($sentence['highlight_sentence_for_ai'] ?? false) === true;
            $mapped[] = ['id' => count($mapped) + 1, 'text' => $part, 'start' => $start, 'end' => $end,
                'score' => $valid ? round((float) $probability * 100, 2) : null,
                'highlight' => $highlight, 'reason' => $highlight ? 'Highlighted by GPTZero as an AI-writing signal; this does not establish authorship.' : null];
            $cursor = $end;
        }
        return $mapped ?: $this->analysis->sentences($text);
    }

    public function splitSentences(string $text): array
    {
        return $this->analysis->sentences($text);
    }
}
