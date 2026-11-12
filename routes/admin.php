<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
Route::middleware(['auth','admin'])->prefix('admin')->group(function(){
    Route::get('/', fn()=>view('admin.index'))->name('admin');
    Route::prefix('api')->middleware('throttle:studio')->group(function(){
        Route::get('dashboard',[AdminController::class,'dashboard']);
        Route::get('users',[AdminController::class,'users']);
        Route::patch('users/{user}',[AdminController::class,'updateUser']);
        Route::post('users/{user}/revoke-access',[AdminController::class,'revokeAccess']);
        Route::post('users/{user}/usage-adjustments',[AdminController::class,'adjustUsage']);
        Route::get('jobs',[AdminController::class,'jobs']);
        Route::post('jobs/{job}/cancel',[AdminController::class,'cancel']);
        Route::get('settings',[AdminController::class,'settings']);
        Route::patch('settings',[AdminController::class,'updateSettings']);
        Route::get('providers',[AdminController::class,'providers']);
        Route::put('providers/{provider}',[AdminController::class,'updateProvider']);
        Route::get('audit',[AdminController::class,'auditLog']);
        Route::post('maintenance/prune',[AdminController::class,'prune']);
    });
});
