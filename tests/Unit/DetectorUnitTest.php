<?php

namespace Tests\Unit;

use App\Services\TextAnalysisService;
use PHPUnit\Framework\TestCase;

class DetectorUnitTest extends TestCase
{
    public function test_english_readability_is_not_applied_to_bengali(): void
    {
        $quality = (new TextAnalysisService())->analyze('আমার ভাষায় আমি লিখতে চাই। এটি বাংলায় লেখা একটি সাধারণ বাক্য।', 'bn');
        $this->assertSame('bn', $quality['language']);
        $this->assertGreaterThan(0, $quality['word_count']);
        $this->assertArrayNotHasKey('overall_score', $quality);
    }
}
