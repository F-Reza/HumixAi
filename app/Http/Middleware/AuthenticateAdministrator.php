<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class AuthenticateAdministrator {
    public function handle(Request $request, Closure $next) {
        abort_unless($request->user() && !$request->user()->suspended_at && in_array($request->user()->role, ['admin','super_admin'], true), 403, __('Administrator access required.'));
        return $next($request);
    }
}
