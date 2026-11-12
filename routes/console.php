<?php

use App\Models\StudioJob;
use App\Services\UsageService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('humix:status', function () {
    app(\App\Services\Admin\SystemSettingsService::class)->apply();
    $this->info('HumixAi Studio');
    $this->line('Detector: '.(filled(config('services.gptzero.api_key')) ? 'configured (not connectivity-tested)' : 'not configured'));
    $this->line('Rewriter: '.(filled(config('services.gemini.api_key')) ? 'configured (not connectivity-tested)' : 'not configured'));
    $this->line('Queue: '.config('studio.queue_connection'));
    $this->line('Account email: '.(\App\Http\Controllers\AccountSecurityController::emailEnabled() ? 'configured (not delivery-tested)' : 'not configured'));
    $this->line('OCR languages: '.(implode(', ', app(\App\Services\OcrService::class)->languages()) ?: 'none available'));
})->purpose('Show configuration availability without revealing credentials');

Artisan::command('humix:prune', function () {
    app(\App\Services\Admin\SystemSettingsService::class)->apply();
    $expired = StudioJob::whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(15))->get();
    foreach ($expired as $job) {
        $changed = StudioJob::whereKey($job->id)->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'stage' => 'expired', 'payload' => json_encode([]), 'error' => 'This job expired before finishing.', 'completed_at' => now()]);
        if ($changed) { app(UsageService::class)->release($job->reservation_id); }
    }
    $count = StudioJob::whereIn('status', ['completed', 'partial', 'failed'])->where('updated_at', '<', now()->subHours(config('studio.job_retention_hours')))->delete();
    $this->info("Removed {$count} expired transient jobs.");
})->purpose('Remove expired job text and release abandoned reservations');

Schedule::command('humix:prune')->hourly()->withoutOverlapping();

Artisan::command('humix:admin {email} {--create : Create a missing account using hidden password prompts}', function () {
    $email = mb_strtolower(trim($this->argument('email')));
    if (\Illuminate\Support\Facades\Validator::make(['email'=>$email], ['email'=>'required|email:rfc|max:254'])->fails()) {
        $this->error('Enter a valid email address.'); return 1;
    }
    $user = \App\Models\User::where('email', $email)->first();
    if ($user?->suspended_at) { $this->error('This account is suspended. Reactivate it before promotion.'); return 1; }
    if (!$user) {
        if (!$this->option('create')) {
            $this->error('No account exists for this email. Register first, or run: php artisan humix:admin '.$email.' --create'); return 1;
        }
        if (!$this->input->isInteractive()) { $this->error('Account creation requires an interactive terminal for hidden password entry.'); return 1; }
        $name = $this->ask('Administrator name', 'System Admin');
        // Never accept passwords in command arguments, logs or visible fallback prompts.
        $password = $this->secret('Password (at least 10 characters)', false);
        $confirmation = $this->secret('Confirm password', false);
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['name'=>$name, 'password'=>$password, 'password_confirmation'=>$confirmation],
            ['name'=>'required|string|max:100', 'password'=>'required|string|min:10|max:128|confirmed']
        );
        if ($validator->fails()) { foreach ($validator->errors()->all() as $error) $this->error($error); return 1; }
        \Illuminate\Support\Facades\DB::transaction(function () use ($name, $email, $password) {
            $created = \App\Models\User::create(['name'=>trim($name),'email'=>$email,'password'=>$password]);
            $created->forceFill(['role'=>'super_admin'])->save();
            app(\App\Services\Admin\SystemSettingsService::class)->audit(null,'admin.create_cli',(string)$created->id);
        });
        $this->info('Administrator account created. Sign in at /login, then open /admin.'); return 0;
    }
    \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
        $user->forceFill(['role'=>'super_admin'])->save();
        app(\App\Services\Admin\SystemSettingsService::class)->audit(null,'admin.promote_cli',(string)$user->id);
    });
    $this->info('Existing account promoted to super administrator.');
})->purpose('Promote an active account or securely create one with --create');

Artisan::command('humix:website-checks',function(){
 app(\App\Services\Admin\SystemSettingsService::class)->apply();
 $checks=\App\Models\WebsiteCheck::where('active',true)->where('next_run_at','<=',now())->where(fn($q)=>$q->whereNull('running_until')->orWhere('running_until','<',now()))->orderBy('next_run_at')->limit(20)->get();
 $count=0;foreach($checks as $check)if(app(\App\Services\WebsiteCheckScheduler::class)->dispatch($check))$count++;
 $this->info('Queued '.$count.' scheduled website checks.');
})->purpose('Queue bounded, explicitly consented public-page checks');
Schedule::command('humix:website-checks')->everyFiveMinutes()->withoutOverlapping();
