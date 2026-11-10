<?php

use App\Models\StudioJob;
use App\Services\UsageService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('humix:status', function () {
    $this->info('HumixAi Studio');
    $this->line('Detector: '.(filled(config('services.gptzero.api_key')) ? 'configured (not connectivity-tested)' : 'not configured'));
    $this->line('Rewriter: '.(filled(config('services.gemini.api_key')) ? 'configured (not connectivity-tested)' : 'not configured'));
    $this->line('Queue: '.config('studio.queue_connection'));
})->purpose('Show configuration availability without revealing credentials');

Artisan::command('humix:prune', function () {
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
