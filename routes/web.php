<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AiToolController;
use Illuminate\Support\Facades\Artisan;

Route::get('/cc', function () {
    Artisan::call('cache:clear');
    Artisan::call('view:clear');
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('optimize:clear');
    return redirect()->back()->with('success', 'Cache cleared successfully');
})->name('clear-all');

// Direct root route to the AI Detector & Humanizer Interface
Route::get('/', [AiToolController::class, 'index'])->name('home');

Route::prefix('ai-tools')->name('ai-tools.')->group(function () {
    Route::get('/', [AiToolController::class, 'index'])->name('index');
    Route::post('/detect', [AiToolController::class, 'detect'])->name('detect');
    Route::post('/humanize', [AiToolController::class, 'humanize'])->name('humanize');
    Route::post('/combo', [AiToolController::class, 'combo'])->name('combo');
    Route::post('/update-key', [AiToolController::class, 'updateKey'])->name('update-key');
    Route::get('/status', [AiToolController::class, 'status'])->name('status');
});
