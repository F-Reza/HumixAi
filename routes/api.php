<?php

use App\Http\Controllers\{AiToolController, DocumentController, ImportController, StudioJobController};
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'HumixAi Studio', 'version' => '2.0.0']));
Route::get('/v1/status', [AiToolController::class, 'status']);
Route::post('/billing/webhook', [\App\Http\Controllers\BillingController::class, 'webhook']);
Route::prefix('v1')->middleware(['api.token', 'throttle:studio-api'])->group(function () {
    Route::post('/local-patterns', [AiToolController::class, 'localPatterns'])->middleware('api.token:detect');
    Route::post('/style', [AiToolController::class, 'style'])->middleware('api.token:detect');
    Route::post('/detect', [AiToolController::class, 'detect'])->middleware('api.token:detect');
    Route::post('/humanize', [AiToolController::class, 'humanize'])->middleware('api.token:rewrite');
    Route::post('/combo', [AiToolController::class, 'combo'])->middleware(['api.token:detect', 'api.token:rewrite']);
    Route::post('/jobs', [StudioJobController::class, 'store'])->middleware('api.token:jobs');
    Route::get('/jobs', [StudioJobController::class, 'index'])->middleware('api.token:jobs');
    Route::get('/jobs/{job}', [StudioJobController::class, 'show'])->middleware('api.token:jobs');
    Route::post('/batch', [\App\Http\Controllers\StudioBatchController::class, 'store'])->middleware('api.token:jobs');
    Route::post('/review', [\App\Http\Controllers\ContentReviewController::class, 'review'])->middleware('api.token:rewrite');
    Route::post('/citations', [\App\Http\Controllers\ContentReviewController::class, 'citations'])->middleware('api.token:detect');
    Route::post('/plagiarism', [\App\Http\Controllers\PlagiarismController::class, 'store'])->middleware('api.token:detect');
    Route::middleware('api.token:documents')->group(function () {
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/documents/{document}', [DocumentController::class, 'show']);
        Route::patch('/documents/{document}', [DocumentController::class, 'update']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
        Route::get('/documents/{document}/revisions', [DocumentController::class, 'revisions']);
        Route::get('/documents/{document}/export', [DocumentController::class, 'export']);
        Route::post('/imports', [ImportController::class, 'preview']);
        Route::post('/imports/url', [\App\Http\Controllers\UrlImportController::class, 'preview']);
    });
});
