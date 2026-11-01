<?php

namespace Tests\Feature;

use App\Services\AiDetectorService;
use App\Services\AiHumanizerService;
use Tests\TestCase;

class AiToolTest extends TestCase
{
    public function test_homepage_is_accessible(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('WalterAI');
        $response->assertSee('Top 5 Detector Benchmark Comparison');
    }

    public function test_detect_requires_minimum_length(): void
    {
        $response = $this->postJson('/ai-tools/detect', [
            'text' => 'Too short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['text']);
    }

    public function test_detect_returns_overall_percentage_and_risk_breakdown(): void
    {
        $aiSample = "In today's fast-paced digital world, artificial intelligence plays a pivotal role. Furthermore, it is crucial to delve into how machine learning models revolutionize productivity across various domains.";

        $response = $this->postJson('/ai-tools/detect', [
            'text' => $aiSample,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'overall_score',
                'verdict',
                'word_count',
                'risk_summary' => ['high', 'medium', 'low'],
                'detectors_breakdown' => [
                    'walter_writes',
                    'proofademic',
                    'copyleaks',
                    'gptzero',
                    'originality',
                ],
                'sentences',
            ]
        ]);

        $score = $response->json('data.overall_score');
        $this->assertGreaterThanOrEqual(70, $score, 'Classic AI sample should receive high AI likelihood score.');
    }

    public function test_humanizer_preserves_academic_citations(): void
    {
        $academicSample = "According to empirical research by Miller et al. (2022, p. 45), neural networks play a pivotal role. Furthermore, it is crucial to delve into institutional metrics [1-3].";

        $response = $this->postJson('/ai-tools/humanize', [
            'text' => $academicSample,
            'mode' => 'academic',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $humanized = $response->json('data.humanized_text');

        // Verify APA and bracketed citations remain completely intact
        $this->assertStringContainsString('Miller et al. (2022, p. 45)', $humanized, 'APA citation must be preserved.');
        $this->assertStringContainsString('[1-3]', $humanized, 'Bracketed citation must be preserved.');
    }

    public function test_combo_1_step_execution(): void
    {
        $sample = "In today's digital era, artificial intelligence plays a pivotal role in modern industries. Furthermore, it is vital to delve into deep learning.";

        $response = $this->postJson('/ai-tools/combo', [
            'text' => $sample,
            'mode' => 'standard',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'original_score',
                'humanized_score',
                'score_reduction',
                'humanized_text',
                'original_detection',
                'verification',
            ]
        ]);

        $reduction = $response->json('data.score_reduction');
        $this->assertGreaterThanOrEqual(0, $reduction);
    }

    public function test_sentence_splitter_protects_abbreviations(): void
    {
        /** @var AiDetectorService $detector */
        $detector = app(AiDetectorService::class);

        $text = "Dr. Johnson et al. conducted the trial in 2021. The results were confirmed by Prof. Davis.";
        $sentences = $detector->splitSentences($text);

        $this->assertCount(2, $sentences, 'Abbreviations like Dr., et al., and Prof. should not split sentences.');
    }

    public function test_api_health_endpoint(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'ok');
    }
}

