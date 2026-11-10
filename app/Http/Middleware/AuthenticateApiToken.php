<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $plain = $request->bearerToken();
        if (!$plain || strlen($plain) > 150) {
            return response()->json(['success' => false, 'message' => 'A valid API bearer token is required.'], 401);
        }
        $token = ApiToken::with('user')->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if (!$token || !$token->user) {
            return response()->json(['success' => false, 'message' => 'The API token is invalid, expired, or revoked.'], 401);
        }
        $required = $scope === 'rewrite' ? 'humanize' : $scope;
        if ($required && !in_array($required, $token->scopes ?? [], true)) {
            return response()->json(['success' => false, 'message' => 'This token does not have the required scope.'], 403);
        }
        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);
        $token->forceFill(['last_used_at' => now()])->save();
        return $next($request);
    }
}
