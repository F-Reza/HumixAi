<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudioRequest;
use App\Services\AiDetectorService;
use App\Services\DocumentImportService;
use App\Services\GeminiApiService;
use App\Services\StudioRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiToolController extends Controller
{
    public function index(): View { return view('ai-tools.index'); }

    public function status(Request $request, AiDetectorService $detector, GeminiApiService $gemini, DocumentImportService $imports, \App\Services\PlagiarismReviewService $plagiarism, \App\Services\BillingService $billing): JsonResponse
    {
        $urlCapabilities = app(\App\Services\UrlImportService::class)->capabilities();
        $importCapabilities = array_merge($imports->capabilities(), \Illuminate\Support\Arr::except($urlCapabilities, ['max_bytes']), ['url_max_bytes' => $urlCapabilities['max_bytes']]);
        return response()->json(['success' => true, 'data' => [
            'brand' => 'HumixAi', 'detector' => $detector->status(),
            'rewriter' => ['configured' => $gemini->isConfigured(), 'provider' => 'gemini', 'model' => config('services.gemini.model')],
            'imports' => $importCapabilities,
            'plagiarism' => $plagiarism->status(),
            'limits' => ['max_characters' => config('studio.max_characters'), 'max_file_kb' => config('studio.max_file_kb'),
                'daily_words' => $request->user()?->quota_override ?? $request->user()?->daily_word_limit ?? config('studio.daily_word_limit'), 'max_batch_size' => config('studio.max_batch_size')],
            'user' => $request->user()?->only(['id', 'name', 'email', 'history_enabled']),
            'features' => ['style_review' => true, 'document_history' => true, 'batch' => true, 'api_tokens' => true,
                'docx_export' => true, 'share_reports' => true, 'content_review' => true, 'citations' => true, 'teams' => true,
                'plagiarism' => $plagiarism->status()['configured'], 'media_detection' => false, 'billing' => $billing->configured()],
            'external_processing_notice' => __('Detection and citation review send your draft to GPTZero. Rewriting and writing feedback send text and any supplied voice reference to Google Gemini. Optional plagiarism review sends text to Walter Writes. Only requested stages are sent. Scores are estimates, not proof of authorship. Voice references are transient processing data cleared when work finishes or expires; saved voice profiles require separate explicit consent and can be managed or deleted from your account. Expired job cleanup follows the configured server retention schedule.'),
            'language_notice' => __('English, Bangla, Hindi, Urdu, Arabic and Nepali are available. Multilingual accuracy requires evaluation; probabilities are not proof of authorship.'),
        ]]);
    }

    public function localPatterns(StudioRequest $request, \App\Services\LocalPatternService $patterns): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>$patterns->analyze($request->string('text')->toString(), $request->input('language','auto'))]);
    }

    public function style(StudioRequest $request, AiDetectorService $detector): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $detector->analyzeLocal($request->string('text')->toString(), $request->input('language', 'auto'))]);
    }

    public function detect(StudioRequest $request, StudioRunner $runner): JsonResponse { return $this->execute($request, $runner, 'detect'); }
    public function humanize(StudioRequest $request, StudioRunner $runner): JsonResponse { return $this->execute($request, $runner, 'humanize'); }
    public function combo(StudioRequest $request, StudioRunner $runner): JsonResponse { return $this->execute($request, $runner, 'combo'); }

    private function execute(StudioRequest $request, StudioRunner $runner, string $action): JsonResponse
    {
        $data = $request->validated();
        $runner->assertConsent($data);
        $runner->assertTokenScopes($request, $action, $data);
        return response()->json(['success' => true, 'data' => $runner->execute($request->user(), $action, $data)]);
    }
}
