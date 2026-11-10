<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlagiarismReviewService;
use App\Services\UsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlagiarismTest extends TestCase
{
    private string $text = 'This is a complete submitted passage with a citation (Smith, 2024). It must be checked against actual web sources.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true, 'services.walter.api_key' => '',
            'services.walter.base_url' => 'https://developer-portal.walterwrites.ai/api', 'studio.daily_word_limit' => 10000]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    private function signIn(): User
    {
        $user = User::create(['name' => 'Writer', 'email' => 'writer@example.test', 'password' => Hash::make('test-password-123')]);
        $this->actingAs($user);
        return $user;
    }

    public function test_auth_consent_and_configuration_gate_all_outbound_requests(): void
    {
        Http::fake();
        $this->postJson('/ai-tools/plagiarism', ['text' => $this->text, 'external_processing_consent' => true])->assertUnauthorized();
        $user = $this->signIn();
        $this->postJson('/ai-tools/plagiarism', ['text' => $this->text])->assertStatus(422);
        $this->postJson('/ai-tools/plagiarism', ['text' => $this->text, 'external_processing_consent' => true])->assertOk()
            ->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.similarity_score', null)->assertJsonPath('data.matches', []);
        Http::assertNothingSent();
        $this->assertSame(0, app(UsageService::class)->summary($user)['used_words']);
        $this->assertFalse(app(PlagiarismReviewService::class)->capabilities()['configured']);
    }

    public function test_real_schema_is_normalized_to_similarity_and_exact_input_excerpts(): void
    {
        $user = $this->signIn();
        config(['services.walter.api_key' => 'test-walter-key']);
        Http::fake(['https://developer-portal.walterwrites.ai/api/plagiarism/scan/' => Http::response([
            'status' => 'success', 'scan_id' => 'scan_test', 'plagiarism_score' => 0.35,
            'sources' => [['url' => 'https://example.org/source', 'title' => 'Source document', 'similarity_score' => 0.92,
                'matches' => [['submitted_range' => ['start' => 0, 'end' => 19], 'similarity' => 0.92]]]],
        ])]);
        $this->postJson('/ai-tools/plagiarism', ['text' => $this->text, 'external_processing_consent' => true])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.score_type', 'similarity')
            ->assertJsonPath('data.similarity_score', 35)->assertJsonPath('data.source_hash', hash('sha256', $this->text))
            ->assertJsonPath('data.matches.0.source_url', 'https://example.org/source')
            ->assertJsonPath('data.matches.0.similarity', 92)->assertJsonPath('data.matches.0.excerpts.0', substr($this->text, 0, 19));
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-walter-key')
            && $request->hasHeader('Idempotency-Key') && $request->data() === ['content' => $this->text]);
        $this->assertGreaterThan(0, app(UsageService::class)->summary($user)['used_words']);
    }

    public function test_unavailable_and_malformed_results_never_become_zero_similarity(): void
    {
        $user = $this->signIn();
        config(['services.walter.api_key' => 'test-walter-key']);
        Http::fakeSequence()->push(['code' => 'insufficient_credits'], 403)
            ->push(['status' => 'success', 'sources' => []])
            ->push(['status' => 'success', 'plagiarism_score' => 0.2, 'sources' => [['url' => 'javascript:alert(1)', 'title' => 'Unsafe', 'matches' => []]]]);
        foreach (['unavailable', 'error', 'error'] as $status) {
            $this->postJson('/ai-tools/plagiarism', ['text' => $this->text, 'external_processing_consent' => true])->assertOk()
                ->assertJsonPath('data.status', $status)->assertJsonPath('data.similarity_score', null)->assertJsonPath('data.matches', []);
        }
        $this->assertSame(0, app(UsageService::class)->summary($user)['used_words']);
        $this->assertSame(0, app(UsageService::class)->summary($user)['reserved_words']);
    }

    public function test_genuine_zero_is_only_shown_from_a_valid_completed_provider_result(): void
    {
        $this->signIn();
        config(['services.walter.api_key' => 'test-walter-key']);
        Http::fake(['*' => Http::response(['status' => 'success', 'plagiarism_score' => 0, 'sources' => []])]);
        $this->postJson('/ai-tools/plagiarism', ['text' => $this->text, 'external_processing_consent' => true])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.similarity_score', 0);
    }
}
