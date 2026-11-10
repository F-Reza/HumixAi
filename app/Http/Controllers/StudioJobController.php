<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudioRequest;
use App\Jobs\ProcessStudioJob;
use App\Models\StudioJob;
use App\Services\StudioRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StudioJobController extends Controller
{
    public function store(StudioRequest $request, StudioRunner $runner): JsonResponse
    {
        $payload = $request->validated(); $runner->assertConsent($payload);
        $action = $payload['action'] ?? 'combo';
        $runner->assertTokenScopes($request, $action, $payload);
        $payload['action'] = $action;
        if (!empty($payload['idempotency_key'])) {
            try {
                return Cache::lock('humix-job:'.$request->user()->id.':'.hash('sha256', $payload['idempotency_key']), 15)
                    ->block(5, fn () => $this->enqueue($request, $runner, $payload));
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                throw new HttpException(409, 'This request is already being queued. Retry with the same idempotency key.');
            }
        }
        return $this->enqueue($request, $runner, $payload);
    }

    private function enqueue(StudioRequest $request, StudioRunner $runner, array $payload): JsonResponse
    {
        $action = $payload['action']; $document = $runner->document($request->user(), $payload);
        $fingerprint = hash('sha256', json_encode(Arr::sortRecursive(Arr::except($payload, ['idempotency_key'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (!empty($payload['idempotency_key'])) {
            $existing = StudioJob::where('user_id', $request->user()->id)->where('idempotency_key', $payload['idempotency_key'])->first();
            if ($existing) { return $this->replay($existing, $fingerprint); }
        }
        $id = (string) Str::uuid(); $reservation = $runner->reserve($request->user(), $action, $payload, $id);
        try {
            $job = StudioJob::create(['id' => $id, 'user_id' => $request->user()->id, 'document_id' => $document?->id,
                'action' => $action, 'payload' => $payload, 'status' => 'queued', 'stage' => 'queued',
                'reservation_id' => $reservation['reservation_id'], 'idempotency_key' => $payload['idempotency_key'] ?? null, 'request_hash' => $fingerprint]);
            ProcessStudioJob::dispatch($job->id)->onConnection(config('studio.queue_connection'));
        } catch (\Throwable $e) {
            app(\App\Services\UsageService::class)->release($reservation['reservation_id']);
            if (isset($job)) { $job->update(['status' => 'failed', 'stage' => 'failed', 'payload' => [], 'completed_at' => now(), 'error' => 'The processing job could not be queued. Please try again.']); }
            elseif ($e instanceof \Illuminate\Database\QueryException && !empty($payload['idempotency_key'])) {
                $existing = StudioJob::where('user_id', $request->user()->id)->where('idempotency_key', $payload['idempotency_key'])->first();
                if ($existing) { return $this->replay($existing, $fingerprint); }
            }
            throw $e;
        }
        return response()->json(['success' => true, 'data' => $this->serialize($job->refresh())], 202);
    }

    private function replay(StudioJob $job, string $fingerprint): JsonResponse
    {
        if (!$job->request_hash || !hash_equals($job->request_hash, $fingerprint)) {
            throw new HttpException(409, 'This idempotency key belongs to a different request. Use a new key for changed text or settings.');
        }
        return response()->json(['success' => true, 'data' => $this->serialize($job)]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => StudioJob::where('user_id', $request->user()->id)->latest()->limit(30)->get()->map(fn ($job) => $this->serialize($job))]);
    }

    public function show(Request $request, string $job): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->serialize(StudioJob::where('user_id', $request->user()->id)->findOrFail($job))]);
    }

    private function serialize(StudioJob $job): array
    {
        return $job->only(['id', 'document_id', 'action', 'status', 'stage', 'error', 'result', 'created_at', 'started_at', 'completed_at']);
    }
}
