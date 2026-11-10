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
        $middleware->trimStrings(except: ['text', 'source_text', 'current_text', 'revised_text', 'voice_sample', 'selection', 'guidelines', 'protected_terms.*']);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'workspace' => \App\Http\Middleware\AuthenticateWorkspace::class,
            'api.token' => \App\Http\Middleware\AuthenticateApiToken::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Services\Exceptions\HumanizationException $exception, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage(),
                    'stage' => $exception->stage, 'violations' => $exception->violations,
                    'original_detection' => $exception->originalDetection], $exception->stage === 'validation' ? 422 : 503);
            }
        });
    })->create();
