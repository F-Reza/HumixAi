<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Team;
use App\Models\TeamComment;
use App\Models\TeamDocument;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teams = Team::whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id))
            ->withCount('memberships')->latest()->get()->map(function ($team) use ($request) {
                $team->setAttribute('role', TeamMembership::where('team_id', $team->id)->where('user_id', $request->user()->id)->value('role'));
                return $team;
            });
        return response()->json(['success' => true, 'data' => ['teams' => $teams]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        if (Team::where('owner_user_id', $request->user()->id)->count() >= 10) {
            throw ValidationException::withMessages(['name' => ['You can own up to ten teams.']]);
        }
        $team = DB::transaction(function () use ($request, $data) {
            $team = Team::create(['owner_user_id' => $request->user()->id, 'name' => trim($data['name'])]);
            TeamMembership::create(['team_id' => $team->id, 'user_id' => $request->user()->id, 'role' => 'owner']);
            return $team;
        });
        return response()->json(['success' => true, 'data' => ['team' => $team, 'role' => 'owner']], 201);
    }

    public function show(Request $request, string $team): JsonResponse
    {
        [$team, $membership] = $this->member($request, $team);
        $members = $team->memberships()->with('user:id,name,email')->oldest()->get();
        $data = ['team' => $team, 'role' => $membership->role, 'members' => $members];
        if ($membership->role === 'owner') {
            $data['invitations'] = $team->invitations()->whereNull('revoked_at')->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get();
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function update(Request $request, string $team): JsonResponse
    {
        [$team] = $this->owner($request, $team);
        $data = $request->validate(['name' => 'required|string|max:100']);
        $team->update(['name' => trim($data['name'])]);
        return response()->json(['success' => true, 'data' => ['team' => $team]]);
    }

    public function destroy(Request $request, string $team): JsonResponse
    {
        [$team] = $this->owner($request, $team);
        $team->delete();
        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

    public function invite(Request $request, string $team): JsonResponse
    {
        [$team] = $this->owner($request, $team);
        if ($request->filled('email')) { $request->merge(['email' => mb_strtolower(trim($request->input('email')))]); }
        $data = $request->validate([
            'role' => ['required', Rule::in(['editor', 'reviewer'])], 'email' => 'nullable|email:rfc|max:254',
            'expires_in_days' => 'sometimes|integer|min:1|max:14',
        ]);
        if ($team->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 50) {
            throw ValidationException::withMessages(['role' => ['Revoke an unused invitation before creating another.']]);
        }
        $plain = 'inv_'.Str::random(64);
        $invitation = TeamInvitation::create([
            'team_id' => $team->id, 'invited_by_user_id' => $request->user()->id,
            'email' => $data['email'] ?? null, 'role' => $data['role'], 'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays($data['expires_in_days'] ?? 7),
        ]);
        return response()->json(['success' => true, 'data' => [
            'id' => $invitation->id, 'token' => $plain, 'url' => url('/?invitation='.$plain),
            'role' => $invitation->role, 'expires_at' => $invitation->expires_at->toIso8601String(),
        ]], 201)->header('Cache-Control', 'no-store');
    }

    public function revokeInvitation(Request $request, string $team, string $invitation): JsonResponse
    {
        [$team] = $this->owner($request, $team);
        $record = $team->invitations()->whereKey($invitation)->firstOrFail();
        $record->forceFill(['revoked_at' => now()])->save();
        return response()->json(['success' => true, 'data' => ['revoked' => true]]);
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:100']);
        $result = DB::transaction(function () use ($request, $data) {
            $invite = TeamInvitation::where('token_hash', hash('sha256', $data['token']))->whereNull('revoked_at')
                ->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            if ($invite->email && mb_strtolower($request->user()->email) !== $invite->email) {
                abort(403, 'This invitation is for a different email address.');
            }
            if (TeamMembership::where('team_id', $invite->team_id)->where('user_id', $request->user()->id)->exists()) {
                throw ValidationException::withMessages(['token' => ['You are already a member of this team.']]);
            }
            if (TeamMembership::where('team_id', $invite->team_id)->count() >= 100) {
                throw ValidationException::withMessages(['token' => ['This team has reached its member limit.']]);
            }
            $changed = TeamInvitation::whereKey($invite->id)->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['accepted_at' => now(), 'accepted_by_user_id' => $request->user()->id, 'updated_at' => now()]);
            abort_unless($changed, 404);
            $membership = TeamMembership::create(['team_id' => $invite->team_id, 'user_id' => $request->user()->id, 'role' => $invite->role]);
            return ['team' => $invite->team, 'membership' => $membership, 'role' => $membership->role];
        }, 3);
        return response()->json(['success' => true, 'data' => $result], 201);
    }

    public function removeMember(Request $request, string $team, string $user): JsonResponse
    {
        [$team, $membership] = $this->member($request, $team);
        abort_unless($membership->role === 'owner' || (string) $request->user()->id === $user, 403);
        $target = TeamMembership::where('team_id', $team->id)->where('user_id', $user)->firstOrFail();
        if ($target->role === 'owner' || (string) $team->owner_user_id === $user) {
            throw ValidationException::withMessages(['user' => ['The team owner cannot be removed. Delete the team instead.']]);
        }
        DB::transaction(function () use ($target, $team, $user) {
            // Leaving a team withdraws that member's previously attached private drafts.
            TeamDocument::where('team_id', $team->id)->where('attached_by_user_id', $user)->delete();
            $target->delete();
        });
        return response()->json(['success' => true, 'data' => ['removed' => true]]);
    }

    public function attachDocument(Request $request, string $team): JsonResponse
    {
        [$team] = $this->member($request, $team);
        $data = $request->validate(['document_id' => 'required|uuid']);
        $document = Document::where('user_id', $request->user()->id)->whereKey($data['document_id'])->firstOrFail();
        $link = TeamDocument::firstOrCreate(['team_id' => $team->id, 'document_id' => $document->id], ['attached_by_user_id' => $request->user()->id]);
        return response()->json(['success' => true, 'data' => ['document' => $document, 'link_id' => $link->id]], 201);
    }

    public function detachDocument(Request $request, string $team, string $document): JsonResponse
    {
        [$team, $membership] = $this->member($request, $team);
        $link = $this->linked($team, $document);
        abort_unless($membership->role === 'owner' || $link->attached_by_user_id === $request->user()->id, 403);
        $link->delete();
        return response()->json(['success' => true, 'data' => ['detached' => true]]);
    }

    public function documents(Request $request, string $team): JsonResponse
    {
        [$team, $membership] = $this->member($request, $team);
        $links = $team->documents()->with('document:id,user_id,title,updated_at')->withCount('comments')->latest()->get();
        return response()->json(['success' => true, 'data' => ['documents' => $links, 'role' => $membership->role]]);
    }

    public function showDocument(Request $request, string $team, string $document): JsonResponse
    {
        [$team, $membership] = $this->member($request, $team);
        $link = $this->linked($team, $document);
        return response()->json(['success' => true, 'data' => ['document' => $link->document,
            'role' => $membership->role, 'comments' => $link->comments()->with('user:id,name')->oldest()->limit(100)->get()]]);
    }

    public function updateDocument(Request $request, string $team, string $document): JsonResponse
    {
        [$team, $membership] = $this->member($request, $team);
        $link = $this->linked($team, $document);
        abort_unless(in_array($membership->role, ['owner', 'editor'], true), 403);
        $max = (int) config('studio.max_characters', 100000);
        $data = $request->validate(['current_text' => "required|string|max:{$max}", 'title' => 'sometimes|required|string|max:200']);
        $document = $link->document;
        if (!User::whereKey($document->user_id)->value('history_enabled')) {
            throw ValidationException::withMessages(['document' => ['The document owner has disabled saved history.']]);
        }
        DB::transaction(function () use ($document, $data, $request) {
            $before = $document->current_text;
            $document->fill($data);
            $changed = $document->isDirty('current_text');
            $document->save();
            if ($changed) {
                DocumentRevision::create(['document_id' => $document->id, 'action' => 'team_edit', 'input_text' => $before,
                    'output_text' => $document->current_text, 'text_hash' => hash('sha256', $document->current_text),
                    'result' => ['edited_by_user_id' => $request->user()->id]]);
            }
        });
        return response()->json(['success' => true, 'data' => ['document' => $document]]);
    }

    public function comments(Request $request, string $team, string $document): JsonResponse
    {
        [$team] = $this->member($request, $team);
        $comments = $this->linked($team, $document)->comments()->with('user:id,name')->oldest()->paginate(50);
        return response()->json(['success' => true, 'data' => ['comments' => $comments->items(),
            'current_page' => $comments->currentPage(), 'last_page' => $comments->lastPage(), 'total' => $comments->total()]]);
    }

    public function addComment(Request $request, string $team, string $document): JsonResponse
    {
        [$team] = $this->member($request, $team);
        $link = $this->linked($team, $document);
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $comment = TeamComment::create(['team_document_id' => $link->id, 'user_id' => $request->user()->id, 'body' => trim($data['body'])]);
        return response()->json(['success' => true, 'data' => ['comment' => $comment->load('user:id,name')]], 201);
    }

    public function comment(Request $request, string $team, string $document): JsonResponse
    {
        return $this->addComment($request, $team, $document);
    }

    private function member(Request $request, string $id): array
    {
        $membership = TeamMembership::where('team_id', $id)->where('user_id', $request->user()->id)->with('team')->firstOrFail();
        return [$membership->team, $membership];
    }

    private function owner(Request $request, string $id): array
    {
        [$team, $membership] = $this->member($request, $id);
        abort_unless($membership->role === 'owner' && $team->owner_user_id === $request->user()->id, 403);
        return [$team, $membership];
    }

    private function linked(Team $team, string $document): TeamDocument
    {
        return TeamDocument::where('team_id', $team->id)->where('document_id', $document)->firstOrFail();
    }
}
