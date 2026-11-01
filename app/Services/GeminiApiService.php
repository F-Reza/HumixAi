<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiApiService
{
    protected ?string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $baseUrl = null)
    {
        try {
            $configKey = function_exists('config') ? config('services.gemini.api_key') : null;
            $configModel = function_exists('config') ? config('services.gemini.model') : null;
            $configUrl = function_exists('config') ? config('services.gemini.base_url') : null;
        } catch (\Throwable $e) {
            $configKey = null;
            $configModel = null;
            $configUrl = null;
        }

        $this->apiKey = $apiKey ?: ($configKey ?: env('GEMINI_API_KEY'));
        $this->model = $model ?: ($configModel ?: env('GEMINI_MODEL', 'gemini-1.5-flash'));
        $this->baseUrl = $baseUrl ?: ($configUrl ?: env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'));
    }

    /**
     * Check whether an API key has been provided.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Generate raw text using Gemini.
     */
    public function generateText(string $prompt, ?string $systemInstruction = null, float $temperature = 0.7): string
    {
        if (!$this->isConfigured()) {
            throw new Exception("Gemini API key is missing. Please set GEMINI_API_KEY in your .env file.");
        }

        $url = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $temperature,
            ]
        ];

        if ($systemInstruction) {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }

        $response = Http::timeout(45)->post($url, $payload);

        if (!$response->successful()) {
            Log::error('Gemini API Error: ' . $response->body());
            throw new Exception('Gemini API request failed: ' . ($response->json('error.message') ?? $response->status()));
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return trim($text);
    }

    /**
     * Generate structured JSON output using Gemini.
     */
    public function generateJson(string $prompt, ?string $systemInstruction = null, float $temperature = 0.2): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Gemini API key is missing. Please set GEMINI_API_KEY in your .env file.");
        }

        $url = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'responseMimeType' => 'application/json',
            ]
        ];

        if ($systemInstruction) {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }

        $response = Http::timeout(45)->post($url, $payload);

        if (!$response->successful()) {
            Log::error('Gemini API Error (JSON): ' . $response->body());
            throw new Exception('Gemini API request failed: ' . ($response->json('error.message') ?? $response->status()));
        }

        $rawText = $response->json('candidates.0.content.parts.0.text') ?? '{}';
        
        // Strip markdown fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Gemini JSON decode error: ' . json_last_error_msg() . ' Raw: ' . $rawText);
            throw new Exception('Failed to decode structured JSON from Gemini response.');
        }

        return $decoded;
    }
}
