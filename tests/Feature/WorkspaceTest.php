<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\User;
use App\Models\StudioJob;
use App\Services\UsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class WorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'services.gemini.api_key' => null, 'studio.daily_word_limit' => 10000]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Http::preventStrayRequests();
    }

    private function user(string $email = 'owner@example.test'): User
    {
        return User::create(['name' => 'Writer', 'email' => $email, 'password' => Hash::make('test-password-123')]);
    }

    public function test_documents_revisions_exports_and_shares_are_tenant_isolated(): void
    {
        $owner = $this->user();
        $other = $this->user('other@example.test');
        $document = Document::create(['user_id' => $owner->id, 'title' => 'Private draft',
            'source_text' => 'Private text with a citation (Smith, 2024).', 'current_text' => 'Private text with a citation (Smith, 2024).']);
        $this->getJson('/documents/'.$document->id.'/export')->assertStatus(401);
        $this->actingAs($other);
        $this->getJson('/documents/'.$document->id)->assertNotFound();
        $this->patchJson('/documents/'.$document->id, ['current_text' => 'Stolen edit'])->assertNotFound();
        $this->getJson('/documents/'.$document->id.'/revisions')->assertNotFound();
        $this->getJson('/documents/'.$document->id.'/export?format=json')->assertNotFound();
        $this->postJson('/documents/'.$document->id.'/share')->assertNotFound();
        $this->deleteJson('/documents/'.$document->id)->assertNotFound();
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'current_text' => $document->current_text]);
    }

    public function test_save_edit_and_docx_export_preserve_text_and_record_revisions(): void
    {
        $this->actingAs($this->user());
        $original = "First paragraph & a citation (Smith, 2024).\n\nSecond paragraph <script>literal text</script>.";
        $id = $this->postJson('/documents', ['title' => 'My draft', 'source_text' => $original])
            ->assertCreated()->json('data.id');
        $updated = $original."\nA final paragraph.";
        $this->patchJson('/documents/'.$id, ['current_text' => $updated])->assertOk();
        $this->getJson('/documents/'.$id.'/revisions')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/documents/'.$id.'/export?format=txt')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertContent($updated);
        $this->getJson('/documents/'.$id.'/export?format=json')->assertOk()->assertJsonPath('current_text', $updated);
        $response = $this->get('/documents/'.$id.'/export?format=docx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'humix-docx-test-');
        file_put_contents($path, $response->getContent());
        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true);
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $this->assertStringContainsString('&lt;script&gt;literal text&lt;/script&gt;', $xml);
            $this->assertStringContainsString('(Smith, 2024)', $xml);
            $this->assertStringContainsString('A final paragraph.', $xml);
        } finally { @unlink($path); }
    }

    public function test_public_share_is_an_expiring_revocable_snapshot_with_escaped_html(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);
        $id = $this->postJson('/documents', ['source_text' => '<script>alert("draft")</script> Literal draft.'])
            ->assertCreated()->json('data.id');
        $url = $this->postJson('/documents/'.$id.'/share', ['expires_in_days' => 1])->assertCreated()->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);
        $plain = basename($path);
        $share = DocumentShare::firstOrFail();
        $this->assertSame(hash('sha256', $plain), $share->token_hash);
        $this->assertNotSame($plain, $share->token_hash);
        $this->patchJson('/documents/'.$id, ['current_text' => 'Changed private version'])->assertOk();
        $this->getJson($path)->assertOk()->assertJsonPath('data.document.current_text', '<script>alert("draft")</script> Literal draft.');
        if (view()->exists('documents.share')) {
            $this->get($path)->assertOk()->assertDontSee('<script>alert("draft")</script>', false);
        }
        $this->deleteJson('/documents/'.$id.'/share')->assertOk();
        $this->getJson($path)->assertNotFound();
        $expiredUrl = $this->postJson('/documents/'.$id.'/share')->assertCreated()->json('data.url');
        DocumentShare::whereNull('revoked_at')->update(['expires_at' => now()->subMinute()]);
        $this->getJson(parse_url($expiredUrl, PHP_URL_PATH))->assertNotFound();
    }

    public function test_document_deletion_erases_transient_job_content_and_releases_reserved_usage(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $id = $this->postJson('/documents', ['source_text' => 'Private source content.',
            'settings' => ['mode' => 'professional', 'voice_sample' => 'Never persist this private reference.']])->assertCreated()->json('data.id');
        $this->assertArrayNotHasKey('voice_sample', Document::findOrFail($id)->settings);
        $usage = app(UsageService::class);
        $reserved = $usage->reserve($user, 25);
        StudioJob::create(['user_id' => $user->id, 'document_id' => $id, 'reservation_id' => $reserved['reservation_id'],
            'action' => 'detect', 'payload' => ['text' => 'Private source content.'], 'result' => ['private' => 'result'], 'status' => 'running']);
        $this->deleteJson('/documents/'.$id)->assertOk();
        $this->assertDatabaseCount('studio_jobs', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame(0, $usage->summary($user)['reserved_words']);
        $this->assertSame(0, $usage->summary($user)['used_words']);
        $usage->release($reserved['reservation_id']);
        $this->assertSame(0, $usage->summary($user)['reserved_words']);
    }

    public function test_editing_original_source_persists_it_and_records_the_exact_input_hash(): void
    {
        $this->actingAs($this->user());
        $id = $this->postJson('/documents', ['source_text' => 'First working original.', 'current_text' => 'Accepted rewrite.'])
            ->assertCreated()->json('data.id');
        $edited = "Edited original with (Smith, 2024).\nA second paragraph.";
        $this->patchJson('/documents/'.$id, ['source_text' => $edited])->assertOk()
            ->assertJsonPath('data.source_text', $edited)->assertJsonPath('data.current_text', 'Accepted rewrite.');
        $this->getJson('/documents/'.$id)->assertOk()->assertJsonPath('data.source_text', $edited);
        $this->assertDatabaseHas('document_revisions', ['document_id' => $id, 'action' => 'edit',
            'input_text' => $edited, 'output_text' => 'Accepted rewrite.', 'text_hash' => hash('sha256', $edited)]);
        $this->patchJson('/account', ['history_enabled' => false])->assertOk();
        $this->patchJson('/documents/'.$id, ['source_text' => 'Must not persist.'])->assertStatus(422);
        $this->assertDatabaseHas('documents', ['id' => $id, 'source_text' => $edited]);
    }
}
