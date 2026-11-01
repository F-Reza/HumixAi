<?php

namespace App\Http\Controllers;

use App\Services\AiDetectorService;
use App\Services\AiHumanizerService;
use App\Services\GeminiApiService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiToolController extends Controller
{
    protected AiDetectorService $detector;
    protected AiHumanizerService $humanizer;
    protected GeminiApiService $gemini;

    public function __construct(
        AiDetectorService $detector,
        AiHumanizerService $humanizer,
        GeminiApiService $gemini
    ) {
        $this->detector = $detector;
        $this->humanizer = $humanizer;
        $this->gemini = $gemini;
    }

    /**
     * Web UI for the WalterAI Detector & Humanizer.
     */
    public function index(): View
    {
        $isConfigured = $this->gemini->isConfigured();
        $model = config('services.gemini.model') ?: env('GEMINI_MODEL', 'gemini-1.5-flash');
        return view('ai-tools.index', compact('isConfigured', 'model'));
    }

    /**
     * Detect AI content percentage and sentence risk breakdown.
     */
    public function detect(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|min:15',
        ], [
            'text.min' => 'Please provide at least 15 characters to analyze.',
            'text.required' => 'Input text is required.',
        ]);

        try {
            $result = $this->detector->detect($request->input('text'));

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Humanize AI-generated text and verify new score in closed loop.
     */
    public function humanize(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|min:15',
            'mode' => 'nullable|string|in:standard,academic,casual,bypass',
        ]);

        $mode = $request->input('mode', 'standard');

        try {
            $result = $this->humanizer->humanize($request->input('text'), $mode, true);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Combined 1-pass detect and humanize (Walter Writes combo).
     */
    public function combo(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|min:15',
            'mode' => 'nullable|string|in:standard,academic,casual,bypass',
        ]);

        $mode = $request->input('mode', 'standard');

        try {
            $result = $this->humanizer->humanize($request->input('text'), $mode, true);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update Gemini API key in .env file directly from UI.
     */
    public function updateKey(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string|min:10',
            'model' => 'nullable|string',
        ]);

        try {
            $envPath = base_path('.env');
            if (!file_exists($envPath)) {
                copy(base_path('.env.example'), $envPath);
            }

            $apiKey = trim($request->input('api_key'));
            $model = trim($request->input('model', 'gemini-1.5-flash'));

            $envContent = file_get_contents($envPath);

            // Update GEMINI_API_KEY
            if (preg_match('/^GEMINI_API_KEY=.*$/m', $envContent)) {
                $envContent = preg_replace('/^GEMINI_API_KEY=.*$/m', "GEMINI_API_KEY={$apiKey}", $envContent);
            } else {
                $envContent .= "\nGEMINI_API_KEY={$apiKey}";
            }

            // Update GEMINI_MODEL
            if (preg_match('/^GEMINI_MODEL=.*$/m', $envContent)) {
                $envContent = preg_replace('/^GEMINI_MODEL=.*$/m', "GEMINI_MODEL={$model}", $envContent);
            } else {
                $envContent .= "\nGEMINI_MODEL={$model}";
            }

            file_put_contents($envPath, $envContent);

            return response()->json([
                'success' => true,
                'message' => 'Gemini API key updated successfully!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update .env: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check status of Gemini configuration.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'gemini_configured' => $this->gemini->isConfigured(),
            'model' => config('services.gemini.model') ?: env('GEMINI_MODEL', 'gemini-1.5-flash'),
        ]);
    }
}
