<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioJob;
use App\Models\StudioJob;
use App\Models\Document;
use App\Models\User;
use App\Services\StudioRunner;
use App\Services\UsageService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Integration regressions found by the independent audit. No live provider calls. */
class IntegrationAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true, 'studio.daily_word_limit' => 1000,
            'services.gemini.api_key' => 'mock-writing-key', 'services.gemini.model' => 'gemini-3.8-flash',
            'services.gptzero.api_key' => 'mock-scan-key', 'services.gptzero.min_words' => 1]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Http::preventStrayRequests();
    }

    private function writer(): User
    {
        return User::create(['name' => 'Audit Writer', 'email' => 'audit@example.test', 'password' => Hash::make('password-12345')]);
    }

    public function test_idempotency_key_reuse_with_different_input_returns_a_conflict(): void
    {
        Queue::fake();
        $this->actingAs($this->writer());
        $payload = ['text' => 'The first submitted draft.', 'action' => 'detect', 'external_processing_consent' => true, 'idempotency_key' => 'one-immutable-operation'];
        $first = $this->postJson('/ai-tools/jobs', $payload)->assertAccepted();
        $this->postJson('/ai-tools/jobs', $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/ai-tools/jobs', array_replace($payload, ['text' => 'A completely different submitted draft.']))->assertStatus(409);
        $this->assertDatabaseCount('studio_jobs', 1);
        Queue::assertPushed(ProcessStudioJob::class, 1);
    }

    public function test_queue_dispatch_failure_clears_sensitive_single_job_payload(): void
    {
        $this->actingAs($user = $this->writer());
        config(['studio.queue_connection' => 'missing-audit-queue']);
        $this->postJson('/ai-tools/jobs', ['text' => 'A private draft for the queue.', 'voice_sample' => 'Private voice reference.',
            'action' => 'humanize', 'external_processing_consent' => true])->assertStatus(500);
        $job = StudioJob::firstOrFail();
        $this->assertSame('failed', $job->status);
        $this->assertSame([], $job->payload);
        $this->assertSame(0, app(UsageService::class)->summary($user)['reserved_words']);
    }

    public function test_batch_dispatch_failure_releases_all_unqueued_reservations(): void
    {
        $this->actingAs($user = $this->writer());
        config(['studio.queue_connection' => 'missing-audit-queue']);
        $this->postJson('/ai-tools/batch', ['items' => [['text' => 'The first private draft.'], ['text' => 'The second private draft.']],
            'action' => 'humanize', 'external_processing_consent' => true])->assertStatus(500);
        $this->assertSame(0, app(UsageService::class)->summary($user)['reserved_words']);
        foreach (StudioJob::all() as $job) {
            $this->assertSame('failed', $job->status);
            $this->assertSame([], $job->payload);
        }
    }

    public function test_failed_rewrite_job_retains_the_successful_scan_that_was_charged(): void
    {
        $user = $this->writer();
        $payload = ['text' => 'The original submitted draft.', 'external_processing_consent' => true];
        $runner = app(StudioRunner::class);
        $reservation = $runner->reserve($user, 'combo', $payload);
        $job = StudioJob::create(['user_id' => $user->id, 'action' => 'combo', 'payload' => $payload,
            'reservation_id' => $reservation['reservation_id'], 'status' => 'queued']);
        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'gptzero')
                ? Http::response(['documents' => [['predicted_class' => 'ai', 'class_probabilities' => ['human' => .2, 'mixed' => .1, 'ai' => .7]]], 'version' => 'audit-version'])
                : Http::response([], 500);
        });
        (new ProcessStudioJob($job->id))->handle($runner);
        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame(4, app(UsageService::class)->summary($user)['used_words']);
        $this->assertSame('completed', $job->result['original_detection']['status'] ?? null);
        $this->assertSame([], $job->payload);
    }

    public function test_disabling_history_while_a_provider_is_running_prevents_later_revision_persistence(): void
    {
        $user = $this->writer();
        $document = Document::create(['user_id' => $user->id, 'title' => 'Private document',
            'source_text' => 'The submitted draft.', 'current_text' => 'The submitted draft.']);
        Http::fake(function () use ($user) {
            User::whereKey($user->id)->update(['history_enabled' => false]);
            return Http::response(['documents' => [['predicted_class' => 'human',
                'class_probabilities' => ['human' => .8, 'mixed' => .1, 'ai' => .1]]], 'version' => 'audit-version']);
        });
        $result = app(StudioRunner::class)->execute($user, 'detect', ['text' => 'The submitted draft.', 'document_id' => $document->id]);
        $this->assertSame('completed', $result['status']);
        $this->assertFalse($user->fresh()->history_enabled);
        $this->assertDatabaseCount('document_revisions', 0);
    }
}
