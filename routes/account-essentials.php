<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AccountSecurityController,VoiceProfileController};
Route::get('/forgot-password',fn()=>view('auth.forgot-password'))->name('password.request');
Route::post('/forgot-password',[AccountSecurityController::class,'forgot'])->middleware('throttle:auth')->name('password.email');
Route::get('/reset-password/{token}',fn(string $token)=>view('auth.reset-password',['token'=>$token]))->name('password.reset');
Route::post('/reset-password',[AccountSecurityController::class,'reset'])->middleware('throttle:auth')->name('password.update');
Route::middleware(['auth','workspace'])->group(function(){
 Route::get('/account/security',fn()=>view('auth.security'))->name('verification.notice');
 Route::post('/email/verification-notification',[AccountSecurityController::class,'sendVerification'])->middleware('throttle:3,60')->name('verification.send');
 Route::get('/email/verify/{id}/{hash}',[AccountSecurityController::class,'verify'])->middleware(['signed:relative','throttle:6,1'])->name('verification.verify');
 Route::get('/voice-profiles',[VoiceProfileController::class,'index']);
 Route::get('/voice-profiles/new',fn()=>view('voice.edit',['profile'=>null]));
 Route::post('/voice-profiles',[VoiceProfileController::class,'store'])->middleware('throttle:studio');
 Route::get('/voice-profiles/{id}',[VoiceProfileController::class,'show']);
 Route::get('/voice-profiles/{id}/edit',[VoiceProfileController::class,'edit']);
 Route::put('/voice-profiles/{id}',[VoiceProfileController::class,'update'])->middleware('throttle:studio');
 Route::delete('/voice-profiles/{id}',[VoiceProfileController::class,'destroy']);
});

Route::middleware(['auth','workspace','throttle:studio'])->prefix('website-checks')->group(function(){
 Route::get('/',[\App\Http\Controllers\WebsiteCheckController::class,'index']);
 Route::get('/export',[\App\Http\Controllers\WebsiteCheckController::class,'export']);
 Route::post('/',[\App\Http\Controllers\WebsiteCheckController::class,'store']);
 Route::patch('/{id}',[\App\Http\Controllers\WebsiteCheckController::class,'update']);
 Route::delete('/{id}',[\App\Http\Controllers\WebsiteCheckController::class,'destroy']);
 Route::post('/{id}/run',[\App\Http\Controllers\WebsiteCheckController::class,'run'])->middleware('throttle:3,1');
});

Route::middleware(['auth','workspace','throttle:studio'])->prefix('sitemap')->group(function(){
 Route::get('/',[\App\Http\Controllers\SitemapController::class,'index']);
 Route::post('/preview',[\App\Http\Controllers\SitemapController::class,'preview'])->middleware('throttle:3,1');
 Route::post('/schedule',[\App\Http\Controllers\SitemapController::class,'store']);
});

Route::middleware(['auth','workspace'])->prefix('writing-records')->group(function(){
 Route::get('/',[\App\Http\Controllers\WritingRecordController::class,'index']);
 Route::get('/new',[\App\Http\Controllers\WritingRecordController::class,'create']);
 Route::post('/',[\App\Http\Controllers\WritingRecordController::class,'store'])->middleware('throttle:studio');
 Route::get('/{id}',[\App\Http\Controllers\WritingRecordController::class,'show']);
 Route::patch('/{id}/state',[\App\Http\Controllers\WritingRecordController::class,'state'])->middleware('throttle:studio');
 Route::post('/{id}/frames',[\App\Http\Controllers\WritingRecordController::class,'append'])->middleware('throttle:60,1');
 Route::get('/{id}/frames',[\App\Http\Controllers\WritingRecordController::class,'frames']);
 Route::get('/{id}/export',[\App\Http\Controllers\WritingRecordController::class,'export']);
 Route::delete('/{id}',[\App\Http\Controllers\WritingRecordController::class,'destroy']);
});
