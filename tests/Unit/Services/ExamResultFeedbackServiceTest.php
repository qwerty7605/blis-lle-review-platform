<?php

namespace Tests\Unit\Services;

use App\Models\ExamAttempt;
use App\Services\DashboardAnalyticsService;
use App\Services\ExamResultFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * ExamResultFeedbackService, called directly: the feedback on a result page.
 * Covers the score bands of each message, how weak and strong areas are
 * ordered, and when two areas count as the same one.
 */
class ExamResultFeedbackServiceTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private function exposed(): object
    {
        return new class(app(DashboardAnalyticsService::class)) extends ExamResultFeedbackService {
            public function __call($method, $arguments)
            {
                return $this->{$method}(...$arguments);
            }
        };
    }

    private function scored(float $score, int $total = 10, int $answered = 10): ExamAttempt
    {
        return new ExamAttempt(['score_percent' => $score, 'total_items' => $total, 'answered_count' => $answered]);
    }

    private function area(string $label, float $score, array $extra = []): array
    {
        return ['label' => $label, 'score_percent' => $score, 'missed_count' => 0, 'incorrect_count' => 0, 'total_items' => 5, 'correct_count' => 0, ...$extra];
    }

    public function test_an_attempt_still_under_way_gets_no_feedback(): void
    {
        $this->assertNull(app(ExamResultFeedbackService::class)->buildForAttempt(new ExamAttempt(['status' => ExamAttempt::STATUS_IN_PROGRESS])));
    }

    public function test_a_handed_in_attempt_gets_every_part_of_the_feedback(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$student]);
        $topic = $this->makeTopic('Reference, Bibliography and User Services', 'Reference Interview', $teacher);
        $subtopic = $this->makeSubtopic($topic, 'Open questions', $teacher);
        $bank = $this->makeBank($teacher, 4, [], ['topic_id' => $topic->id, 'subtopic_id' => $subtopic->id]);
        $exam = $this->makeExam($teacher, $bank, [$room]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $student, [true, true, true, true]);

        $feedback = app(ExamResultFeedbackService::class)->buildForAttempt($attempt);

        $this->assertSame('excellent', $feedback['summary']['tone']);
        $this->assertSame('exam_ready', $feedback['readiness_level']['level']);
        $this->assertTrue($feedback['pass_status']['is_passing']);
        $this->assertSame('Reference Interview → Open questions', $feedback['topic_breakdown'][0]['leaf_label'] ?? $feedback['primary_focus']['leaf_label']);
    }

    public function test_each_score_band_has_its_own_readiness_level_and_summary(): void
    {
        $service = $this->exposed();
        $focus = ['label' => 'Reference, Bibliography and User Services'];

        $bands = [
            95.0 => ['exam_ready', 'excellent'],
            80.0 => ['near_ready', 'passing'],
            65.0 => ['developing', 'review'],
            30.0 => ['needs_reinforcement', 'needs-work'],
        ];

        foreach ($bands as $score => [$level, $tone]) {
            $this->assertSame($level, $service->buildReadinessLevel($this->scored($score))['level'], (string) $score);
            $this->assertSame($tone, $service->buildSummary($this->scored($score), $focus)['tone'], (string) $score);
            $this->assertSame($tone, $service->buildSummary($this->scored($score), null)['tone'], (string) $score);
        }

        $this->assertStringContainsString('weakest slice of this attempt was Reference, Bibliography and User Services', $service->buildSummary($this->scored(95), $focus)['message']);
        $this->assertStringContainsString('Keep reviewing to maintain this level', $service->buildSummary($this->scored(95), null)['message']);
        $this->assertStringContainsString('Reference, Bibliography and User Services pulled your score down', $service->buildSummary($this->scored(80), $focus)['message']);
        $this->assertStringContainsString('Reference, Bibliography and User Services needs targeted review', $service->buildSummary($this->scored(65), $focus)['message']);
        $this->assertStringContainsString('several concepts still need review', $service->buildSummary($this->scored(65), null)['message']);
    }

    public function test_topic_levels_follow_the_score(): void
    {
        $service = $this->exposed();

        $this->assertSame('strong', $service->topicLevel(85.0));
        $this->assertSame('average', $service->topicLevel(65.0));
        $this->assertSame('weak', $service->topicLevel(45.0));
        $this->assertSame('needs_review', $service->topicLevel(10.0));
    }

    public function test_weak_areas_sort_lowest_score_first_then_by_what_was_missed_then_by_name(): void
    {
        $sorted = $this->exposed()->sortWeakAreas([
            $this->area('Same score, fewer missed', 50, ['missed_count' => 1]),
            $this->area('Higher score', 70),
            $this->area('Same score, more missed', 50, ['missed_count' => 3]),
            $this->area('Tie on missed, more wrong', 40, ['incorrect_count' => 4]),
            $this->area('Tie on missed, fewer wrong', 40, ['incorrect_count' => 1]),
            $this->area('Tie all, bigger', 30, ['total_items' => 9]),
            $this->area('Tie all, smaller', 30, ['total_items' => 2]),
            $this->area('B same', 20),
            $this->area('A same', 20),
        ]);

        $this->assertSame([
            'A same', 'B same',
            'Tie all, bigger', 'Tie all, smaller',
            'Tie on missed, more wrong', 'Tie on missed, fewer wrong',
            'Same score, more missed', 'Same score, fewer missed',
            'Higher score',
        ], array_column($sorted, 'label'));
    }

    public function test_strong_areas_sort_highest_score_first_then_most_correct_then_fewest_missed(): void
    {
        $sorted = $this->exposed()->sortStrongAreas([
            $this->area('Lower', 60),
            $this->area('Tie, fewer correct', 90, ['correct_count' => 2]),
            $this->area('Tie, more correct', 90, ['correct_count' => 5]),
            $this->area('Tie, more missed', 80, ['missed_count' => 3]),
            $this->area('Tie, fewer missed', 80, ['missed_count' => 1]),
            $this->area('Z name', 70),
            $this->area('Y name', 70),
        ]);

        $this->assertSame([
            'Tie, more correct', 'Tie, fewer correct',
            'Tie, fewer missed', 'Tie, more missed',
            'Y name', 'Z name',
            'Lower',
        ], array_column($sorted, 'label'));
    }

    public function test_two_areas_are_the_same_only_at_the_same_level_and_place(): void
    {
        $service = $this->exposed();
        $topic = ['basis' => 'topic', 'topic_id' => 3, 'subtopic_id' => 0];

        $this->assertFalse($service->isSameFocus(null, $topic));
        $this->assertFalse($service->isSameFocus($topic, ['basis' => 'subject', 'subject' => 'X']));
        $this->assertTrue($service->isSameFocus($topic, ['basis' => 'topic', 'topic_id' => 3]));
        $this->assertFalse($service->isSameFocus($topic, ['basis' => 'topic', 'topic_id' => 3, 'subtopic_id' => 8]));
        $this->assertTrue($service->isSameFocus(['basis' => 'subject', 'subject' => 'X'], ['basis' => 'subject', 'subject' => 'X']));
    }

    public function test_a_weak_area_from_history_matches_the_focus_at_the_level_both_have(): void
    {
        $service = $this->exposed();
        $topicFocus = ['basis' => 'topic', 'topic_id' => 3];

        $this->assertFalse($service->matchesWeakArea(null, ['basis' => 'topic']));
        $this->assertTrue($service->matchesWeakArea($topicFocus, ['basis' => 'subtopic', 'topic_id' => 3]));
        $this->assertFalse($service->matchesWeakArea($topicFocus, ['basis' => 'topic', 'topic_id' => 4]));
        $this->assertFalse($service->matchesWeakArea(['basis' => 'subject', 'subject' => 'X'], ['basis' => 'topic', 'topic_id' => 3]));
        $this->assertTrue($service->matchesWeakArea(['basis' => 'subject', 'subject' => 'X'], ['basis' => 'subject', 'subject' => ' X ']));
    }

    public function test_a_subtopic_with_no_topic_is_labelled_by_itself(): void
    {
        $stats = $this->exposed()->formatAreaStats('topic', 'Cataloging', 1, null, 4, 3, 2, 9, 'MARC fields');

        $this->assertSame('MARC fields', $stats['leaf_label']);
        $this->assertSame('Cataloging • MARC fields', $stats['label']);
        $this->assertSame(1, $stats['incorrect_count']);
        $this->assertSame(1, $stats['missed_count']);
    }

    public function test_a_steady_area_is_suggested_as_a_strength_when_history_does_not_match(): void
    {
        $actions = $this->exposed()->buildRecommendedActions(
            $this->scored(70),
            null,
            ['label' => 'Indexing'],
            ['matches_primary_focus' => false],
        );

        $this->assertContains('Keep using Indexing as a stable area while you reinforce weaker concepts.', $actions);
    }

    public function test_many_unanswered_items_bring_advice_on_pacing(): void
    {
        $actions = $this->exposed()->buildRecommendedActions($this->scored(40, 10, 5), null, null, null);

        $this->assertContains('Improve pacing on the next attempt. 5 item(s) were left unanswered.', $actions);
    }

    public function test_with_a_topic_focus_and_no_other_topic_the_strength_is_another_subject(): void
    {
        $focus = ['basis' => 'topic', 'topic_id' => 1, 'subject' => 'Cataloging', 'label' => 'Cataloging • MARC'];
        $subjects = [$this->area('Cataloging', 40, ['basis' => 'subject', 'subject' => 'Cataloging']), $this->area('Indexing', 90, ['basis' => 'subject', 'subject' => 'Indexing'])];

        $strength = $this->exposed()->selectStrength($focus, $subjects, []);

        $this->assertSame('Indexing', $strength['label']);
    }
}
