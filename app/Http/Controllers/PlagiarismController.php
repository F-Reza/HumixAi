<?php

namespace App\Http\Controllers;

use App\Services\PlagiarismReviewService;
use App\Services\StudioRunner;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class PlagiarismController extends Controller
{
    public function store(Request $request, PlagiarismReviewService $reviewer, StudioRunner $runner, UsageService $usage): JsonResponse
    {
        if (!$request->user()) { throw new HttpException(401, 'Sign in to check web similarity.'); }
        $data = $request->validate([
            'text' => ['required', 'string', 'min:50', 'regex:/\S/u', 'max:'.config('studio.max_characters', 60000)],
            'external_processing_consent' => ['sometimes', 'boolean'],
        ]);
        $runner->assertConsent($data);
        $runner->assertTokenScopes($request, 'detect', $data);
        $words = max(1, $runner->wordCount($data['text']));
        $reservation = $usage->reserve($request->user(), $words, 'plagiarism');
        if (!($reservation['allowed'] ?? false)) { throw new HttpException(429, 'Your daily allowance is exhausted. Try a smaller draft or return tomorrow.'); }
        try {
            $result = $reviewer->review($data['text']);
            $result['usage'] = $result['status'] === 'completed'
                ? $usage->settle($reservation['reservation_id'], $words) : $usage->release($reservation['reservation_id']);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (Throwable $exception) {
            $usage->release($reservation['reservation_id']);
            throw $exception;
        }
    }
}
