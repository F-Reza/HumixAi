<?php

namespace Tests\Feature;

use App\Models\{ApiToken, Document, StudioJob, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiToolTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Studio Writer', 'email' => uniqid().'@example.test', 'password' => 'a-long-local-test-password']);
    }

    public function test_local_style_review_does_not_invent_authorship_scores_or_call_providers(): void
    {
        $this->postJson('/ai-tools/style', ['text' => 'আমি বাংলায় লিখছি। আমার নিজের একটি অভিজ্ঞতা নিয়ে এই লেখাটি তৈরি করেছি।', 'language' => 'bn'])
            ->assertOk()->assertJsonPath('data.overall_score', null)->assertJsonPath('data.engine', 'local-writing-analysis');
        Http::assertNothingSent();
    }

    public function test_external_processing_requires_authentication_and_explicit_consent(): void
    {
        $this->postJson('/ai-tools/detect', ['text' => 'This is a draft that should remain private.'])->assertUnauthorized();
        $this->actingAs($this->user())->postJson('/ai-tools/detect', ['text' => 'This is a draft that should remain private.'])
            ->assertUnprocessable()->assertJsonValidationErrors('external_processing_consent');
        Http::assertNothingSent();
    }

    public function test_removed_environment_editor_and_cache_routes_are_inaccessible(): void
    {
        $this->postJson('/api/v1/update-key', ['api_key' => 'must-not-be-written'])->assertNotFound();
        $this->postJson('/ai-tools/update-key', ['api_key' => 'must-not-be-written'])->assertNotFound();
        $this->get('/cc')->assertNotFound();
    }

    public function test_unconfigured_detection_job_returns_honest_partial_result_and_releases_quota(): void
    {
        $user = $this->user();
        $response = $this->actingAs($user)->postJson('/ai-tools/jobs', ['action' => 'detect',
            'text' => str_repeat('A writer described the observations carefully and recorded the weather that morning. ', 10),
            'external_processing_consent' => true]);
        $response->assertStatus(202)->assertJsonPath('data.status', 'partial')->assertJsonPath('data.result.overall_score', null);
        $this->assertDatabaseHas('usage_days', ['user_id' => $user->id, 'used_words' => 0, 'reserved_words' => 0]);
        $this->assertSame([], StudioJob::first()->payload);
        Http::assertNothingSent();
    }

    public function test_job_token_cannot_escalate_into_rewriting(): void
    {
        $user = $this->user();
        ApiToken::create(['user_id' => $user->id, 'name' => 'jobs only', 'token_hash' => hash('sha256', 'local-scope-test'),
            'prefix' => 'local', 'scopes' => ['jobs'], 'expires_at' => now()->addDay()]);
        $this->withToken('local-scope-test')->postJson('/api/v1/jobs', ['action' => 'humanize',
            'text' => 'This token should never be able to request a rewriting operation.', 'external_processing_consent' => true])->assertForbidden();
        $this->assertDatabaseCount('studio_jobs', 0);
    }

    public function test_job_ownership_and_idempotent_resubmission(): void
    {
        $owner = $this->user();
        $data = ['text' => 'This draft is intentionally short and receives no invented detection result.', 'action' => 'detect',
            'external_processing_consent' => true, 'idempotency_key' => 'one-scan-only'];
        $id = $this->actingAs($owner)->postJson('/ai-tools/jobs', $data)->assertStatus(202)->json('data.id');
        $this->postJson('/ai-tools/jobs', $data)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('studio_jobs', 1);
        $this->actingAs($this->user())->getJson('/ai-tools/jobs/'.$id)->assertNotFound();
    }

    public function test_finished_scan_records_its_input_without_overwriting_newer_draft(): void
    {
        $user = $this->user();
        $document = Document::create(['user_id' => $user->id, 'title' => 'Draft', 'source_text' => 'First draft.', 'current_text' => 'A newer version that should remain in the editor.']);
        $text = 'An earlier draft is submitted for a local unavailable detection result.';
        $this->actingAs($user)->postJson('/ai-tools/detect', ['text' => $text, 'document_id' => $document->id,
            'external_processing_consent' => true])->assertOk();
        $this->assertSame('A newer version that should remain in the editor.', $document->fresh()->current_text);
        $this->assertDatabaseHas('document_revisions', ['document_id' => $document->id, 'input_text' => $text, 'text_hash' => hash('sha256', $text)]);
    }

    public function test_batch_rejects_the_entire_request_if_a_document_is_not_owned(): void
    {
        $owner = $this->user(); $other = $this->user();
        $document = Document::create(['user_id' => $owner->id, 'title' => 'Private', 'source_text' => 'A private draft.', 'current_text' => 'A private draft.']);
        $this->actingAs($other)->postJson('/ai-tools/batch', ['action' => 'detect', 'external_processing_consent' => true,
            'items' => [['text' => 'This text belongs to a separate draft that is long enough.'],
                ['text' => 'This text is attached to a document that is not owned.', 'document_id' => $document->id]]])->assertNotFound();
        $this->assertDatabaseCount('studio_jobs', 0);
        $this->assertDatabaseCount('usage_reservations', 0);
    }

    public function test_status_is_secret_free_and_health_is_rebranded(): void
    {
        config(['services.gemini.api_key' => 'secret-must-not-appear']);
        $this->getJson('/ai-tools/status')->assertOk()->assertJsonPath('data.rewriter.configured', true)
            ->assertDontSee('secret-must-not-appear')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/health')->assertOk()->assertJsonPath('service', 'HumixAi Studio');
    }
}
