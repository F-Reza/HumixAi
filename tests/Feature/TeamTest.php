<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true, 'services.gemini.api_key' => null]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Http::preventStrayRequests();
    }

    private function user(string $name): User
    {
        return User::create(['name' => ucfirst($name), 'email' => $name.'@example.test', 'password' => Hash::make('test-password-123')]);
    }

    private function team(User $owner, string $name = 'Writing desk'): string
    {
        return $this->actingAs($owner)->postJson('/teams', ['name' => $name])->assertCreated()->json('data.team.id');
    }

    private function join(User $owner, User $member, string $team, string $role): string
    {
        $token = $this->actingAs($owner)->postJson('/teams/'.$team.'/invitations', ['role' => $role])->assertCreated()->json('data.token');
        $this->actingAs($member)->postJson('/teams/join', ['token' => $token, 'role' => 'owner'])->assertCreated()->assertJsonPath('data.role', $role);
        return $token;
    }

    public function test_invitations_are_hash_only_single_use_expiring_and_owner_controlled(): void
    {
        $owner = $this->user('owner');
        $reviewer = $this->user('reviewer');
        $outsider = $this->user('outsider');
        $team = $this->team($owner);
        $token = $this->join($owner, $reviewer, $team, 'reviewer');
        $this->assertSame(hash('sha256', $token), TeamInvitation::firstOrFail()->token_hash);
        $this->getJson('/teams/'.$team)->assertOk()->assertDontSee($token, false);
        $this->postJson('/teams/'.$team.'/invitations', ['role' => 'editor'])->assertForbidden();
        $this->actingAs($outsider)->postJson('/teams/join', ['token' => $token])->assertNotFound();
        $this->getJson('/teams/'.$team)->assertNotFound();
        $this->actingAs($owner)->postJson('/teams/'.$team.'/invitations', ['role' => 'owner'])->assertStatus(422);
        $expired = $this->postJson('/teams/'.$team.'/invitations', ['role' => 'editor'])->assertCreated()->json('data.token');
        TeamInvitation::where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($outsider)->postJson('/teams/join', ['token' => $expired])->assertNotFound();
        $this->actingAs($owner)->deleteJson('/teams/'.$team.'/members/'.$owner->id)->assertStatus(422);
        $this->assertSame('owner', TeamMembership::where('team_id', $team)->where('user_id', $owner->id)->value('role'));
    }

    public function test_reviewers_comment_editors_revise_and_private_document_access_stays_owner_only(): void
    {
        $owner = $this->user('owner');
        $editor = $this->user('editor');
        $reviewer = $this->user('reviewer');
        $team = $this->team($owner);
        $this->join($owner, $editor, $team, 'editor');
        $this->join($owner, $reviewer, $team, 'reviewer');
        $document = Document::create(['user_id' => $owner->id, 'title' => 'Shared draft', 'source_text' => 'Original private draft.', 'current_text' => 'Original private draft.']);
        $this->actingAs($editor)->postJson('/teams/'.$team.'/documents', ['document_id' => $document->id])->assertNotFound();
        $this->actingAs($owner)->postJson('/teams/'.$team.'/documents', ['document_id' => $document->id])->assertCreated();
        $this->actingAs($reviewer)->getJson('/teams/'.$team.'/documents/'.$document->id)->assertOk()->assertJsonPath('data.role', 'reviewer');
        $this->patchJson('/teams/'.$team.'/documents/'.$document->id, ['current_text' => 'Unauthorized edit.'])->assertForbidden();
        $this->postJson('/teams/'.$team.'/documents/'.$document->id.'/comments', ['body' => '<script>literal review</script> Check this citation.'])
            ->assertCreated()->assertJsonPath('data.comment.body', '<script>literal review</script> Check this citation.');
        $this->actingAs($editor)->patchJson('/teams/'.$team.'/documents/'.$document->id, ['current_text' => 'Edited draft with (Smith, 2024).'])->assertOk();
        $this->assertDatabaseHas('document_revisions', ['document_id' => $document->id, 'action' => 'team_edit', 'output_text' => 'Edited draft with (Smith, 2024).']);
        $this->getJson('/documents/'.$document->id)->assertNotFound();
        $this->getJson('/documents/'.$document->id.'/export?format=json')->assertNotFound();
        $this->getJson('/teams/'.$team.'/documents/'.$document->id.'/comments')->assertOk()->assertJsonPath('data.total', 1);
        Http::assertNothingSent();
    }

    public function test_cross_team_links_and_member_removal_do_not_expose_or_delete_private_drafts(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $team = $this->team($owner);
        $second = $this->team($owner, 'Other team');
        $this->join($owner, $member, $team, 'editor');
        $document = Document::create(['user_id' => $member->id, 'title' => 'Member draft', 'source_text' => 'Member original.', 'current_text' => 'Member original.']);
        $this->actingAs($member)->postJson('/teams/'.$team.'/documents', ['document_id' => $document->id])->assertCreated();
        $this->actingAs($owner)->getJson('/teams/'.$second.'/documents/'.$document->id)->assertNotFound();
        $this->deleteJson('/teams/'.$team.'/members/'.$member->id)->assertOk();
        $this->getJson('/teams/'.$team.'/documents/'.$document->id)->assertNotFound();
        $this->actingAs($member)->getJson('/teams/'.$team)->assertNotFound();
        $this->getJson('/documents/'.$document->id)->assertOk();
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'user_id' => $member->id]);
        $this->actingAs($owner)->deleteJson('/account', ['password' => 'test-password-123'])->assertOk();
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('team_memberships', 0);
        $this->assertDatabaseCount('team_invitations', 0);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }
}
