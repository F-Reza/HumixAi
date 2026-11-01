<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class AiHumanizerService
{
    protected GeminiApiService $gemini;
    protected AiDetectorService $detector;

    public function __construct(GeminiApiService $gemini, AiDetectorService $detector)
    {
        $this->gemini = $gemini;
        $this->detector = $detector;
    }

    /**
     * Humanize AI-generated text and verify new score in a closed loop.
     *
     * @param string $text The text to humanize
     * @param string $mode 'standard' | 'academic' | 'casual' | 'bypass'
     * @param bool $verify Whether to run the detector on the rewritten text
     */
    public function humanize(string $text, string $mode = 'standard', bool $verify = true): array
    {
        $text = trim($text);
        if (empty($text)) {
            throw new Exception("Input text cannot be empty.");
        }

        // 1. Initial detection of original text
        $originalDetection = $this->detector->detect($text);
        $originalScore = $originalDetection['overall_score'];

        // 2. Protect citations (APA / MLA / IEEE) so they never get corrupted
        [$preppedText, $citationsMap] = $this->extractAndProtectCitations($text);

        // 3. Select mode instructions
        $modePrompt = match ($mode) {
            'academic' => <<<MODE
Mode: ACADEMIC & RESEARCH (Proofademic / Turnitin Safe)
- Preserve ALL citation tokens (e.g. __CITATION_0__), statistics, formulas, and domain-specific terminology exactly as written.
- Maintain a formal, authoritative scholarly tone.
- Eradicate formulaic throat-clearing thesis phrases (e.g. 'In conclusion, this essay has demonstrated', 'It is worth noting that').
- Employ varied syntactic clauses, nuanced transitions, and active academic prose.
MODE,
            'casual' => <<<MODE
Mode: CASUAL & CONVERSATIONAL (Blogger / Social / Personal)
- Use lively, punchy, conversational flow.
- Use natural contractions (it's, don't, we've, let's), rhetorical rhythm, and relatable metaphors.
- Sound like a passionate, knowledgeable human author having a direct discussion with the reader.
MODE,
            'bypass' => <<<MODE
Mode: AGGRESSIVE STEALTH BYPASS (Maximum Perplexity & Burstiness)
- Drastically vary sentence length: mix very short 3-6 word punchy sentences with rich compound sentences.
- Break predictable AI cadence. Introduce asymmetrical rhythm and active, concrete verbs.
- Strip all repetitive AI transitions ('Furthermore', 'Moreover', 'Consequently').
MODE,
            default => <<<MODE
Mode: STANDARD / WALTER WRITES (Best Overall Combo for SEO, Articles, Content)
- Clear, natural, engaging human writing that keeps the original structure and paragraphs intact.
- Eliminate overused AI tropes and clichés without changing the core meaning or facts.
- Smooth out robotic monotony while maintaining professional polish.
MODE,
        };

        $systemInstruction = <<<PROMPT
You are an elite prose editor and AI text humanizer modeled after Walter Writes, Proofademic, and Copyleaks cleanup engines.
Your task is to rewrite the user's text into genuine, authentic human prose that easily passes AI detectors (GPTZero, Copyleaks, Turnitin, Originality.ai).

{$modePrompt}

CORE RULES FOR SUCCESS:
1. MAXIMIZE BURSTINESS: Humans vary sentence lengths organically. Never output consecutive sentences of similar length. Mix punchy short statements with detailed clauses.
2. BANISH AI CLICHÉS: NEVER use 'delve', 'testament', 'pivotal', 'tapestry', 'seamlessly', 'beacon', 'crucial', 'vital', 'ever-evolving', 'plethora', 'unleash', 'leverage', 'holistic'.
3. NO FORMULAIC OPENINGS: Do not start paragraphs with 'In today's fast-paced digital world', 'In conclusion', 'Furthermore', 'Moreover'.
4. PRESERVE FACTS & CITATIONS: Keep all facts, names, numbers, and exact placeholder tokens (e.g. __CITATION_X__) untouched.
5. NO WRAPPERS: Return ONLY the rewritten text. Do not prepend commentary like 'Here is the humanized version:' or wrap text in quotation marks.
PROMPT;

        $prompt = "Rewrite the following text into authentic human writing according to the instructions:\n\n\"\"\"\n{$preppedText}\n\"\"\"";

        if (!$this->gemini->isConfigured()) {
            // High-quality Heuristic Fallback Rewrite
            $rewrittenText = $this->advancedHeuristicRewrite($preppedText, $mode);
        } else {
            try {
                $rewrittenText = $this->gemini->generateText($prompt, $systemInstruction, 0.72);
            } catch (Exception $e) {
                Log::warning("Gemini generation failed ({$e->getMessage()}), using advanced heuristic rewrite.");
                $rewrittenText = $this->advancedHeuristicRewrite($preppedText, $mode);
            }
        }

        // 4. Restore preserved citations
        $humanizedText = $this->restoreCitations($rewrittenText, $citationsMap);

        // 5. Closed Loop Verification: re-scan the rewritten text
        $verification = null;
        $newScore = 0;
        if ($verify) {
            $verification = $this->detector->detect($humanizedText);
            $newScore = $verification['overall_score'];
        }

        $reduction = max(0, $originalScore - $newScore);

        return [
            'original_text' => $text,
            'humanized_text' => $humanizedText,
            'mode' => $mode,
            'original_score' => $originalScore,
            'humanized_score' => $newScore,
            'score_reduction' => $reduction,
            'original_detection' => $originalDetection,
            'verification' => $verification,
            'engine' => $this->gemini->isConfigured() ? 'gemini-humanizer' : 'heuristic-humanizer',
        ];
    }

    /**
     * Extracts and tokenizes citations to protect APA/MLA/IEEE citations from degradation.
     */
    protected function extractAndProtectCitations(string $text): array
    {
        $citationsMap = [];
        $counter = 0;

        // Parenthetical citations: (Smith, 2020), (Miller et al., 2021, p. 34)
        $parentheticalRegex = '/\((?:[A-Z][a-zA-Z\s]+(?:et al\.)?,?\s*\d{4}[a-z]?(?:,\s*p{1,2}\.\s*\d+(?:-\d+)?)?)\)/';
        $text = preg_replace_callback($parentheticalRegex, function ($matches) use (&$citationsMap, &$counter) {
            $token = "__CITATION_{$counter}__";
            $citationsMap[$token] = $matches[0];
            $counter++;
            return $token;
        }, $text);

        // Bracketed IEEE citations: [1], [1-3], [4, 5]
        $bracketRegex = '/\[\d+(?:[-,]\s*\d+)*\]/';
        $text = preg_replace_callback($bracketRegex, function ($matches) use (&$citationsMap, &$counter) {
            $token = "__CITATION_{$counter}__";
            $citationsMap[$token] = $matches[0];
            $counter++;
            return $token;
        }, $text);

        return [$text, $citationsMap];
    }

    /**
     * Restores protected citations into the rewritten text.
     */
    protected function restoreCitations(string $text, array $citationsMap): string
    {
        foreach ($citationsMap as $token => $originalCitation) {
            $text = str_replace($token, $originalCitation, $text);
        }
        return $text;
    }

    /**
     * Advanced Rule-based Heuristic Humanizer (Offline Engine).
     * Eliminates clichés, injects burstiness, and adapts syntax to chosen mode.
     */
    public function advancedHeuristicRewrite(string $text, string $mode = 'standard'): string
    {
        // 1. Cliché and robotic pattern replacements
        $replacements = [
            // Throats & openings
            '/In today\'s fast-paced digital world,\s*/i' => 'Today, ',
            '/In today\'s fast-paced world,\s*/i' => 'Nowadays, ',
            '/In today\'s digital world,\s*/i' => 'In current times, ',
            '/In today\'s digital age,\s*/i' => 'Right now, ',
            '/In the realm of\s+/i' => 'In ',
            '/In the modern era,\s*/i' => 'Today, ',
            '/First and foremost,\s*/i' => 'First, ',
            '/It is important to remember that\s*/i' => 'Remember, ',
            '/It is worth noting that\s*/i' => 'Notably, ',
            '/At the end of the day,\s*/i' => 'Ultimately, ',
            
            // Transitions
            '/Furthermore,\s*/i' => ($mode === 'casual' ? 'Also, ' : 'Beyond this, '),
            '/Moreover,\s*/i' => ($mode === 'casual' ? 'Plus, ' : 'In addition, '),
            '/Additionally,\s*/i' => 'Also, ',
            '/In conclusion,\s*/i' => ($mode === 'academic' ? 'To conclude, ' : ($mode === 'casual' ? 'Bottom line: ' : 'Overall, ')),
            '/In summary,\s*/i' => 'To sum up, ',
            '/Consequently,\s*/i' => 'As a result, ',

            // Heavy LLM cliché vocabulary
            '/\bplays a pivotal role in\b/i' => 'is essential to',
            '/\bplays a crucial role in\b/i' => 'shapes',
            '/\bplays an indispensable role\b/i' => 'is fundamental',
            '/\bit is crucial to\b/i' => 'it is key to',
            '/\bcrucial to\b/i' => 'key to',
            '/\bcrucial\b/i' => 'key',
            '/\bdelve into\b/i' => 'explore',
            '/\bdelves into\b/i' => 'explores',
            '/\bdelving into\b/i' => 'examining',
            '/\bserves as a testament to\b/i' => 'shows',
            '/\ba testament to\b/i' => 'evidence of',
            '/\btechnological tapestry\b/i' => 'technology landscape',
            '/\btapestry of\b/i' => 'interconnected mix of',
            '/\btapestry\b/i' => 'fabric',
            '/\bevolve seamlessly\b/i' => 'adapt smoothly',
            '/\bseamlessly integrate\b/i' => 'fit naturally',
            '/\bseamlessly\b/i' => 'smoothly',
            '/\bbeacon of\b/i' => 'model for',
            '/\bparamount importance\b/i' => 'high priority',
            '/\bparamount\b/i' => 'essential',
            '/\bplethora of\b/i' => 'variety of',
            '/\bunleash\b/i' => 'unlock',
            '/\bleverage\b/i' => 'use',
            '/\bleveraging\b/i' => 'using',
            '/\bever-evolving\b/i' => 'changing',
            '/\bholistic approach\b/i' => 'comprehensive strategy',
            '/\bholistic\b/i' => 'complete',
            '/\bmultifaceted\b/i' => 'diverse',
            '/\bdeep dive\b/i' => 'close look',
            '/\bgame changer\b/i' => 'major turning point',
            '/\btransformative power\b/i' => 'major impact',
            '/\bcornerstone of\b/i' => 'foundation for',
            '/\bvarious domains\b/i' => 'different fields',
            '/\bacross various domains\b/i' => 'in many fields',
        ];

        $rewritten = $text;
        foreach ($replacements as $pattern => $sub) {
            $rewritten = preg_replace($pattern, $sub, $rewritten);
        }

        // 2. Sentence-level Burstiness & Cadence restructuring
        $sentenceList = $this->detector->splitSentences($rewritten);
        $restructured = [];

        foreach ($sentenceList as $idx => $item) {
            $s = trim($item['sentence']);
            $wordCount = str_word_count($s);

            // If sentence is excessively long and contains "and" or "while", inject burstiness by splitting
            if ($wordCount >= 22 && preg_match('/^(.*?),\s*(and|while|which)\s+(.*)$/i', $s, $match)) {
                $clause1 = trim($match[1]);
                $connector = strtolower($match[2]);
                $clause2 = ucfirst(trim($match[3]));

                if ($connector === 'and') {
                    $restructured[] = $clause1 . '.';
                    $restructured[] = 'Furthermore, ' . lcfirst($clause2);
                } elseif ($connector === 'while') {
                    $restructured[] = $clause1 . '.';
                    $restructured[] = 'Meanwhile, ' . lcfirst($clause2);
                } else {
                    $restructured[] = $clause1 . '.';
                    $restructured[] = $clause2;
                }
            } else {
                $restructured[] = $s;
            }
        }

        $assembled = implode(' ', $restructured);

        // 3. Mode-specific adjustments
        if ($mode === 'casual') {
            // Apply contractions
            $contractions = [
                '/\bdo not\b/i' => "don't",
                '/\bcannot\b/i' => "can't",
                '/\bit is\b/i' => "it's",
                '/\bthat is\b/i' => "that's",
                '/\bthere is\b/i' => "there's",
                '/\bwe have\b/i' => "we've",
                '/\bthey are\b/i' => "they're",
            ];
            foreach ($contractions as $cPat => $cRep) {
                $assembled = preg_replace($cPat, $cRep, $assembled);
            }
        }

        return trim($assembled);
    }
}
