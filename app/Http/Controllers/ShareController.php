<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ShareController extends Controller
{
    public function store(Request $request, string $document): JsonResponse
    {
        $document = Document::where('user_id', $request->user()->id)->whereKey($document)->firstOrFail();
        $data = $request->validate(['expires_in_days' => 'sometimes|integer|min:1|max:30']);
        $plain = 'sh_'.Str::random(64);
        $share = DocumentShare::create([
            'document_id' => $document->id, 'user_id' => $request->user()->id,
            'token_hash' => hash('sha256', $plain), 'snapshot' => DocumentController::snapshot($document),
            'expires_at' => now()->addDays($data['expires_in_days'] ?? 7),
        ]);
        return response()->json(['success' => true, 'data' => [
            'id' => $share->id, 'url' => url('/shared/'.$plain), 'expires_at' => $share->expires_at->toIso8601String(),
        ]], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $document, ?string $share = null): JsonResponse
    {
        $document = Document::where('user_id', $request->user()->id)->whereKey($document)->firstOrFail();
        $query = DocumentShare::where('document_id', $document->id)->where('user_id', $request->user()->id);
        if ($share !== null) {
            $record = $query->whereKey($share)->firstOrFail();
            $record->forceFill(['revoked_at' => now()])->save();
            $count = 1;
        } else { $count = $query->whereNull('revoked_at')->update(['revoked_at' => now()]); }
        return response()->json(['success' => true, 'data' => ['revoked' => true, 'count' => $count]]);
    }

    public function revokeAll(Request $request, string $document): JsonResponse
    {
        return $this->destroy($request, $document);
    }

    public function show(Request $request, string $token): Response
    {
        $share = $this->validShare($token);
        if (!$request->expectsJson() && view()->exists('documents.share')) {
            return response()->view('documents.share', ['snapshot' => $share->snapshot, 'document' => $share->snapshot, 'share' => $share, 'token' => $token])
                ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }
        return response()->json(['success' => true, 'data' => ['document' => $share->snapshot, 'expires_at' => $share->expires_at->toIso8601String()]])
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function export(Request $request, string $token): Response
    {
        return DocumentController::downloadPayload($this->validShare($token)->snapshot, (string) $request->query('format', 'report'));
    }

    private function validShare(string $plain): DocumentShare
    {
        abort_unless(strlen($plain) <= 100, 404);
        return DocumentShare::where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')
            ->where('expires_at', '>', now())->firstOrFail();
    }
}
