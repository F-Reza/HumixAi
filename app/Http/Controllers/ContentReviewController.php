<?php

namespace App\Http\Controllers;

use App\Services\CitationReviewService;
use App\Services\ContentReviewService;
use App\Services\StudioRunner;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ContentReviewController extends Controller
{
    public function review(Request $request, ContentReviewService $reviewer, StudioRunner $runner, UsageService $usage): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'type' => ['required', Rule::in(['grammar', 'guidelines', 'meaning'])],
            'guidelines' => ['required_if:type,guidelines', 'nullable', 'string', 'max:6000'],
            'revised_text' => ['required_if:type,meaning', 'nullable', 'string', 'regex:/\S/u', 'max:'.config('studio.max_characters', 60000)],
        ]);
        $runner->assertConsent($data);
        $runner->assertTokenScopes($request, 'humanize', $data);
        return $this->execute($request, $data, 'review_'.$data['type'], $runner, $usage,
            fn () => $reviewer->review($data['text'], $data['type'], $data));
    }

    public function citations(Request $request, CitationReviewService $reviewer, StudioRunner $runner, UsageService $usage): JsonResponse
    {
        $data = $request->validate($this->rules());
        $runner->assertConsent($data);
        $runner->assertTokenScopes($request, 'detect', $data);
        return $this->execute($request, $data, 'citations', $runner, $usage, fn () => $reviewer->review($data['text']));
    }

    private function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:15', 'regex:/\S/u', 'max:'.config('studio.max_characters', 60000)],
            'external_processing_consent' => ['sometimes', 'boolean'],
        ];
    }

    private function execute(Request $request, array $data, string $action, StudioRunner $runner, UsageService $usage, callable $review): JsonResponse
    {
        if (!$request->user()) {
            throw new HttpException(401, 'Sign in to use external review tools.');
        }
        $input = $data['text'];
        // Charge input units for exactly what this review sends; never store the submitted text here.
        if ($action === 'review_guidelines') {
            $input .= "\n".($data['guidelines'] ?? '');
        } elseif ($action === 'review_meaning') {
            $input .= "\n".($data['revised_text'] ?? '');
        }
        $words = max(1, $runner->wordCount($input));
        $reservation = $usage->reserve($request->user(), $words, $action);
        if (!($reservation['allowed'] ?? false)) {
            throw new HttpException(429, 'Your daily allowance is exhausted. Try a smaller draft or return tomorrow.');
        }
        try {
            $result = $review();
            $result['usage'] = ($result['status'] ?? null) === 'completed'
                ? $usage->settle($reservation['reservation_id'], $words)
                : $usage->release($reservation['reservation_id']);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (Throwable $exception) {
            $usage->release($reservation['reservation_id']);
            throw $exception;
        }
    }
}
