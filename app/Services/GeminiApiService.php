<?php

namespace App\Services;

use App\Services\Exceptions\HumanizationException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class GeminiApiService
{
    private ?string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey ?? config('services.gemini.api_key');
        $this->model = $model ?? (string) config('services.gemini.model', 'gemini-3.8-flash');
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    public function isConfigured(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generateText(string $prompt, ?string $systemInstruction = null, float $temperature = 0.4): string
    {
        return $this->request($prompt, $systemInstruction, $temperature, false);
    }

    public function generateJson(string $prompt, ?string $systemInstruction = null, float $temperature = 0.2): array
    {
        $raw = $this->request($prompt, $systemInstruction, $temperature, true);
        try {
            $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HumanizationException('The writing provider returned an invalid response. Please try again.', 'generation');
        }
        if (!is_array($result)) {
            throw new HumanizationException('The writing provider returned an invalid response. Please try again.', 'generation');
        }
        return $result;
    }

    private function request(string $prompt, ?string $instruction, float $temperature, bool $json): string
    {
        if (!$this->isConfigured()) {
            throw new HumanizationException('The writing provider is not configured. Add its API key in server configuration to enable rewriting.', 'generation');
        }
        $payload = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => max(256, (int) config('services.gemini.max_output_tokens', 8192))]];
        // Gemini 3+ uses model-recommended sampling; omit legacy sampling controls.
        if (!preg_match('/gemini-(?:[3-9]|[1-9]\d)/i', $this->model)) {
            $payload['generationConfig']['temperature'] = max(0, min(1, $temperature));
        }
        if ($instruction) {
            $payload['systemInstruction'] = ['parts' => [['text' => $instruction]]];
        }
        if ($json) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }
        try {
            $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(10)->timeout(max(1, (int) config('services.gemini.timeout', 60)))
                ->post($this->baseUrl.'/models/'.rawurlencode($this->model).':generateContent', $payload);
        } catch (Throwable) {
            throw new HumanizationException('The writing provider could not be reached. Your original text is unchanged.', 'generation');
        }
        if (!$response->successful()) {
            throw new HumanizationException('The writing provider could not complete the request. Check server configuration and allowance, then try again.', 'generation');
        }
        $candidate = $response->json('candidates.0');
        if (!is_array($candidate) || ($candidate['finishReason'] ?? null) !== 'STOP') {
            throw new HumanizationException('The writing provider did not return a complete draft. Please try again with a shorter passage.', 'generation');
        }
        $parts = [];
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_string($part['text'] ?? null) && !($part['thought'] ?? false)) {
                $parts[] = $part['text'];
            }
        }
        $text = trim(implode('', $parts));
        if ($text === '') {
            throw new HumanizationException('The writing provider returned an empty draft. Your original text is unchanged.', 'generation');
        }
        return $text;
    }
}
