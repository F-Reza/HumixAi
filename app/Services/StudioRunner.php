<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StudioRunner
{
    public function __construct(private AiDetectorService $detector, private AiHumanizerService $humanizer, private UsageService $usage) {}

    public function assertConsent(array $payload): void
    {
        if (!filter_var($payload['external_processing_consent'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw ValidationException::withMessages(['external_processing_consent' => 'Please acknowledge external processing before sending your draft to a provider.']);
        }
    }

    public function wordCount(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\x{200C}\x{200D}\x{2019}\x{0027}-]*/u', $text, $matches);
        return count($matches[0]);
    }

    public function assertTokenScopes(\Illuminate\Http\Request $request, string $action, array $payload): void
    {
        $token = $request->attributes->get('api_token');
        if (!$token) { return; }
        $required = match ($action) { 'combo' => ['detect', 'humanize'], 'humanize' => ['humanize'], default => ['detect'] };
        if (!empty($payload['document_id'])) { $required[] = 'documents'; }
        foreach ($required as $scope) {
            if (!in_array($scope, $token->scopes ?? [], true)) { throw new HttpException(403, 'This token does not have permission for the requested processing action.'); }
        }
    }

    public function reserve(User $user, string $action, array $payload, ?string $reference = null): array
    {
        $words = max(1, $this->wordCount($payload['text']));
        $budget = $words * ($action === 'combo' ? 4 : 1);
        $reservation = $this->usage->reserve($user->id, $budget, $action, $reference);
        if (!($reservation['allowed'] ?? false)) { throw new HttpException(429, 'Your daily allowance is exhausted. Try a smaller draft or return tomorrow.'); }
        return $reservation;
    }

    public function document(User $user, array $payload): ?Document
    {
        return empty($payload['document_id']) ? null : Document::where('user_id', $user->id)->findOrFail($payload['document_id']);
    }

    public function execute(User $user, string $action, array $payload, ?string $reservationId = null, ?callable $progress = null): array
    {
        $document = $this->document($user, $payload);
        $reservationId ??= $this->reserve($user, $action, $payload)['reservation_id'];
        try {
            $text = $payload['text'];
            if ($action === 'detect') {
                $progress && $progress('detection', 'running', 'Analyzing the original draft');
                $result = $this->detector->detect($text, $payload['language'] ?? 'auto');
                $progress && $progress('detection', $result['status'], $result['message'] ?? 'Detection finished');
            } else {
                $result = $this->humanizer->humanize($text, $payload['mode'] ?? 'professional', $action === 'combo', $payload, $progress);
            }
            $actual = $this->completedWords($action, $result, $text);
            $result['usage'] = $this->usage->settle($reservationId, $actual, true);
            if ($document) {
                $revision = DB::transaction(function () use ($user, $document, $action, $text, $result) {
                    $currentUser = User::whereKey($user->id)->lockForUpdate()->first();
                    $currentDocument = Document::whereKey($document->id)->where('user_id', $user->id)->lockForUpdate()->first();
                    if (!$currentUser?->history_enabled || !$currentDocument) { return null; }
                    return DocumentRevision::create(['document_id' => $document->id, 'action' => $action,
                        'input_text' => $text, 'output_text' => $result['humanized_text'] ?? null, 'text_hash' => hash('sha256', $text), 'result' => $result]);
                });
                if ($revision) { $result['revision_id'] = $revision->id; $result['document_id'] = $document->id; }
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($exception instanceof \App\Services\Exceptions\HumanizationException && !empty($exception->completedOperations)) {
                $this->usage->settle($reservationId, $this->wordCount($payload['text']) * count($exception->completedOperations), true);
            } else {
                $this->usage->release($reservationId);
            }
            throw $exception;
        }
    }

    private function completedWords(string $action, array $result, string $text): int
    {
        if ($action === 'detect') { return ($result['status'] ?? null) === 'completed' ? $this->wordCount($text) : 0; }
        $words = $this->wordCount($text);
        foreach (['original_detection', 'verification'] as $key) {
            if (($result[$key]['status'] ?? null) === 'completed') { $words += $this->wordCount($text); }
        }
        return $words;
    }
}
