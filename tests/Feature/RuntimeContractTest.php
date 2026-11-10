<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioJob;
use App\Models\{StudioJob, UsageReservation, User};
use App\Services\{StudioRunner, UsageService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RuntimeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_input_whitespace_survives_request_and_hash_binding(): void
    {
        $text = "  A source with intentional leading spacing.\n\n";
        $this->postJson('/ai-tools/style', ['text' => $text])->assertOk()->assertJsonPath('data.text_hash', hash('sha256', $text));
    }

    public function test_expired_jobs_do_not_call_a_provider_without_a_reservation(): void
    {
        $user = User::create(['name' => 'Late Writer', 'email' => 'late@example.test', 'password' => 'long-test-password']);
        $payload = ['text' => 'A queued document with an allowance that has expired.'];
        $reservation = app(StudioRunner::class)->reserve($user, 'detect', $payload);
        UsageReservation::whereKey($reservation['reservation_id'])->update(['expires_at' => now()->subMinute()]);
        $job = StudioJob::create(['user_id' => $user->id, 'action' => 'detect', 'payload' => $payload, 'status' => 'queued', 'reservation_id' => $reservation['reservation_id']]);
        (new ProcessStudioJob($job->id))->handle(app(StudioRunner::class));
        $this->assertSame('failed', $job->fresh()->status); $this->assertSame([], $job->fresh()->payload);
        $this->assertSame(0, app(UsageService::class)->summary($user)['reserved_words']);
        Http::assertNothingSent();
    }

    public function test_studio_and_auth_templates_render_with_local_assets(): void
    {
        $this->get('/')->assertOk()->assertSee('HumixAi')->assertSee('assets/humixai.js')->assertDontSee('cdn.tailwindcss.com');
        $this->get('/login')->assertOk()->assertSee('id="authForm"', false);
        $this->get('/register')->assertOk()->assertSee('id="authForm"', false);
    }

    public function test_clearing_a_saved_draft_can_be_saved_and_restored_from_history(): void
    {
        $user = User::create(['name' => 'Editing Writer', 'email' => 'editing@example.test', 'password' => 'long-test-password']);
        $id = $this->actingAs($user)->postJson('/documents', ['source_text' => 'A draft before its text was cleared.'])->assertCreated()->json('data.id');
        $this->patchJson('/documents/'.$id, ['source_text' => '', 'current_text' => ''])->assertOk()->assertJsonPath('data.source_text', '')->assertJsonPath('data.current_text', '');
        $this->getJson('/documents/'.$id.'/revisions')->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_document_preferences_cannot_inject_fake_reports_or_invalid_control_types(): void
    {
        $user = User::create(['name' => 'Careful Writer', 'email' => 'careful@example.test', 'password' => 'long-test-password']);
        $this->actingAs($user)->postJson('/documents', ['source_text' => 'An actual draft.', 'settings' => ['protected_terms' => 'invalid type']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.protected_terms');
        $response = $this->postJson('/documents', ['source_text' => 'An actual draft.', 'settings' => [
            'mode' => 'professional', 'last_detection' => ['overall_score' => 0, 'status' => 'completed', 'provider' => 'gptzero'],
            'voice_sample' => 'A private reference that must not become saved preferences.',
        ]])->assertCreated();
        $this->assertSame(['mode' => 'professional'], $response->json('data.settings'));
        $share = $this->postJson('/documents/'.$response->json('data.id').'/share')->assertCreated()->json('data.url');
        $this->get($share)->assertOk()->assertDontSee('0%')->assertDontSee('A private reference');
    }
}
