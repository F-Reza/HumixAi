<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AiToolController;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'WalterAI Detector & Humanizer API',
        'version' => '1.2.0',
    ]);
});

Route::prefix('v1')->group(function () {
    Route::post('/detect', [AiToolController::class, 'detect']);
    Route::post('/humanize', [AiToolController::class, 'humanize']);
    Route::post('/combo', [AiToolController::class, 'combo']);
    Route::post('/update-key', [AiToolController::class, 'updateKey']);
    Route::get('/status', [AiToolController::class, 'status']);
});
