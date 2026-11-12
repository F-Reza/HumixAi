<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trimStrings(except: ['recording_text', 'text', 'source_text', 'current_text', 'revised_text', 'voice_sample', 'selection', 'guidelines', 'protected_terms.*']);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\ApplySystemSettings::class);
        $middleware->web(append: [\App\Http\Middleware\SetLocale::class, \Illuminate\Session\Middleware\AuthenticateSession::class]);
        $middleware->api(append: [\App\Http\Middleware\SetLocale::class]);
        $middleware->alias([
            'admin' => \App\Http\Middleware\AuthenticateAdministrator::class,
            'workspace' => \App\Http\Middleware\AuthenticateWorkspace::class,
            'api.token' => \App\Http\Middleware\AuthenticateApiToken::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['recording_text', 'sample', 'preferences', 'voice_sample', 'voice_preferences', 'api_key', 'current_password']);
        $exceptions->render(function (\App\Services\Exceptions\HumanizationException $exception, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage(),
                    'stage' => $exception->stage, 'violations' => $exception->violations,
                    'original_detection' => $exception->originalDetection], $exception->stage === 'validation' ? 422 : 503);
            }
        });
    })->create();
