<?php

namespace Tests\Unit;

use App\Services\TextAnalysisService;
use Tests\TestCase;

class TextAnalysisTest extends TestCase
{
    public function test_bengali_counts_and_sentence_offsets_are_unicode_character_offsets(): void
    {
        $text = "  আমি বাংলা লিখি।\nতুমি কি পড়ো?  ";
        $analysis = new TextAnalysisService();
        $result = $analysis->analyze($text);
        $this->assertSame('bn', $result['language']);
        $this->assertSame(6, $result['word_count']);
        $this->assertSame(2, $result['sentence_count']);
        $this->assertSame(mb_strlen($text), $result['character_count']);
        foreach ($analysis->sentences($text) as $sentence) {
            $this->assertSame($sentence['text'], mb_substr($text, $sentence['start'], $sentence['end'] - $sentence['start']));
            $this->assertNull($sentence['score']);
        }
    }

    public function test_abbreviations_decimals_and_repeated_sentences_keep_original_spans(): void
    {
        $text = 'Dr. Smith measured 3.5 cm. Same sentence! Same sentence!';
        $sentences = (new TextAnalysisService())->sentences($text);
        $this->assertCount(3, $sentences);
        $this->assertSame('Dr. Smith measured 3.5 cm.', $sentences[0]['text']);
        $this->assertGreaterThan($sentences[1]['start'], $sentences[2]['start']);
        foreach ($sentences as $sentence) {
            $this->assertSame($sentence['text'], mb_substr($text, $sentence['start'], $sentence['end'] - $sentence['start']));
        }
    }

    public function test_quality_metrics_do_not_claim_authorship_or_english_readability_for_bengali(): void
    {
        $result = (new TextAnalysisService())->analyze('গবেষণা গবেষণা গবেষণা গবেষণা।');
        $this->assertSame(4, $result['word_count']);
        $this->assertSame([['word' => 'গবেষণা', 'count' => 4]], $result['repeated_words']);
        $this->assertArrayNotHasKey('overall_score', $result);
        $this->assertArrayNotHasKey('flesch_score', $result);
        $empty = (new TextAnalysisService())->analyze('');
        $this->assertSame(0, $empty['word_count']);
        $this->assertSame(0, $empty['sentence_count']);
        $this->assertNull($empty['vocabulary_diversity']);
    }
}
