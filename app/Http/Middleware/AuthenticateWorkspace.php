<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json(['success' => false, 'message' => __('Sign in to access your workspace.')], 401);
        }
        abort_if($request->user()?->suspended_at, 403, __('This account is suspended.'));
        return $next($request);
    }
}
