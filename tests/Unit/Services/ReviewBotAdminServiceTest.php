<?php

namespace Tests\Unit\Services;

use App\Jobs\GenerateReviewBotQuestionsJob;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionMappingService;
use App\Services\ReviewBotAdminService;
use App\Services\ReviewBotQuestionScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * ReviewBotAdminService, called directly: the automatic top-up, generation
 * when there is nothing to generate from, the checks every generated
 * question must pass before it is kept, and the bulk status changes.
 */
class ReviewBotAdminServiceTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private const SUBJECT = 'Reference Services';

    private function service(): ReviewBotAdminService
    {
        return app(ReviewBotAdminService::class);
    }

    /** The service with its protected steps opened up for testing. */
    private function exposed(): object
    {
        return new class(app(QuestionMappingService::class), app(ReviewBotQuestionScreeningService::class)) extends ReviewBotAdminService {
            public function normalize(mixed $raw): array
            {
                return $this->normalizeGeneratedQuestions($raw);
            }

            public function persist(array $generated, Collection $sources, QuestionBank $bank, string $subject): int
            {
                return $this->persistGenerated($generated, $sources, $bank, $subject);
            }
        };
    }

    private function validQuestion(array $overrides = []): array
    {
        return [
            'source_index' => 0,
            'prompt' => 'Which tool answers a ready reference question?',
            'type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'options' => [
                ['id' => 'a', 'text' => 'An almanac'],
                ['id' => 'b', 'text' => 'A thesaurus'],
            ],
            'correct_option_id' => 'a',
            'explanation' => 'Almanacs hold quick facts.',
            'difficulty' => 'easy',
            'cognitive_level' => 'recall',
            ...$overrides,
        ];
    }

    public function test_a_top_up_is_not_queued_for_a_subject_nobody_has_generated_for(): void
    {
        Queue::fake();

        $this->service()->queueTopUp(self::SUBJECT, null, null);

        Queue::assertNothingPushed();
    }

    public function test_a_top_up_is_queued_once_and_then_held_back_for_a_while(): void
    {
        Queue::fake();
        $this->service()->reviewBotBankFor(self::SUBJECT, $this->makeTeacher()->id);

        $this->service()->queueTopUp(self::SUBJECT, 5, null);
        $this->service()->queueTopUp(self::SUBJECT, 5, null);

        Queue::assertPushed(GenerateReviewBotQuestionsJob::class, 1);
    }

    public function test_generating_with_no_exam_items_in_scope_explains_why_nothing_came_out(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->service()->reviewBotBankFor(self::SUBJECT, $teacher->id);

        $result = $this->service()->generate($bank, $teacher->id, self::SUBJECT, null, null, 10);

        $this->assertSame(0, $result['created']);
        $this->assertSame(['Every exam item in this scope already has Review Bot questions, or no teacher-authored items exist for it yet.'], $result['errors']);
    }

    public function test_a_well_formed_generated_question_is_kept_with_lettered_choices(): void
    {
        $normalized = $this->exposed()->normalize([$this->validQuestion()]);

        $this->assertCount(1, $normalized);
        $this->assertSame(['A', 'B'], array_column($normalized[0]['options'], 'label'));
        $this->assertSame('A', $normalized[0]['correct_option_label']);
        $this->assertSame('An almanac', $normalized[0]['correct_option_text']);
        $this->assertSame('easy', $normalized[0]['difficulty']);
    }

    public function test_an_unknown_difficulty_or_level_falls_back_and_a_blank_explanation_is_dropped(): void
    {
        $normalized = $this->exposed()->normalize([$this->validQuestion([
            'difficulty' => 'brutal',
            'cognitive_level' => 'telepathy',
            'explanation' => '   ',
        ])]);

        $this->assertSame(QuestionBankQuestion::DIFFICULTY_MEDIUM, $normalized[0]['difficulty']);
        $this->assertSame('comprehension', $normalized[0]['cognitive_level']);
        $this->assertNull($normalized[0]['explanation']);
    }

    public function test_every_malformed_generated_question_is_dropped(): void
    {
        $this->assertSame([], $this->exposed()->normalize('not a list'));

        $rejects = [
            'not an array',
            $this->validQuestion(['source_index' => null]),
            $this->validQuestion(['source_index' => 'first']),
            $this->validQuestion(['prompt' => '  ']),
            $this->validQuestion(['type' => 'essay']),
            // Choices that are not arrays or are blank do not count, leaving one.
            $this->validQuestion(['options' => ['junk', ['id' => '', 'text' => 'x'], ['id' => 'c', 'text' => ''], ['id' => 'a', 'text' => 'Only one']]]),
            // The correct choice is not among the choices.
            $this->validQuestion(['correct_option_id' => 'z']),
        ];

        $this->assertSame([], $this->exposed()->normalize($rejects));
    }

    public function test_nothing_is_saved_from_an_empty_batch_or_for_a_source_that_is_not_in_it(): void
    {
        $teacher = $this->makeTeacher();
        $source = $this->makeBank($teacher, 1, ['subject' => self::SUBJECT]);
        $bank = $this->service()->reviewBotBankFor(self::SUBJECT, $teacher->id);
        $exposed = $this->exposed();

        $this->assertSame(0, $exposed->persist([], $source->questions, $bank, self::SUBJECT));

        $orphan = $exposed->normalize([$this->validQuestion(['source_index' => 7])]);
        $this->assertSame(0, $exposed->persist($orphan, $source->questions->values(), $bank, self::SUBJECT));

        $kept = $exposed->normalize([$this->validQuestion()]);
        $this->assertSame(1, $exposed->persist($kept, $source->questions->values(), $bank, self::SUBJECT));
        $this->assertSame($source->questions->first()->id, $bank->reviewBotQuestions()->first()->source_question_id);
    }

    public function test_bulk_status_changes_with_no_ids_change_nothing(): void
    {
        $this->assertSame(0, $this->service()->reject([]));
        $this->assertSame(0, $this->service()->publish([], 1));
        $this->assertSame(0, $this->service()->unpublish([]));
    }
}
