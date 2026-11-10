<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\DocumentShare;
use App\Models\StudioJob;
use App\Models\UsageReservation;
use App\Models\User;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'services.gemini.api_key' => null, 'studio.daily_word_limit' => 100, 'studio.allow_registration' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Http::preventStrayRequests();
    }

    private function user(string $email = 'writer@example.test'): User
    {
        return User::create(['name' => 'Writer', 'email' => $email, 'password' => Hash::make('test-password-123')]);
    }

    public function test_registration_hashes_password_and_login_uses_the_session(): void
    {
        $this->postJson('/register', ['name' => 'Writer', 'email' => 'WRITER@example.test',
            'password' => 'test-password-123', 'password_confirmation' => 'test-password-123'])->assertCreated()
            ->assertJsonPath('data.user.email', 'writer@example.test')->assertJsonMissingPath('data.user.password');
        $this->assertTrue(Hash::check('test-password-123', User::firstOrFail()->password));
        $this->getJson('/auth/me')->assertOk();
        $this->postJson('/logout')->assertOk();
        $this->postJson('/login', ['email' => 'writer@example.test', 'password' => 'wrong-password'])->assertStatus(422);
        $this->postJson('/login', ['email' => 'writer@example.test', 'password' => 'test-password-123'])->assertOk();
        $this->getJson('/account')->assertOk()->assertJsonPath('data.user.history_enabled', true);
    }

    public function test_word_reservations_enforce_the_cap_release_failures_and_settle_once(): void
    {
        $user = $this->user();
        $usage = app(UsageService::class);
        $first = $usage->reserve($user, 70, 'detect');
        $this->assertTrue($first['allowed']);
        $this->assertFalse($usage->reserve($user, 40, 'humanize')['allowed']);
        $usage->settle($first['reservation_id'], 65);
        $usage->settle($first['reservation_id'], 65);
        $this->assertSame(65, $usage->summary($user)['used_words']);
        $next = $usage->reserve($user, 35, 'humanize');
        $usage->release($next['reservation_id']);
        $this->assertSame(35, $usage->summary($user)['remaining']);
        $stale = $usage->reserve($user, 30);
        UsageReservation::whereKey($stale['reservation_id'])->update(['expires_at' => now()->subSecond()]);
        $this->assertSame(0, $usage->summary($user)['reserved_words']);
    }

    public function test_tokens_are_hash_only_scoped_expiring_revocable_and_tenant_isolated(): void
    {
        $owner = $this->user();
        $other = $this->user('other@example.test');
        $this->actingAs($owner);
        $created = $this->postJson('/account/tokens', ['name' => 'Detector', 'scopes' => ['detect'], 'expires_in_days' => 1])->assertCreated();
        $plain = $created->json('data.plain_text_token');
        $id = $created->json('data.token.id');
        $this->assertSame(hash('sha256', $plain), ApiToken::findOrFail($id)->token_hash);
        $this->getJson('/account/tokens')->assertOk()->assertDontSee($plain, false)->assertJsonMissingPath('data.tokens.0.token_hash');
        Route::get('/_test-token-detect', fn (Request $request) => response()->json(['user_id' => $request->user()->id]))
            ->middleware(AuthenticateApiToken::class.':detect');
        Route::get('/_test-token-write', fn () => response()->json(['ok' => true]))->middleware(AuthenticateApiToken::class.':humanize');
        $this->withToken($plain)->getJson('/_test-token-detect')->assertOk()->assertJsonPath('user_id', $owner->id);
        $this->withToken($plain)->getJson('/_test-token-write')->assertForbidden();
        $this->actingAs($other)->deleteJson('/account/tokens/'.$id)->assertNotFound();
        $this->actingAs($owner)->deleteJson('/account/tokens/'.$id)->assertOk();
        $this->withToken($plain)->getJson('/_test-token-detect')->assertUnauthorized();
        $fresh = $this->postJson('/account/tokens', ['name' => 'Expired token', 'scopes' => ['detect']])->assertCreated();
        ApiToken::whereKey($fresh->json('data.token.id'))->update(['expires_at' => now()->subMinute()]);
        $this->withToken($fresh->json('data.plain_text_token'))->getJson('/_test-token-detect')->assertUnauthorized();
    }

    public function test_history_consent_and_account_deletion_are_enforced(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->patchJson('/account', ['history_enabled' => false])->assertOk()->assertJsonPath('data.user.history_enabled', false);
        $this->postJson('/documents', ['source_text' => 'A private draft must not be saved.'])->assertStatus(422);
        $this->patchJson('/account', ['history_enabled' => true])->assertOk();
        $document = Document::create(['user_id' => $user->id, 'title' => 'Draft', 'source_text' => 'Private.', 'current_text' => 'Private.']);
        DocumentRevision::create(['document_id' => $document->id, 'action' => 'created', 'input_text' => 'Private.', 'text_hash' => hash('sha256', 'Private.')]);
        DocumentShare::create(['document_id' => $document->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', 'share'), 'snapshot' => [], 'expires_at' => now()->addDay()]);
        $this->postJson('/account/tokens', ['name' => 'Token'])->assertCreated();
        app(UsageService::class)->reserve($user, 5);
        StudioJob::create(['user_id' => $user->id, 'document_id' => $document->id, 'action' => 'detect', 'payload' => ['text' => 'Private.'], 'status' => 'queued']);
        $this->deleteJson('/account', ['password' => 'wrong-password'])->assertStatus(422);
        $this->deleteJson('/account', ['password' => 'test-password-123'])->assertOk();
        foreach (['users', 'documents', 'document_revisions', 'document_shares', 'api_tokens', 'usage_days', 'usage_reservations', 'studio_jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
