<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentReviewController;
use App\Models\User;
use App\Services\CitationReviewService;
use App\Services\ContentReviewService;
use App\Services\GeminiApiService;
use App\Services\UsageService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContentReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'services.gemini.api_key' => 'mock-gemini-key', 'services.gemini.model' => 'gemini-3.8-flash',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gptzero.api_key' => 'mock-gptzero-key', 'services.gptzero.base_url' => 'https://api.gptzero.me',
            'studio.daily_word_limit' => 1000]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Http::preventStrayRequests();
        // Isolated routes exercise the new controller before the root adds production routes.
        Route::post('/_test-review', [ContentReviewController::class, 'review']);
        Route::post('/_test-citations', [ContentReviewController::class, 'citations']);
    }

    private function writer(): User
    {
        return User::create(['name' => 'Review Writer', 'email' => 'review@example.test', 'password' => Hash::make('password-12345')]);
    }

    private function feedback(array $overrides = []): array
    {
        return array_replace(['summary' => 'Consider a subject-verb agreement edit.', 'issues' => [[
            'excerpt' => 'The findings demonstrates', 'category' => 'grammar',
            'message' => 'The plural subject needs a plural verb.', 'suggestion' => 'The findings demonstrate',
        ]]], $overrides);
    }

    private function response(array $feedback): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($feedback)]]]]]];
    }

    public function test_grammar_feedback_is_labeled_bounded_and_anchored_to_the_source(): void
    {
        Http::fake(['*' => Http::response($this->response($this->feedback()))]);
        $source = 'The findings demonstrates a useful pattern.';
        $result = (new ContentReviewService(new GeminiApiService()))->review($source);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('gemini', $result['provider']);
        $this->assertStringStartsWith('AI feedback:', $result['summary']);
        $this->assertSame(hash('sha256', $source), $result['source_hash']);
        $this->assertCount(1, $result['issues']);
        $this->assertNotEmpty($result['limitations']);
        Http::assertSent(fn (Request $request) => $request['generationConfig']['responseMimeType'] === 'application/json');
        Http::assertSentCount(1);
    }

    public function test_guidelines_and_meaning_reviews_use_only_the_provided_texts(): void
    {
        $sequence = Http::sequence()
            ->push($this->response($this->feedback(['issues' => [['excerpt' => 'The findings demonstrates', 'category' => 'guideline', 'message' => 'Use shorter wording.', 'suggestion' => 'The findings show']]])))
            ->push($this->response($this->feedback(['summary' => 'A claim changed.', 'issues' => [['excerpt' => 'The effect is certain.', 'category' => 'claim_change', 'message' => 'The revised version removes uncertainty.', 'suggestion' => 'Retain the original uncertainty.']]])));
        Http::fake(['*' => $sequence]);
        $service = new ContentReviewService(new GeminiApiService());
        $this->assertSame('completed', $service->review('The findings demonstrates a pattern.', 'guidelines', ['guidelines' => 'Use short sentences.'])['status']);
        $result = $service->review('The effect may exist.', 'meaning', ['revised_text' => 'The effect is certain.']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(hash('sha256', 'The effect is certain.'), $result['revised_hash']);
        $this->assertSame('claim_change', $result['issues'][0]['category']);
        Http::assertSentCount(2);
    }

    public function test_unmatched_excerpt_is_rejected_instead_of_inventing_inline_feedback(): void
    {
        Http::fake(['*' => Http::response($this->response($this->feedback()))]);
        $result = (new ContentReviewService(new GeminiApiService()))->review('A completely different document.');
        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['issues']);
    }

    public function test_unsupported_fields_sources_categories_and_too_many_issues_are_rejected(): void
    {
        $bad = [
            $this->feedback(['sources' => [['url' => 'https://invented.example']]]),
            $this->feedback(['summary' => 'Verified by https://invented.example/source']),
            $this->feedback(['issues' => [['excerpt' => 'The findings', 'category' => 'fact_verified', 'message' => 'Verified.', 'suggestion' => '']]]),
            $this->feedback(['issues' => array_fill(0, 31, $this->feedback()['issues'][0])]),
        ];
        $sequence = Http::sequence();
        foreach ($bad as $feedback) { $sequence->push($this->response($feedback)); }
        Http::fake(['*' => $sequence]);
        $service = new ContentReviewService(new GeminiApiService());
        foreach ($bad as $unused) {
            $result = $service->review('The findings demonstrates a pattern.');
            $this->assertSame('error', $result['status']);
            $this->assertSame([], $result['issues']);
        }
    }

    public function test_invalid_request_and_unconfigured_provider_make_no_calls(): void
    {
        Http::fake();
        $service = new ContentReviewService(new GeminiApiService());
        $this->assertSame('error', $service->review('A source document.', 'meaning')['status']);
        $this->assertSame('error', $service->review('A source document.', 'guidelines')['status']);
        config(['services.gemini.api_key' => null]);
        $this->assertSame('unavailable', (new ContentReviewService(new GeminiApiService()))->review('A source document.')['status']);
        Http::assertNothingSent();
    }

    public function test_citation_review_calls_real_documented_endpoint_and_returns_unmapped_evidence(): void
    {
        $upstream = ['provider_specific_result' => ['references' => [['title' => 'Provider source', 'url' => 'https://example.test/source']]]];
        Http::fake(['api.gptzero.me/*' => Http::response($upstream)]);
        $result = (new CitationReviewService())->review('The claim cites a reference [1].');
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['format' => 'provider-json', 'data' => $upstream], $result['evidence']);
        $this->assertSame([], $result['issues']);
        $this->assertNull($result['model']);
        $this->assertArrayNotHasKey('verified_sources', $result);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.gptzero.me/v2/bibliography-scan/text'
            && $request->hasHeader('x-api-key', 'mock-gptzero-key') && $request['document'] === 'The claim cites a reference [1].');
    }

    public function test_citation_error_responses_do_not_claim_verification_or_leak_errors(): void
    {
        Http::fake(['*' => Http::sequence()->push(['error' => 'secret-provider-data'], 500)->push(['error' => 'secret-provider-data'])]);
        foreach (['unavailable', 'error'] as $status) {
            $result = (new CitationReviewService())->review('The claim cites a reference [1].');
            $this->assertSame($status, $result['status']);
            $this->assertNull($result['evidence']);
            $this->assertSame([], $result['issues']);
            $this->assertStringNotContainsString('secret-provider-data', json_encode($result));
        }
    }

    public function test_controller_requires_auth_and_consent_and_settles_completed_review_only(): void
    {
        Http::fake(['*' => Http::response($this->response($this->feedback()))]);
        $payload = ['text' => 'The findings demonstrates a useful pattern.', 'type' => 'grammar', 'external_processing_consent' => true];
        $this->postJson('/_test-review', $payload)->assertUnauthorized();
        $user = $this->writer();
        $this->actingAs($user);
        $this->postJson('/_test-review', array_replace($payload, ['external_processing_consent' => false]))->assertUnprocessable()->assertJsonValidationErrors('external_processing_consent');
        Http::assertNothingSent();
        $this->postJson('/_test-review', $payload)->assertOk()->assertJsonPath('data.status', 'completed');
        $summary = app(UsageService::class)->summary($user);
        $this->assertSame(6, $summary['used_words']);
        $this->assertSame(0, $summary['reserved_words']);
        $this->assertDatabaseCount('document_revisions', 0);
    }

    public function test_provider_failure_releases_reserved_units_and_daily_limit_prevents_a_call(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $user = $this->writer();
        $this->actingAs($user);
        $payload = ['text' => 'The findings demonstrates a useful pattern.', 'type' => 'grammar', 'external_processing_consent' => true];
        $this->postJson('/_test-review', $payload)->assertOk()->assertJsonPath('data.status', 'unavailable');
        $summary = app(UsageService::class)->summary($user);
        $this->assertSame(0, $summary['used_words']);
        $this->assertSame(0, $summary['reserved_words']);
        $user->forceFill(['daily_word_limit' => 1])->save();
        $this->postJson('/_test-review', $payload)->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_controller_applies_existing_token_scopes_to_each_review_kind(): void
    {
        Http::fake();
        $this->actingAs($this->writer());
        Route::post('/_test-review-scoped', function (\Illuminate\Http\Request $request) {
            $request->attributes->set('api_token', (object) ['scopes' => ['detect']]);
            return app()->call([app(ContentReviewController::class), 'review'], ['request' => $request]);
        });
        $this->postJson('/_test-review-scoped', ['text' => 'The findings demonstrates a useful pattern.', 'type' => 'grammar', 'external_processing_consent' => true])->assertForbidden();
        Http::assertNothingSent();
    }
}
