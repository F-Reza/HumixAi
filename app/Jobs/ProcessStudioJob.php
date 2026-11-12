<?php

namespace App\Jobs;

use App\Models\StudioJob;
use App\Services\StudioRunner;
use App\Services\UsageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessStudioJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 300;
    public bool $failOnTimeout = true;
    public function __construct(public string $studioJobId) {}

    public function handle(StudioRunner $runner): void
    {
        app(\App\Services\Admin\SystemSettingsService::class)->apply();
        app()->setLocale('en');
        $job = StudioJob::find($this->studioJobId);
        if (!$job || $job->status !== 'queued') { return; }
        $claimed = DB::transaction(function () use ($job) {
            $reservation = \App\Models\UsageReservation::whereKey($job->reservation_id)->lockForUpdate()->first();
            if (!$reservation || $reservation->status !== 'reserved' || $reservation->expires_at->isPast()) {
                $changed = StudioJob::whereKey($job->id)->where('status', 'queued')->update(['status' => 'failed', 'stage' => 'expired',
                    'payload' => json_encode([]), 'error' => 'This job’s allowance reservation expired. Submit it again.', 'completed_at' => now()]);
                if ($changed && $reservation) { app(UsageService::class)->release($reservation->id); }
                return false;
            }
            if (!StudioJob::whereKey($job->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()])) { return false; }
            // A claimed job has bounded provider timeouts and retains its reservation for the full run.
            if ($reservation->expires_at->lt(now()->addMinutes(10))) { $reservation->update(['expires_at' => now()->addMinutes(10)]); }
            return true;
        });
        if (!$claimed) { return; }
        try {
            $user = \App\Models\User::find($job->user_id);
            if (!$user || $user->suspended_at) { throw new \RuntimeException('Account unavailable.'); }
            app()->setLocale(\App\Support\Locales::normalize($user->locale));
            $result = $runner->execute($user, $job->action, $job->payload, $job->reservation_id, function ($stage, $status, $message) use ($job) {
                $job->update(['stage' => $stage.':'.$status]);
            });
            $partial = $job->action === 'detect' ? ($result['status'] ?? 'error') !== 'completed'
                : (!(bool) ($result['preservation']['passed'] ?? false) || ($job->action === 'combo' && !($result['comparable'] ?? false)));
            $job->update(['status' => $partial ? 'partial' : 'completed', 'stage' => 'finished', 'result' => $result, 'payload' => [], 'completed_at' => now()]);
        } catch (\Throwable $e) {
            app(UsageService::class)->release($job->reservation_id);
            $safeError = $e instanceof \App\Services\Exceptions\HumanizationException ? $e->getMessage() : 'This job could not finish. Check provider configuration and try again. No result was reported as verified.';
            $safeResult = $e instanceof \App\Services\Exceptions\HumanizationException ? [
                'original_detection' => $e->originalDetection, 'violations' => $e->violations,
                'completed_operations' => $e->completedOperations, 'usage' => app(UsageService::class)->summary($job->user_id),
            ] : null;
            $job->update(['status' => 'failed', 'stage' => 'failed', 'error' => $safeError, 'result' => $safeResult, 'payload' => [], 'completed_at' => now()]);
            Log::warning('HumixAi job failed', ['job_id' => $job->id, 'exception_class' => get_class($e)]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $job = StudioJob::find($this->studioJobId);
        if (!$job || in_array($job->status, ['completed', 'partial', 'failed'], true)) { return; }
        app(UsageService::class)->release($job->reservation_id);
        $job->update(['status' => 'failed', 'stage' => 'failed', 'error' => 'Processing timed out. Please try a shorter draft.', 'payload' => [], 'completed_at' => now()]);
    }
}
