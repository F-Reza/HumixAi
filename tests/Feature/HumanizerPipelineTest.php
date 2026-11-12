<?php

namespace Tests\Feature;

use App\Services\AiDetectorService;
use App\Services\AiHumanizerService;
use App\Services\Exceptions\HumanizationException;
use App\Services\GeminiApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HumanizerPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'test-writing-key', 'services.gemini.model' => 'gemini-3.8-flash',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gptzero.api_key' => 'test-detection-key', 'services.gptzero.min_words' => 1]);
        Http::preventStrayRequests();
    }

    public function test_plain_prose_prompt_does_not_suggest_protection_markers(): void
    {
        Http::fake(function (Request $request) {
            $instruction = $request['systemInstruction']['parts'][0]['text'];
            $this->assertStringNotContainsString('__HUMIX_LOCK_', $instruction);
            $this->assertStringContainsString('no protected-content markers', $instruction);
            return Http::response($this->writingResponse('Clear writing makes complex ideas easier to understand.'));
        });
        $result = $this->service()->humanize('Good writing helps readers understand complex ideas.', 'professional', false);
        $this->assertTrue($result['preservation']['passed']);
    }

    public function test_protected_markers_include_meaning_reference_without_relaxing_restore_checks(): void
    {
        Http::fake(function (Request $request) {
            $input=json_decode($request['contents'][0]['parts'][0]['text'],true);
            $this->assertContains('not',array_values($input['protected_reference_only']));
            foreach(array_keys($input['protected_reference_only']) as $marker)$this->assertStringContainsString($marker,$input['source_text']);
            return Http::response($this->writingResponse($input['source_text']));
        });
        $result=$this->service()->humanize('This proposal is not approved for publication.', 'professional', false);
        $this->assertSame('This proposal is not approved for publication.',$result['humanized_text']);
    }

    private function service(): AiHumanizerService
    {
        return new AiHumanizerService(new GeminiApiService(), new AiDetectorService());
    }

    private function writingResponse(string $text, string $finish = 'STOP'): array
    {
        return ['candidates' => [['finishReason' => $finish, 'content' => ['parts' => [['text' => $text]]]]]];
    }

    private function detectorResponse(float $score, string $version = 'same-version'): array
    {
        return ['documents' => [['predicted_class' => 'ai', 'class_probabilities' => ['human' => 1 - $score, 'mixed' => 0, 'ai' => $score], 'confidence_category' => 'high']], 'version' => $version];
    }

    public function test_humanize_only_preserves_locks_and_does_not_scan(): void
    {
        $source = 'Miller et al. (2022, p. 45) found 42% agreement [1-3]. "Keep this quote." HumixAi is helpful.';
        Http::fake(function (Request $request) {
            $this->assertStringContainsString('generativelanguage.googleapis.com', $request->url());
            $this->assertTrue($request->hasHeader('x-goog-api-key', 'test-writing-key'));
            $this->assertStringNotContainsString('test-writing-key', $request->url());
            $this->assertArrayNotHasKey('temperature', $request['generationConfig']);
            $data = json_decode($request['contents'][0]['parts'][0]['text'], true);
            return Http::response($this->writingResponse(str_replace('is helpful', 'helps readers', $data['source_text'])));
        });
        $events = [];
        $result = $this->service()->humanize($source, 'academic', false, ['protected_terms' => ['HumixAi']], function (...$event) use (&$events) { $events[] = $event; });
        $this->assertSame(str_replace('is helpful', 'helps readers', $source), $result['humanized_text']);
        $this->assertTrue($result['preservation']['passed']);
        $this->assertGreaterThanOrEqual(5, $result['preservation']['protected_count']);
        $this->assertNull($result['original_detection']);
        $this->assertNull($result['verification']);
        $this->assertNull($result['score_change']);
        $this->assertFalse($result['comparable']);
        $this->assertNotEmpty($events);
        Http::assertSentCount(1);
    }

    public function test_one_step_scans_both_versions_and_reports_signed_change(): void
    {
        $scans = 0;
        Http::fake(function (Request $request) use (&$scans) {
            if (str_contains($request->url(), 'gptzero')) {
                return Http::response($this->detectorResponse(++$scans === 1 ? .6 : .8));
            }
            return Http::response($this->writingResponse('A clearer version of this passage.'));
        });
        $result = $this->service()->humanize('The original version of this passage.');
        $this->assertTrue($result['comparable']);
        $this->assertSame(20.0, $result['score_change']);
        $this->assertSame(60.0, $result['original_score']);
        $this->assertSame(80.0, $result['humanized_score']);
        Http::assertSentCount(3);
    }

    public function test_model_change_prevents_score_comparison(): void
    {
        $scans = 0;
        Http::fake(function (Request $request) use (&$scans) {
            return Http::response(str_contains($request->url(), 'gptzero')
                ? $this->detectorResponse(.6, 'version-'.++$scans)
                : $this->writingResponse('The revised passage.'));
        });
        $result = $this->service()->humanize('The original passage.');
        $this->assertFalse($result['comparable']);
        $this->assertNull($result['score_change']);
    }

    public function test_failed_recheck_keeps_the_generated_draft_without_a_fake_score(): void
    {
        $scans = 0;
        Http::fake(function (Request $request) use (&$scans) {
            if (str_contains($request->url(), 'gptzero')) {
                return ++$scans === 1 ? Http::response($this->detectorResponse(.6)) : Http::response([], 429);
            }
            return Http::response($this->writingResponse('The revised passage.'));
        });
        $result = $this->service()->humanize('The original passage.');
        $this->assertSame('The revised passage.', $result['humanized_text']);
        $this->assertSame('unavailable', $result['verification']['status']);
        $this->assertNull($result['humanized_score']);
        $this->assertNull($result['score_change']);
    }

    public function test_later_duplicate_selection_replaces_only_the_requested_occurrence(): void
    {
        Http::fake(['*' => Http::response($this->writingResponse('New phrasing.'))]);
        $prefix = "  Start. Old phrasing.\n";
        $result = $this->service()->humanize($prefix.'Old phrasing. End.  ', 'professional', false,
            ['selection' => 'Old phrasing.', 'selection_start' => mb_strlen($prefix, 'UTF-8')]);
        $this->assertSame($prefix.'New phrasing. End.  ', $result['humanized_text']);
    }

    public function test_unicode_selection_offsets_preserve_emoji_bengali_and_outside_bytes(): void
    {
        Http::fake(function (Request $request) {
            $input = json_decode($request['contents'][0]['parts'][0]['text'], true);
            $this->assertSame('বাংলা বাক্য।', $input['source_text']);
            return Http::response($this->writingResponse('সহজ বাক্য।'));
        });
        $prefix = "  🙂 শুরু। বাংলা বাক্য।\n";
        $result = $this->service()->humanize($prefix.'বাংলা বাক্য। End.  ', 'professional', false,
            ['selection' => 'বাংলা বাক্য।', 'selection_start' => mb_strlen($prefix, 'UTF-8')]);
        $this->assertSame($prefix.'সহজ বাক্য। End.  ', $result['humanized_text']);
    }

    public function test_ambiguous_selection_without_position_is_rejected_before_provider_calls(): void
    {
        Http::fake();
        try {
            $this->service()->humanize('Old phrasing. Old phrasing.', 'professional', false, ['selection' => 'Old phrasing.']);
            $this->fail('Ambiguous selection must not rewrite a different occurrence.');
        } catch (HumanizationException $exception) {
            $this->assertSame('validation', $exception->stage);
            Http::assertNothingSent();
        }
    }

    public function test_stale_unicode_selection_position_is_rejected_before_provider_calls(): void
    {
        Http::fake();
        try {
            $this->service()->humanize('🙂 বাংলা বাক্য। End.', 'professional', false,
                ['selection' => 'বাংলা বাক্য।', 'selection_start' => 1]);
            $this->fail('A stale position must not rewrite a different span.');
        } catch (HumanizationException $exception) {
            $this->assertSame('validation', $exception->stage);
            Http::assertNothingSent();
        }
    }

    public function test_generation_errors_and_truncated_output_fail_without_fallback(): void
    {
        Http::fake(['*' => Http::response($this->writingResponse('Truncated draft', 'MAX_TOKENS'))]);
        $this->expectException(HumanizationException::class);
        $this->service()->humanize('Original passage.', 'professional', false);
    }

    public function test_lost_protected_content_fails_validation(): void
    {
        Http::fake(['*' => Http::response($this->writingResponse('The result is different.'))]);
        try {
            $this->service()->humanize('The result was 42% (Smith, 2022).', 'professional', false);
            $this->fail('Expected protected-content rejection.');
        } catch (HumanizationException $exception) {
            $this->assertSame('preservation', $exception->stage);
            $this->assertNotEmpty($exception->violations);
        }
    }

    public function test_introduced_numbers_are_rejected_even_when_there_were_no_locks(): void
    {
        Http::fake(['*' => Http::response($this->writingResponse('The result improved by 50%.'))]);
        $this->expectException(HumanizationException::class);
        $this->service()->humanize('The result improved.', 'professional', false);
    }

    public function test_unconfigured_generation_has_a_safe_actionable_error(): void
    {
        config(['services.gemini.api_key' => null]);
        Http::fake();
        try {
            $this->service()->humanize('Original passage.', 'professional', false);
            $this->fail('Expected unavailable writing provider.');
        } catch (HumanizationException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
            $this->assertStringNotContainsString('Original passage', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_marketing_mode_locks_negation_and_named_spans(): void
    {
        Http::fake(function (Request $request) {
            $data = json_decode($request['contents'][0]['parts'][0]['text'], true);
            $this->assertDoesNotMatchRegularExpression('/\bnot\b/', $data['source_text']);
            $this->assertStringNotContainsString('HumixAi', $data['source_text']);
            $this->assertStringContainsString('Avoid hype', $request['systemInstruction']['parts'][0]['text']);
            return Http::response($this->writingResponse(str_replace('notable', 'important', $data['source_text'])));
        });
        $result = $this->service()->humanize('HumixAi does not make notable promises.', 'marketing', false);
        $this->assertSame('HumixAi does not make important promises.', $result['humanized_text']);
    }

    public function test_failed_generation_retains_successful_original_scan_for_credit_settlement(): void
    {
        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'gptzero')
                ? Http::response($this->detectorResponse(.7))
                : Http::response(['error' => 'secret provider data'], 500);
        });
        try {
            $this->service()->humanize('The original passage.');
            $this->fail('Expected generation failure.');
        } catch (HumanizationException $exception) {
            $this->assertSame(['original_scan'], $exception->completedOperations);
            $this->assertSame('completed', $exception->originalDetection['status']);
            $this->assertSame(70.0, $exception->originalDetection['overall_score']);
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function test_output_expansion_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->writingResponse(str_repeat('Unrequested output. ', 100)))]);
        $this->expectException(HumanizationException::class);
        $this->service()->humanize('A short passage.', 'professional', false);
    }
}
