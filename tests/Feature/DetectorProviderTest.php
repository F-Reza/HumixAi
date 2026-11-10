<?php

namespace Tests\Feature;

use App\Services\AiDetectorService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DetectorProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gptzero.api_key' => 'test-private-key', 'services.gptzero.base_url' => 'https://api.gptzero.me',
            'services.gptzero.min_words' => 1, 'services.gptzero.model_version' => 'requested-version']);
        Http::preventStrayRequests();
    }

    private function payload(array $overrides = []): array
    {
        return ['documents' => [array_replace([
            'predicted_class' => 'mixed', 'class_probabilities' => ['human' => .1, 'mixed' => .7, 'ai' => .2],
            'confidence_category' => 'medium',
            'sentences' => [['sentence' => 'A café opens.', 'generated_prob' => .9, 'highlight_sentence_for_ai' => true],
                ['sentence' => 'A café opens.', 'generated_prob' => .1, 'highlight_sentence_for_ai' => false]],
        ], $overrides)], 'version' => 'actual-version'];
    }

    public function test_real_provider_payload_and_probability_semantics_are_preserved(): void
    {
        Http::fake(['api.gptzero.me/*' => Http::response($this->payload())]);
        $text = 'A café opens. A café opens.';
        $result = (new AiDetectorService())->detect($text);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(20.0, $result['overall_score']);
        $this->assertSame('MIXED', $result['classification']);
        $this->assertSame(['human' => 10.0, 'mixed' => 70.0, 'ai' => 20.0], $result['class_probabilities']);
        $this->assertSame('actual-version', $result['model_version']);
        $this->assertSame('medium', $result['confidence']);
        $this->assertSame(hash('sha256', $text), $result['text_hash']);
        $this->assertSame(mb_strlen($text), $result['analyzed_characters']);
        $this->assertGreaterThan($result['sentences'][0]['start'], $result['sentences'][1]['start']);
        foreach ($result['sentences'] as $sentence) {
            $this->assertSame($sentence['text'], mb_substr($text, $sentence['start'], $sentence['end'] - $sentence['start']));
        }
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.gptzero.me/v2/predict/text'
            && $request->hasHeader('x-api-key', 'test-private-key')
            && $request['document'] === $text && $request['modelVersion'] === 'requested-version');
        Http::assertSentCount(1);
    }

    public function test_offline_and_local_analysis_never_synthesize_an_ai_score(): void
    {
        config(['services.gptzero.api_key' => null]);
        Http::fake();
        $service = new AiDetectorService();
        foreach ([$service->detect('This is a normal sentence.'), $service->analyzeLocal('আমি বাংলা লিখি।')] as $result) {
            $this->assertSame('unavailable', $result['status']);
            $this->assertNull($result['overall_score']);
            $this->assertNull($result['confidence']);
            $this->assertNull($result['classification']);
            $this->assertSame(0, $result['analyzed_characters']);
            $this->assertArrayNotHasKey('detectors_breakdown', $result);
            $this->assertNotEmpty($result['quality']);
        }
        Http::assertNothingSent();
    }

    public function test_short_unsupported_and_oversized_inputs_do_not_make_requests(): void
    {
        Http::fake();
        $service = new AiDetectorService();
        config(['services.gptzero.min_words' => 80]);
        $this->assertSame('insufficient_text', $service->detect('Short text.')['status']);
        config(['services.gptzero.min_words' => 1]);
        $this->assertSame('unsupported_language', $service->detect('আমি বাংলা লিখি।', 'en')['status']);
        config(['services.gptzero.max_characters' => 3]);
        $this->assertSame('error', $service->detect('A longer sentence.')['status']);
        Http::assertNothingSent();
    }

    public function test_provider_failures_and_malformed_results_do_not_leak_or_guess(): void
    {
        $sequence = Http::sequence();
        foreach ([401, 403, 429, 500] as $status) {
            $sequence->push(['error' => 'sensitive-provider-message'], $status);
        }
        $sequence->push($this->payload(['class_probabilities' => ['ai' => 90]]));
        Http::fake(['*' => $sequence]);
        foreach ([401, 403, 429, 500] as $status) {
            $result = (new AiDetectorService())->detect('A sentence to scan.');
            $this->assertSame('unavailable', $result['status']);
            $this->assertNull($result['overall_score']);
            $this->assertStringNotContainsString('sensitive', json_encode($result));
        }
        $result = (new AiDetectorService())->detect('A sentence to scan.');
        $this->assertSame('error', $result['status']);
        $this->assertNull($result['overall_score']);
    }

    public function test_missing_optional_provider_sentence_scores_and_confidence_remain_null(): void
    {
        Http::fake(['*' => Http::response($this->payload(['confidence_category' => null, 'sentences' => [['sentence' => 'A café opens.']]]))]);
        $result = (new AiDetectorService())->detect('A café opens.');
        $this->assertSame('completed', $result['status']);
        $this->assertNull($result['confidence']);
        $this->assertNull($result['sentences'][0]['score']);
        $this->assertFalse($result['sentences'][0]['highlight']);
    }
}
