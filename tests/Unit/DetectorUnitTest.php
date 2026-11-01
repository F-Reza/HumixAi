<?php

namespace Tests\Unit;

use App\Services\AiDetectorService;
use App\Services\AiHumanizerService;
use App\Services\GeminiApiService;
use PHPUnit\Framework\TestCase;

class DetectorUnitTest extends TestCase
{
    protected AiDetectorService $detector;
    protected AiHumanizerService $humanizer;

    protected function setUp(): void
    {
        parent::setUp();
        $gemini = new GeminiApiService();
        $this->detector = new AiDetectorService($gemini);
        $this->humanizer = new AiHumanizerService($gemini, $this->detector);
    }

    public function test_empty_string_returns_zero_score(): void
    {
        $result = $this->detector->detect('');
        $this->assertEquals(0, $result['overall_score']);
        $this->assertEquals('Empty text', $result['verdict']);
    }

    public function test_sentence_level_suggestion_generation(): void
    {
        $sentence = "In today's fast-paced digital world, it is crucial to delve into data.";
        $suggestion = $this->detector->generateHeuristicSentenceSuggestion($sentence);

        $this->assertStringNotContainsString("In today's fast-paced digital world", $suggestion);
        $this->assertStringContainsString("explore", $suggestion);
    }

    public function test_heuristic_rewrite_removes_cliches(): void
    {
        $text = "In conclusion, the technological tapestry will evolve seamlessly, serving as a testament to innovation.";
        $rewritten = $this->humanizer->advancedHeuristicRewrite($text, 'standard');

        $this->assertStringNotContainsString('technological tapestry', $rewritten);
        $this->assertStringNotContainsString('testament', $rewritten);
    }
}

