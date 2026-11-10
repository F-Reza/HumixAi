<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('studio', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('studio-api', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(6)->by($request->ip()),
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }
}
