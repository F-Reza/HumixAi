<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tokens = ApiToken::where('user_id', $request->user()->id)->whereNull('revoked_at')->latest()->get();
        return response()->json(['success' => true, 'data' => ['tokens' => $tokens]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'scopes' => 'sometimes|required|array|min:1|max:4',
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(['detect', 'humanize', 'jobs', 'documents'])],
            'expires_in_days' => 'sometimes|integer|min:1|max:365',
        ]);
        if (ApiToken::where('user_id', $request->user()->id)->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 20) {
            return response()->json(['success' => false, 'message' => __('Revoke an existing token before creating another.')], 422);
        }
        $plain = 'hxi_'.Str::random(64);
        $token = ApiToken::create([
            'user_id' => $request->user()->id, 'name' => trim($data['name']),
            'token_hash' => hash('sha256', $plain), 'prefix' => substr($plain, 0, 12),
            'scopes' => $data['scopes'] ?? ['detect', 'humanize', 'jobs', 'documents'],
            'expires_at' => now()->addDays($data['expires_in_days'] ?? 90),
        ]);
        return response()->json(['success' => true, 'data' => ['token' => $token, 'plain_text_token' => $plain]], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $token = ApiToken::where('user_id', $request->user()->id)->whereKey($token)->firstOrFail();
        $token->forceFill(['revoked_at' => now()])->save();
        return response()->json(['success' => true, 'data' => ['revoked' => true]]);
    }
}
