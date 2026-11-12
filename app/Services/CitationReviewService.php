<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class CitationReviewService
{
    /**
     * Official endpoint: https://docs.gptzero.me/api-reference/#post-v2-bibliography-scan-text
     * The public reference documents the request, but does not expose a stable result schema.
     * Keep returned evidence as provider JSON rather than guessing citation-verification verdicts.
     */
    public function review(string $text): array
    {
        $result = [
            'status' => 'unavailable', 'provider' => 'gptzero', 'review_type' => 'citations',
            'summary' => __('Citation review is unavailable. No references were verified.'), 'issues' => [],
            'model' => null, 'source_hash' => hash('sha256', $text),
            'reviewed_at' => now()->utc()->toIso8601String(),
            'evidence' => null,
            'limitations' => [
                'Citation review uses GPTZero bibliography scanning and depends on its plan access and available sources.',
                'HumixAi presents provider JSON as evidence; it does not infer an all-clear verdict or fabricate sources from an unknown response schema.',
                'A found reference does not establish that the cited source supports the claim. Review the original publication yourself.',
                'This is not a plagiarism scan or complete factual verification.',
            ],
        ];
        $maximum = min(150000, max(1, (int) config('services.gptzero.max_characters', 60000)));
        if (trim($text) === '' || mb_strlen($text, 'UTF-8') > $maximum) {
            return array_replace($result, ['status' => 'error', 'summary' => "Provide a nonempty document within the {$maximum}-character citation scan limit."]);
        }
        if (!filled(config('services.gptzero.api_key'))) {
            return $result;
        }
        try {
            $response = Http::acceptJson()->withHeaders(['x-api-key' => config('services.gptzero.api_key')])
                ->connectTimeout(10)->timeout(max(1, (int) config('services.gptzero.timeout', 45)))
                ->post(rtrim((string) config('services.gptzero.base_url', 'https://api.gptzero.me'), '/').'/v2/bibliography-scan/text', ['document' => $text]);
            if (!$response->successful()) {
                return array_replace($result, ['summary' => __('The bibliography provider could not complete the review. Check provider access and allowance, then try again. No references were verified.')]);
            }
            if (strlen($response->body()) > 1000000) {
                return array_replace($result, ['status' => 'error', 'summary' => __('The bibliography result exceeded the review size limit. No verification verdict is available.')]);
            }
            $body = $response->json();
            if (!is_array($body) || $body === [] || array_is_list($body) || array_key_exists('error', $body)
                || ($body['success'] ?? null) === false || in_array($body['status'] ?? null, ['error', 'failed', 'unavailable'], true)) {
                return array_replace($result, ['status' => 'error', 'summary' => __('The bibliography provider returned an invalid result. No verification verdict is available.')]);
            }
            return array_replace($result, [
                'status' => 'completed',
                'summary' => __('GPTZero returned bibliography evidence. Inspect the provider JSON and original publications; HumixAi has not converted it into verification verdicts.'),
                'evidence' => ['format' => 'provider-json', 'data' => $body],
            ]);
        } catch (Throwable) {
            return array_replace($result, ['summary' => __('The bibliography provider could not be reached. No references were verified.')]);
        }
    }
}
