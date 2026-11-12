<?php
namespace App\Http\Middleware;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
class SetLocale {
    public function handle(Request $request, Closure $next) {
        $locale = $request->header('X-Locale') ?: ($request->user()?->locale ?: ($request->hasSession() ? $request->session()->get('locale') : null));
        app()->setLocale(Locales::normalize($locale ?: $request->user()?->locale));
        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        return $response;
    }
}
