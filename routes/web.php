<?php

use App\Http\Controllers\{AccountController, AiToolController, AuthController, DocumentController, ImportController, ShareController, StudioJobController, TokenController};
use Illuminate\Support\Facades\Route;

Route::get('/cc', function () {
    Artisan::call('cache:clear');
    Artisan::call('view:clear');
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('optimize:clear');
    return redirect()->back()->with('success', 'Cache cleared successfully');
})->name('clear-all');


Route::get('/', [AiToolController::class, 'index'])->name('home');
Route::get('/ai-tools', [AiToolController::class, 'index'])->name('ai-tools.index');
Route::get('/login', fn () => view('auth.login'))->middleware('guest')->name('login');
Route::get('/register', fn () => view('auth.register'))->middleware('guest')->name('register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::get('/ai-tools/status', [AiToolController::class, 'status'])->name('ai-tools.status');
Route::post('/ai-tools/style', [AiToolController::class, 'style'])->middleware('throttle:studio');
Route::get('/shared/{token}', [ShareController::class, 'show'])->middleware('throttle:studio');
Route::get('/shared/{token}/export', [ShareController::class, 'export'])->middleware('throttle:studio');

Route::middleware('workspace')->group(function () {
    Route::prefix('ai-tools')->name('ai-tools.')->group(function () {
        Route::post('/detect', [AiToolController::class, 'detect'])->middleware('throttle:studio')->name('detect');
        Route::post('/humanize', [AiToolController::class, 'humanize'])->middleware('throttle:studio')->name('humanize');
        Route::post('/combo', [AiToolController::class, 'combo'])->middleware('throttle:studio')->name('combo');
        Route::post('/jobs', [StudioJobController::class, 'store'])->middleware('throttle:studio')->name('jobs.store');
        Route::get('/jobs', [StudioJobController::class, 'index'])->name('jobs.index');
        Route::get('/jobs/{job}', [StudioJobController::class, 'show'])->name('jobs.show');
        Route::post('/batch', [\App\Http\Controllers\StudioBatchController::class, 'store'])->middleware('throttle:studio')->name('batch');
        Route::post('/review', [\App\Http\Controllers\ContentReviewController::class, 'review'])->middleware('throttle:studio')->name('review');
        Route::post('/citations', [\App\Http\Controllers\ContentReviewController::class, 'citations'])->middleware('throttle:studio')->name('citations');
        Route::post('/plagiarism', [\App\Http\Controllers\PlagiarismController::class, 'store'])->middleware('throttle:studio')->name('plagiarism');
    });
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::patch('/documents/{document}', [DocumentController::class, 'update']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
    Route::get('/documents/{document}/revisions', [DocumentController::class, 'revisions']);
    Route::get('/documents/{document}/export', [DocumentController::class, 'export']);
    Route::post('/documents/{document}/share', [ShareController::class, 'store']);
    Route::delete('/documents/{document}/share', [ShareController::class, 'revokeAll']);
    Route::post('/documents/{document}/shares', [ShareController::class, 'store']);
    Route::delete('/documents/{document}/shares/{share}', [ShareController::class, 'destroy']);
    Route::post('/imports', [ImportController::class, 'preview'])->middleware('throttle:studio');
    Route::post('/imports/preview', [ImportController::class, 'preview'])->middleware('throttle:studio');
    Route::post('/imports/url', [\App\Http\Controllers\UrlImportController::class, 'preview'])->middleware('throttle:studio');
    Route::get('/account', [AccountController::class, 'show']);
    Route::get('/billing/status', [\App\Http\Controllers\BillingController::class, 'status']);
    Route::post('/billing/checkout', [\App\Http\Controllers\BillingController::class, 'checkout'])->middleware('throttle:studio');
    Route::post('/billing/portal', [\App\Http\Controllers\BillingController::class, 'portal'])->middleware('throttle:studio');
    Route::patch('/account', [AccountController::class, 'update']);
    Route::delete('/account', [AccountController::class, 'destroy']);
    Route::get('/account/tokens', [TokenController::class, 'index']);
    Route::post('/account/tokens', [TokenController::class, 'store']);
    Route::delete('/account/tokens/{token}', [TokenController::class, 'destroy']);
    Route::get('/tokens', [TokenController::class, 'index']);
    Route::post('/tokens', [TokenController::class, 'store']);
    Route::delete('/tokens/{token}', [TokenController::class, 'destroy']);
    Route::get('/teams', [\App\Http\Controllers\TeamController::class, 'index']);
    Route::post('/teams', [\App\Http\Controllers\TeamController::class, 'store']);
    Route::post('/teams/join', [\App\Http\Controllers\TeamController::class, 'join']);
    Route::get('/teams/{team}', [\App\Http\Controllers\TeamController::class, 'show']);
    Route::patch('/teams/{team}', [\App\Http\Controllers\TeamController::class, 'update']);
    Route::delete('/teams/{team}', [\App\Http\Controllers\TeamController::class, 'destroy']);
    Route::post('/teams/{team}/invitations', [\App\Http\Controllers\TeamController::class, 'invite']);
    Route::delete('/teams/{team}/invitations/{invitation}', [\App\Http\Controllers\TeamController::class, 'revokeInvitation']);
    Route::delete('/teams/{team}/members/{user}', [\App\Http\Controllers\TeamController::class, 'removeMember']);
    Route::get('/teams/{team}/documents', [\App\Http\Controllers\TeamController::class, 'documents']);
    Route::post('/teams/{team}/documents', [\App\Http\Controllers\TeamController::class, 'attachDocument']);
    Route::get('/teams/{team}/documents/{document}', [\App\Http\Controllers\TeamController::class, 'showDocument']);
    Route::patch('/teams/{team}/documents/{document}', [\App\Http\Controllers\TeamController::class, 'updateDocument']);
    Route::delete('/teams/{team}/documents/{document}', [\App\Http\Controllers\TeamController::class, 'detachDocument']);
    Route::get('/teams/{team}/documents/{document}/comments', [\App\Http\Controllers\TeamController::class, 'comments']);
    Route::post('/teams/{team}/documents/{document}/comments', [\App\Http\Controllers\TeamController::class, 'comment']);
});

Route::patch('/locale', [\App\Http\Controllers\LocaleController::class, 'update']);
Route::get('/localization/catalog', [\App\Http\Controllers\LocaleController::class, 'catalog']);
require __DIR__.'/admin.php';

require __DIR__.'/account-essentials.php';

Route::post('/ai-tools/local-patterns', [AiToolController::class, 'localPatterns'])->middleware('throttle:studio');
