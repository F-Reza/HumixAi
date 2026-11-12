<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStudioJob;
use App\Models\StudioJob;
use App\Services\StudioRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StudioBatchController extends Controller
{
    public function store(Request $request, StudioRunner $runner): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.config('studio.max_batch_size')],
            'items.*.text' => ['required', 'string', 'min:15', 'max:'.config('studio.max_characters')],
            'items.*.document_id' => ['nullable', 'uuid'],
            'action' => ['required', Rule::in(['detect', 'humanize', 'combo'])],
            'mode' => ['sometimes', Rule::in(['professional', 'standard', 'academic', 'casual', 'marketing'])],
            'language' => ['sometimes', Rule::in(['auto', 'en', 'bn', 'hi', 'ur', 'ar', 'ne', 'es', 'fr', 'de', 'pt'])],
            'strength' => ['sometimes', Rule::in(['light', 'balanced', 'strong'])],
            'length' => ['sometimes', Rule::in(['shorter', 'similar', 'longer'])],
            'external_processing_consent' => ['required', 'accepted'],
        ]);
        $runner->assertConsent($data);
        $runner->assertTokenScopes($request, $data['action'], $data);
        foreach ($data['items'] as $item) { $runner->assertTokenScopes($request, $data['action'], $item); }
        $jobs = DB::transaction(function () use ($request, $runner, $data) {
            $records = [];
            foreach ($data['items'] as $item) {
                $payload = array_merge(\Illuminate\Support\Arr::except($data, ['items']), $item);
                $document = $runner->document($request->user(), $payload);
                $id = (string) Str::uuid();
                $reservation = $runner->reserve($request->user(), $data['action'], $payload, $id);
                $records[] = StudioJob::create(['id' => $id, 'user_id' => $request->user()->id, 'document_id' => $document?->id,
                    'action' => $data['action'], 'payload' => $payload, 'status' => 'queued', 'stage' => 'queued', 'reservation_id' => $reservation['reservation_id']]);
            }
            return $records;
        });
        try {
            foreach ($jobs as $job) { ProcessStudioJob::dispatch($job->id)->onConnection(config('studio.queue_connection')); }
        } catch (\Throwable $exception) {
            // Already-running/completed work is left intact; undispatched work is cancelled immediately.
            foreach ($jobs as $record) {
                $cancelled = StudioJob::whereKey($record->id)->where('status', 'queued')->update([
                    'status' => 'failed', 'stage' => 'failed', 'payload' => json_encode([]),
                    'error' => 'The batch could not be queued. Submit this document again.', 'completed_at' => now(),
                ]);
                if ($cancelled) {
                    app(\App\Services\UsageService::class)->release($record->reservation_id);
                }
            }
            throw $exception;
        }
        return response()->json(['success' => true, 'data' => ['jobs' => collect($jobs)->map->only(['id', 'document_id', 'action', 'status', 'stage']), 'count' => count($jobs)]], 202);
    }
}
