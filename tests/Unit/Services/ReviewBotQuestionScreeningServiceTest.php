<?php

namespace Tests\Unit\Services;

use App\Models\QuestionBankQuestion;
use App\Services\ReviewBotQuestionScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * ReviewBotQuestionScreeningService checks each generated Review Bot question
 * before a teacher sees it: structural defects are flagged, and near
 * duplicates are found by comparing TF-IDF weighted, hashed word vectors by
 * cosine similarity (rejected at 0.90 or more, flagged at 0.80 or more).
 */
class ReviewBotQuestionScreeningServiceTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private ReviewBotQuestionScreeningService $screening;

    protected function setUp(): void
    {
        parent::setUp();
        $this->screening = new ReviewBotQuestionScreeningService();
    }

    private function item(array $options, array $overrides = []): array
    {
        return [
            'prompt' => 'Which tool answers a ready reference question?',
            'type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'options' => $options,
            'explanation' => 'Almanacs hold quick facts.',
            ...$overrides,
        ];
    }

    private function choices(array $texts, array $correct = [0]): array
    {
        return array_map(fn ($text, $index) => ['text' => $text, 'is_correct' => in_array($index, $correct, true)], $texts, array_keys($texts));
    }

    public function test_a_sound_question_has_no_flags(): void
    {
        $this->assertSame([], $this->screening->mechanicalFlags($this->item($this->choices(['Almanac', 'Thesaurus', 'Atlas', 'Gazetteer']))));
    }

    public function test_each_structural_defect_is_flagged(): void
    {
        $flags = fn (array $item) => $this->screening->mechanicalFlags($item);

        $this->assertContains(ReviewBotQuestionScreeningService::FLAG_NO_CORRECT_OPTION, $flags($this->item($this->choices(['A1', 'B1', 'C1', 'D1'], []))));
        $this->assertContains(ReviewBotQuestionScreeningService::FLAG_MULTIPLE_CORRECT_OPTIONS, $flags($this->item($this->choices(['A1', 'B1', 'C1', 'D1'], [0, 1]))));
        $this->assertContains(ReviewBotQuestionScreeningService::FLAG_OPTION_COUNT, $flags($this->item($this->choices(['A1', 'B1', 'C1']))));
        $this->assertContains(ReviewBotQuestionScreeningService::FLAG_DUPLICATE_OPTIONS, $flags($this->item($this->choices(['Atlas', 'atlas ', 'Map', 'Globe']))));
        $this->assertContains(ReviewBotQuestionScreeningService::FLAG_ALL_OF_THE_ABOVE, $flags($this->item($this->choices(['Atlas', 'Map', 'Globe', 'All of the above']))));
        $this->assertContains(ReviewBotQuestionScreeningService::FLAG_MISSING_EXPLANATION, $flags($this->item($this->choices(['A1', 'B1', 'C1', 'D1']), ['explanation' => '  '])));
        $this->assertContains(ReviewBotQuestionScreeningService::FLAG_ANSWER_LENGTH_GIVEAWAY, $flags($this->item($this->choices([
            'A comprehensive annual reference of statistics and facts', 'Atlas', 'Map', 'Globe',
        ]))));
    }

    public function test_a_true_or_false_question_expects_two_choices(): void
    {
        $item = $this->item($this->choices(['True', 'False']), ['type' => QuestionBankQuestion::TYPE_TRUE_FALSE]);

        $this->assertSame([], $this->screening->mechanicalFlags($item));
    }

    public function test_identical_texts_have_a_similarity_of_one_and_unrelated_texts_much_less(): void
    {
        $vectors = $this->screening->embed([
            'Which tool answers a ready reference question',
            'Which tool answers a ready reference question',
            'Describe the main classes of the Dewey Decimal Classification system',
        ]);

        $dot = fn (array $a, array $b) => array_sum(array_map(fn ($x, $y) => $x * $y, $a, $b));

        $this->assertEqualsWithDelta(1.0, $dot($vectors[0], $vectors[1]), 1e-9);
        $this->assertLessThan(0.5, $dot($vectors[0], $vectors[2]));
        $this->assertEqualsWithDelta(1.0, $dot($vectors[2], $vectors[2]), 1e-9, 'Vectors are unit length.');
    }

    public function test_texts_with_no_real_words_have_no_vector(): void
    {
        $this->assertSame([], $this->screening->embed(['', 'a b c', '!!!']));
    }

    public function test_a_vector_survives_being_stored_and_read_back(): void
    {
        $vector = [0.5, -0.25, 0.0, 0.125];

        $this->assertSame($vector, $this->screening->decodeVector($this->screening->encodeVector($vector)));
        $this->assertSame([], $this->screening->decodeVector(null));
        $this->assertSame([], $this->screening->decodeVector(''));
    }

    public function test_an_empty_batch_accepts_and_rejects_nothing(): void
    {
        $this->assertSame(['accepted' => [], 'rejected' => []], $this->screening->screen([], 'Reference Services'));
    }

    public function test_a_batch_of_prompts_with_no_words_skips_duplicate_screening(): void
    {
        $result = $this->screening->screen([$this->item($this->choices(['A1', 'B1', 'C1', 'D1']), ['prompt' => '?'])], 'Reference Services');

        $this->assertCount(1, $result['accepted']);
    }

    public function test_a_repeat_within_the_batch_is_rejected_and_named_by_its_lineage(): void
    {
        $options = $this->choices(['Almanac', 'Thesaurus', 'Atlas', 'Gazetteer']);
        $batch = [
            $this->item($options, ['source_index' => 0]),
            $this->item($options, ['source_index' => 0]),
            $this->item($options, ['source_index' => 1]),
            $this->item($options, ['source_index' => 2, 'prompt' => 'Explain how a union catalog differs from a local one']),
        ];

        $result = $this->screening->screen($batch, 'Reference Services');

        $this->assertSame(['duplicate_variant_of_same_item', 'duplicate_within_batch'], array_column($result['rejected'], 'reason'));
        $this->assertCount(2, $result['accepted']);
    }

    public function test_a_question_already_in_the_bank_is_rejected(): void
    {
        $bank = $this->makeBank($this->makeTeacher(), 1, ['subject' => 'Reference Services']);
        $existing = $bank->questions->first();
        $existing->update([
            'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
            'embedding' => $this->screening->encodeVector($this->screening->embed([
                'Which tool answers a ready reference question?',
                'Some other text so the weights are not all zero',
            ])[0]),
        ]);
        // A stored row with no readable vector is skipped.
        $this->makeQuestion($bank, 2, ['usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT, 'embedding' => 'x']);

        $result = $this->screening->screen([
            $this->item($this->choices(['Almanac', 'Thesaurus', 'Atlas', 'Gazetteer'])),
            $this->item($this->choices(['Almanac', 'Thesaurus', 'Atlas', 'Gazetteer']), ['prompt' => 'Some other text so the weights are not all zero']),
        ], 'Reference Services');

        $this->assertContains('duplicate_of_existing_question', array_column($result['rejected'], 'reason'));
    }
}
