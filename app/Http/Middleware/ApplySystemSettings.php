<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Services\Admin\SystemSettingsService;
class ApplySystemSettings { public function handle(Request $request, Closure $next) { app(SystemSettingsService::class)->apply(); return $next($request); } }
