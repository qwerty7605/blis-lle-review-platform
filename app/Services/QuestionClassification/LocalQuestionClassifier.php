<?php

namespace App\Services\QuestionClassification;

use App\Models\QuestionBankQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Free, local, deterministic stand-in for the old OpenAI-based item
 * classifier. Every method is a pure function of a QuestionBankQuestion (no
 * network calls, no DB writes) -- QuestionMappingService is the only caller
 * that persists anything.
 *
 * Four independent heuristics:
 *  - classifyTopic(): keyword/phrase scoring against TopicKeywordDictionary.
 *  - classifyCognitiveLevel(): Bloom's-style verb/stem cue matching.
 *  - classifyDifficulty(): a structural/linguistic proxy (negation, stem
 *    length, distractor similarity, scenario framing) -- NOT a claim of
 *    matching real empirical item difficulty. A natural v2 once
 *    exam_attempt_answers accumulate enough attempts per question: derive
 *    difficulty from the real percent-correct (p-value) instead of guessing
 *    from text. Out of scope for now.
 *  - classifySubjectForTexts(): the same keyword scoring, aggregated across
 *    a whole freshly-parsed question set to guess which of the 6 curated
 *    subjects it belongs to (used to pre-fill "Subject Category" at upload).
 *
 * Every classify* method returns a confidence score (0-1) so
 * QuestionMappingService can force a low-confidence guess into
 * pending_review regardless of the bank's auto/review mode.
 */
class LocalQuestionClassifier
{
    /** Below this topic confidence, the guess is forced into pending_review. */
    public const TOPIC_CONFIDENCE_THRESHOLD = 0.45;

    /** Below this cognitive-level confidence, the guess is forced into pending_review. */
    public const COGNITIVE_CONFIDENCE_THRESHOLD = 0.5;

    /** Below this difficulty confidence, the guess is forced into pending_review. */
    public const DIFFICULTY_CONFIDENCE_THRESHOLD = 0.4;

    /** Roughly "one solid canonical phrase match" -- used to normalize absolute match strength into 0-1. */
    private const STRONG_TOPIC_MATCH = 4.0;

    /**
     * Bloom's-style stem cues, checked in this precedence order because a
     * rarer/more specific cue co-occurring with a generic one is stronger
     * evidence (a genuinely recall-only stem is unlikely to also contain
     * scenario language).
     *
     * @var array<string, array<int, string>>
     */
    private const COGNITIVE_CUES = [
        'analysis' => [
            'compare and contrast',
            'which of the following best explains why',
            'which best distinguishes',
            'most appropriate course of action',
            'best justification',
            'evaluate the',
            'in contrast to',
        ],
        'application' => [
            'in this scenario',
            'a librarian is faced with',
            'given the following situation',
            'a patron approaches',
            'a patron asks',
            'what should the librarian do',
            'how would you apply',
            'in a case where',
        ],
        'comprehension' => [
            'why does',
            'why is',
            'what is the purpose of',
            'explain',
            'what is meant by',
            'describes the',
        ],
        'recall' => [
            'what is the definition of',
            'which of the following is',
            'what is the term for',
            'identify the',
            'according to',
            'is defined as',
            'stands for',
        ],
    ];

    /** Scenario/multi-step framing cues -- a subset of the "application" cognitive cues, reused as a difficulty signal. */
    private const SCENARIO_CUES = [
        'in this scenario',
        'a librarian is faced with',
        'a patron approaches',
        'a patron asks',
        'given the following situation',
        'the library recently',
    ];

    /** Negation/exception cues -- these make a multiple-choice item harder because every option must be evaluated and the usual judgment inverted. */
    private const NEGATION_CUES = [
        'except',
        'not true',
        'is false',
        'least likely',
        'which is not',
        'are not',
    ];

    public function classifyTopic(QuestionBankQuestion $question, string $subject): TopicClassification
    {
        $topics = TopicKeywordDictionary::KEYWORDS[$subject] ?? [];

        if ($topics === []) {
            return new TopicClassification(null, null, 0.0);
        }

        $searchText = $this->buildTopicSearchText($question);
        $topicResults = $this->scoreTopicsForSubject($searchText, $subject);

        $scores = array_map(fn (array $result): float => $result['score'], $topicResults);

        // Stable sort (PHP 8+ sorts are stable) preserves CURATED_TOPICS'
        // declaration order among ties, so results are deterministic.
        arsort($scores);
        $orderedTopicNames = array_keys($scores);
        $bestTopic = $orderedTopicNames[0];
        $bestScore = $scores[$bestTopic];
        $secondScore = $scores[$orderedTopicNames[1] ?? $bestTopic] ?? 0.0;

        if ($bestScore <= 0.0) {
            // Nothing matched any cue at all. Try a plain topic-name
            // substring as a last-resort guess (never leave it null), but
            // confidence stays 0 -- this is a guess, not evidence.
            foreach ($orderedTopicNames as $topicName) {
                if ($this->containsPhrase($searchText, Str::lower($topicName))) {
                    $bestTopic = $topicName;
                    break;
                }
            }

            $bestSubtopic = $topicResults[$bestTopic]['subtopic'] ?? array_key_first($topics[$bestTopic]);

            return new TopicClassification($bestTopic, $bestSubtopic, 0.0);
        }

        $bestSubtopic = $topicResults[$bestTopic]['subtopic'] ?? array_key_first($topics[$bestTopic]);
        $confidence = $this->deriveTopicConfidence($bestScore, $secondScore);

        return new TopicClassification($bestTopic, $bestSubtopic, $confidence);
    }

    /**
     * Guesses which of the 6 curated subjects a whole freshly-parsed
     * question set belongs to, by averaging each question's best-topic
     * score under every candidate subject. Used so the Library upload form
     * can pre-fill "Subject Category" instead of a teacher picking it
     * manually every time. $searchTexts is one already-normalized search
     * string per question -- see buildSearchTextFromParts(), which
     * QuestionMappingService::suggestSubjectForParsedQuestions() calls for
     * a DOCX-parsed preview array (no QuestionBankQuestion row exists yet
     * at that point).
     *
     * An empty `value` means no subject scored above zero anywhere --
     * genuinely no keyword signal, not just a weak guess.
     *
     * @param  array<int, string>  $searchTexts
     */
    public function classifySubjectForTexts(array $searchTexts): ScalarClassification
    {
        if ($searchTexts === []) {
            return new ScalarClassification('', 0.0);
        }

        $averages = [];

        foreach (array_keys(TopicKeywordDictionary::KEYWORDS) as $subject) {
            $total = 0.0;

            foreach ($searchTexts as $searchText) {
                $topicResults = $this->scoreTopicsForSubject($searchText, $subject);
                $total += $topicResults === [] ? 0.0 : max(array_map(fn (array $result): float => $result['score'], $topicResults));
            }

            $averages[$subject] = $total / count($searchTexts);
        }

        arsort($averages);
        $orderedSubjects = array_keys($averages);
        $bestSubject = $orderedSubjects[0];
        $bestScore = $averages[$bestSubject];

        if ($bestScore <= 0.0) {
            return new ScalarClassification('', 0.0);
        }

        // deriveTopicConfidence() (used for a single question) compares an
        // absolute score against STRONG_TOPIC_MATCH, which works because one
        // question's score is an undiluted signal. A whole set's *average*
        // is a different animal: most real quizzes only have a handful of
        // items that hit any given subject's keyword cues at all, so the
        // correct subject's average gets diluted by every item that scores
        // 0 -- it can legitimately be well below STRONG_TOPIC_MATCH even
        // when it's the obvious, landslide winner. Confidence here is
        // instead the winner's *share* of the total score across all 6
        // subjects: scale-invariant, and naturally low when two subjects
        // are genuinely close and high when one dominates, regardless of
        // how sparse the keyword hits were overall.
        $totalAcrossSubjects = array_sum($averages);
        $confidence = $totalAcrossSubjects > 0.0 ? min(1.0, $bestScore / $totalAcrossSubjects) : 0.0;

        return new ScalarClassification($bestSubject, $confidence);
    }

    /**
     * Scores every topic under one subject against one normalized search
     * text, picking each topic's best-matching subtopic along the way.
     * Shared primitive behind both classifyTopic() (single question, one
     * known subject) and classifySubjectForTexts() (many questions, all 6
     * candidate subjects).
     *
     * @return array<string, array{score: float, subtopic: ?string}>
     */
    private function scoreTopicsForSubject(string $normalizedSearchText, string $subject): array
    {
        $topics = TopicKeywordDictionary::KEYWORDS[$subject] ?? [];
        $results = [];

        foreach ($topics as $topicName => $subtopics) {
            $bestSubtopicScore = 0.0;
            $bestSubtopicName = null;

            foreach ($subtopics as $subtopicName => $cues) {
                $subtopicScore = 0.0;

                foreach ($cues as [$phrase, $weight]) {
                    if ($this->containsPhrase($normalizedSearchText, $phrase)) {
                        $subtopicScore += $weight;
                    }
                }

                if ($subtopicScore > $bestSubtopicScore) {
                    $bestSubtopicScore = $subtopicScore;
                    $bestSubtopicName = $subtopicName;
                }
            }

            $results[$topicName] = ['score' => $bestSubtopicScore, 'subtopic' => $bestSubtopicName];
        }

        return $results;
    }

    public function classifyCognitiveLevel(QuestionBankQuestion $question): ScalarClassification
    {
        $stem = $this->normalizeSearchText((string) $question->question_text);

        foreach (self::COGNITIVE_CUES as $level => $cues) {
            foreach ($cues as $cue) {
                if ($this->containsPhrase($stem, $cue)) {
                    return new ScalarClassification($level, 1.0);
                }
            }
        }

        // No cue fired at all -- default to the lowest-risk tier (also the
        // easiest end of COGNITIVE_LEVELS), flagged low-confidence so it
        // lands in pending_review rather than silently auto-confirming.
        return new ScalarClassification(QuestionBankQuestion::COGNITIVE_LEVELS[0], 0.3);
    }

    public function classifyDifficulty(QuestionBankQuestion $question): ScalarClassification
    {
        $stemRaw = (string) $question->question_text;
        $stem = $this->normalizeSearchText($stemRaw);

        $score = 0;

        if ($this->matchesAny($stem, self::NEGATION_CUES) || preg_match('/\bnot\b/', $stem) === 1) {
            $score += 2;
        }

        $wordCount = str_word_count($stemRaw);
        $score += match (true) {
            $wordCount > 25 => 2,
            $wordCount >= 12 => 1,
            default => 0,
        };

        $score += $this->distractorSimilarityScore($question);

        if ($this->matchesAny($stem, self::SCENARIO_CUES)) {
            $score += 1;
        }

        $difficulty = match (true) {
            $score >= 5 => QuestionBankQuestion::DIFFICULTY_HARD,
            $score >= 3 => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            default => QuestionBankQuestion::DIFFICULTY_EASY,
        };

        return new ScalarClassification($difficulty, $this->deriveDifficultyConfidence($score));
    }

    /**
     * Confidence rewards both a wide margin over the runner-up topic
     * (semantic specificity) and an absolute floor of match strength, so a
     * question that weakly matches two topics equally doesn't get inflated
     * confidence just because both scores happen to be low.
     */
    private function deriveTopicConfidence(float $topScore, float $secondScore): float
    {
        $marginRatio = max(0.0, min(1.0, ($topScore - $secondScore) / $topScore));
        $strengthRatio = min(1.0, $topScore / self::STRONG_TOPIC_MATCH);

        return (0.6 * $marginRatio) + (0.4 * $strengthRatio);
    }

    /** A score sitting right on a bucket boundary is low-confidence; a score deep inside a bucket is high-confidence. */
    private function deriveDifficultyConfidence(int $score): float
    {
        $distanceToNearestBoundary = min(abs($score - 2.5), abs($score - 4.5));

        return max(0.0, min(1.0, $distanceToNearestBoundary / 1.5));
    }

    private function distractorSimilarityScore(QuestionBankQuestion $question): int
    {
        $options = $question->relationLoaded('options') || $question->exists
            ? $question->options
            : new Collection();

        $correct = $options->first(fn ($option) => (bool) $option->is_correct);

        if (!$correct) {
            return 0;
        }

        $correctText = Str::lower(trim((string) $correct->option_text));

        if ($correctText === '') {
            return 0;
        }

        $distractorTexts = $options
            ->reject(fn ($option) => $option === $correct)
            ->map(fn ($option) => Str::lower(trim((string) $option->option_text)))
            ->filter(fn (string $text) => $text !== '')
            ->values();

        if ($distractorTexts->isEmpty()) {
            return 0;
        }

        $averageSimilarity = $distractorTexts
            ->map(fn (string $text) => $this->textSimilarity($correctText, $text))
            ->sortDesc()
            ->take(2)
            ->avg();

        return match (true) {
            $averageSimilarity >= 0.5 => 2,
            $averageSimilarity >= 0.25 => 1,
            default => 0,
        };
    }

    /** Levenshtein-based similarity for short strings (where it's cheap and meaningful), Jaccard word-overlap for longer ones. */
    private function textSimilarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }

        if (max(strlen($a), strlen($b)) <= 40) {
            $distance = levenshtein(substr($a, 0, 255), substr($b, 0, 255));
            $maxLength = max(strlen($a), strlen($b), 1);

            return 1.0 - ($distance / $maxLength);
        }

        $wordsA = array_unique(preg_split('/\s+/', $a, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $wordsB = array_unique(preg_split('/\s+/', $b, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $union = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union > 0 ? count(array_intersect($wordsA, $wordsB)) / $union : 0.0;
    }

    private function buildTopicSearchText(QuestionBankQuestion $question): string
    {
        $parts = [(string) $question->question_text, (string) $question->answer_text];

        $options = $question->relationLoaded('options') || $question->exists
            ? $question->options
            : new Collection();

        foreach ($options as $option) {
            $parts[] = (string) $option->option_text;
        }

        return $this->buildSearchTextFromParts($parts);
    }

    /**
     * Builds one normalized search string from raw text parts (question
     * stem + option texts + answer text). Public so
     * QuestionMappingService::suggestSubjectForParsedQuestions() can build
     * the same kind of search text from a DOCX-parsed preview array, where
     * no QuestionBankQuestion row exists yet to hand to buildTopicSearchText().
     *
     * @param  array<int, string>  $parts
     */
    public function buildSearchTextFromParts(array $parts): string
    {
        return $this->normalizeSearchText(implode(' ', $parts));
    }

    /** Lowercases, strips punctuation (keeping intra-word - and /), collapses whitespace, and pads with a boundary space on both ends. */
    private function normalizeSearchText(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^a-z0-9\-\/\s]/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return ' ' . trim($text) . ' ';
    }

    /** $paddedText must already be normalized via normalizeSearchText(). Space-padding makes this whole-word/whole-phrase safe without needing a separate regex path for short cues. */
    private function containsPhrase(string $paddedText, string $phrase): bool
    {
        $needle = ' ' . trim(Str::lower($phrase)) . ' ';

        return str_contains($paddedText, $needle);
    }

    /**
     * @param  array<int, string>  $phrases
     */
    private function matchesAny(string $paddedText, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($this->containsPhrase($paddedText, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
