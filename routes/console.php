<?php

use App\Services\AiDetectorService;
use App\Services\AiHumanizerService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:test', function (AiDetectorService $detector, AiHumanizerService $humanizer) {
    $this->line("API Key from config: [" . config('services.gemini.api_key') . "]");
    $this->line("API Key from env: [" . env('GEMINI_API_KEY') . "]");
    $this->info("=== Testing AI Detector Service ===");
    $sample = "In today's fast-paced digital world, artificial intelligence plays a pivotal role in revolutionizing modern industries. Furthermore, it is crucial to delve into how machine learning models enhance productivity across various domains. In conclusion, the technological tapestry continues to evolve seamlessly, serving as a testament to human innovation.";
    
    $result = $detector->detect($sample);
    $this->line("Overall Score: " . $result['overall_score'] . "%");
    $this->line("Verdict: " . $result['verdict']);
    $this->line("Risk summary: " . json_encode($result['risk_summary']));
    $this->line("Engine: " . $result['engine']);

    $this->info("\n=== Testing AI Humanizer Service ===");
    $human = $humanizer->humanize($sample, 'standard', true);
    $this->line("Original Score: " . $human['original_score'] . "%");
    $this->line("Humanized Score: " . $human['humanized_score'] . "%");
    $this->line("Humanized Text:\n" . $human['humanized_text']);
})->purpose('Test AI Detector and Humanizer Services');
