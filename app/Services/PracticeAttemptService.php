<?php

namespace App\Services;

use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptAnswer;
use App\Models\PracticeAttemptQuestion;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PracticeAttemptService
{
    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOverview(User $student): array
    {
        $weakArea = $this->resolveWeakAreaForPractice($student);

        if (empty($weakArea)) {
            return [
                'weak_area' => null,
                'available_questions' => 0,
            ];
        }

        return [
            'weak_area' => $weakArea,
            'available_questions' => $this->countAvailableQuestions($weakArea),
        ];
    }

    public function startAttempt(User $student, int $questionCount): PracticeAttempt
    {
        $weakArea = $this->resolveWeakAreaForPractice($student);

        if (empty($weakArea)) {
            throw new RuntimeException('No weak area could be calculated yet. Complete more exams first.');
        }

        $questionIds = $this->resolveQuestionIdsForWeakArea($weakArea, $questionCount);

        if ($questionIds === []) {
            throw new RuntimeException('No approved remedial questions are available for your current weak area.');
        }

        $startedAt = now();
        $topicId = isset($weakArea['topic_id']) ? (int) $weakArea['topic_id'] : null;

        return DB::transaction(function () use ($student, $weakArea, $questionIds, $startedAt, $topicId): PracticeAttempt {
            $attempt = PracticeAttempt::create([
                'user_id' => $student->id,
                'status' => PracticeAttempt::STATUS_IN_PROGRESS,
                'target_type' => (string) ($weakArea['basis'] ?? PracticeAttempt::TARGET_SUBJECT),
                'subject' => $weakArea['subject'] ?? null,
                'topic_id' => $topicId ?: null,
                'total_items' => count($questionIds),
                'answered_count' => 0,
                'correct_answers' => 0,
                'started_at' => $startedAt,
            ]);

            $rows = [];

            foreach ($questionIds as $index => $questionId) {
                $rows[] = [
                    'practice_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $questionId,
                    'item_number' => $index + 1,
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ];
            }

            PracticeAttemptQuestion::insert($rows);

            return $attempt->refresh();
        });
    }

    public function refreshMetrics(PracticeAttempt $attempt, bool $finalize = false): PracticeAttempt
    {
        $answers = $attempt->answers()
            ->select('is_correct')
            ->get();

        $answeredCount = $answers->count();
        $correctAnswers = $answers->where('is_correct', true)->count();
        $scorePercent = $attempt->total_items > 0
            ? round(($correctAnswers / $attempt->total_items) * 100, 2)
            : 0.0;

        $attempt->answered_count = $answeredCount;
        $attempt->correct_answers = $correctAnswers;

        if ($finalize) {
            $attempt->status = PracticeAttempt::STATUS_SUBMITTED;
            $attempt->submitted_at = $attempt->submitted_at ?? now();
            $attempt->score_percent = $scorePercent;
        }

        if ($attempt->status === PracticeAttempt::STATUS_SUBMITTED && is_null($attempt->score_percent)) {
            $attempt->score_percent = $scorePercent;
        }

        $attempt->save();

        return $attempt->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAttemptPayload(PracticeAttempt $attempt): array
    {
        $attempt->loadMissing([
            'topic:id,subject,name',
            'attemptQuestions.question.topic:id,subject,name',
            'attemptQuestions.question.options:id,question_bank_question_id,option_label,option_text,is_correct',
            'answers',
        ]);

        $isSubmitted = $attempt->status === PracticeAttempt::STATUS_SUBMITTED;
        $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');

        $questions = $attempt->attemptQuestions
            ->sortBy('item_number')
            ->values()
            ->map(function (PracticeAttemptQuestion $attemptQuestion) use ($answersByQuestionId, $isSubmitted): ?array {
                $question = $attemptQuestion->question;

                if (!$question) {
                    return null;
                }

                $answer = $answersByQuestionId->get($question->id);
                $hasAnswer = !is_null($answer);
                $canRevealFeedback = $isSubmitted || $hasAnswer;

                return [
                    'question_id' => $question->id,
                    'item_number' => $attemptQuestion->item_number,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'subject' => $question->subject,
                    'topic' => $question->topic?->name,
                    'options' => $question->options->map(fn ($option) => [
                        'id' => $option->id,
                        'label' => $option->option_label,
                        'text' => $option->option_text,
                        'is_correct' => $canRevealFeedback ? (bool) $option->is_correct : null,
                    ])->values(),
                    'answer' => [
                        'selected_option_id' => $answer?->question_bank_option_id,
                        'answer_text' => $answer?->answer_text,
                        'is_correct' => $hasAnswer ? $answer?->is_correct : null,
                    ],
                    'correct_answer' => $canRevealFeedback
                        ? [
                            'label' => $question->answer_label,
                            'text' => $question->answer_text,
                        ]
                        : null,
                    'explanation' => null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'target_type' => $attempt->target_type,
                'subject' => $attempt->subject,
                'topic' => $attempt->topic?->name,
                'topic_id' => $attempt->topic_id,
                'total_items' => $attempt->total_items,
                'answered_count' => $attempt->answered_count,
                'correct_answers' => $attempt->correct_answers,
                'score_percent' => $attempt->score_percent,
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
            ],
            'questions' => $questions,
        ];
    }

    /**
     * @param  array<string, mixed>  $weakArea
     * @return list<int>
     */
    protected function resolveQuestionIdsForWeakArea(array $weakArea, int $questionCount): array
    {
        $topicId = isset($weakArea['topic_id']) ? (int) $weakArea['topic_id'] : 0;
        $subject = trim((string) ($weakArea['subject'] ?? ''));
        $limit = max(1, $questionCount);

        $selectedIds = [];

        if ($topicId > 0) {
            $selectedIds = QuestionBankQuestion::query()
                ->where('usage_type', QuestionBankQuestion::USAGE_REMEDIAL)
                ->where('status', QuestionBankQuestion::STATUS_APPROVED)
                ->where('ai_generated', true)
                ->whereIn('item_style', QuestionBankQuestion::ITEM_STYLES)
                ->where('topic_id', $topicId)
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        if (count($selectedIds) < $limit && $subject !== '') {
            $extraIds = QuestionBankQuestion::query()
                ->where('usage_type', QuestionBankQuestion::USAGE_REMEDIAL)
                ->where('status', QuestionBankQuestion::STATUS_APPROVED)
                ->where('ai_generated', true)
                ->whereIn('item_style', QuestionBankQuestion::ITEM_STYLES)
                ->where('subject', $subject)
                ->when(
                    $selectedIds !== [],
                    fn ($query) => $query->whereNotIn('id', $selectedIds),
                )
                ->inRandomOrder()
                ->limit($limit - count($selectedIds))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            $selectedIds = array_values(array_unique(array_merge($selectedIds, $extraIds)));
        }

        return $selectedIds;
    }

    /**
     * @param  array<string, mixed>  $weakArea
     */
    protected function countAvailableQuestions(array $weakArea): int
    {
        $topicId = isset($weakArea['topic_id']) ? (int) $weakArea['topic_id'] : 0;
        $subject = trim((string) ($weakArea['subject'] ?? ''));

        if ($subject === '') {
            return $topicId > 0
                ? (int) QuestionBankQuestion::query()
                    ->where('usage_type', QuestionBankQuestion::USAGE_REMEDIAL)
                    ->where('status', QuestionBankQuestion::STATUS_APPROVED)
                    ->where('ai_generated', true)
                    ->whereIn('item_style', QuestionBankQuestion::ITEM_STYLES)
                    ->where('topic_id', $topicId)
                    ->count()
                : 0;
        }

        return (int) QuestionBankQuestion::query()
            ->where('usage_type', QuestionBankQuestion::USAGE_REMEDIAL)
            ->where('status', QuestionBankQuestion::STATUS_APPROVED)
            ->where('ai_generated', true)
            ->whereIn('item_style', QuestionBankQuestion::ITEM_STYLES)
            ->where('subject', $subject)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveWeakAreaForPractice(User $student): array
    {
        $weakArea = $this->dashboardAnalyticsService->buildWeakAreaRecommendation($student);

        if (!empty($weakArea) && $this->countAvailableQuestions($weakArea) > 0) {
            return $weakArea;
        }

        $fallbackSubject = trim((string) ($weakArea['fallback_subject'] ?? ''));
        if ($fallbackSubject !== '') {
            $subjectFallback = [
                'basis' => 'subject',
                'subject' => $fallbackSubject,
                'subject_score' => null,
                'topic_id' => null,
                'topic' => null,
                'topic_score' => null,
                'fallback_subject' => null,
            ];

            if ($this->countAvailableQuestions($subjectFallback) > 0) {
                return $subjectFallback;
            }
        }

        $subjectPerformance = $this->dashboardAnalyticsService->buildSubjectWeaknessPerformance($student);

        foreach ($subjectPerformance as $subjectRow) {
            $label = trim((string) ($subjectRow['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $subjectWeakArea = [
                'basis' => 'subject',
                'subject' => $label,
                'subject_score' => isset($subjectRow['score']) ? (float) $subjectRow['score'] : null,
                'topic_id' => null,
                'topic' => null,
                'topic_score' => null,
                'fallback_subject' => null,
            ];

            if ($this->countAvailableQuestions($subjectWeakArea) > 0) {
                return $subjectWeakArea;
            }
        }

        $subjectPoolFallback = QuestionBankQuestion::query()
            ->where('usage_type', QuestionBankQuestion::USAGE_REMEDIAL)
            ->where('status', QuestionBankQuestion::STATUS_APPROVED)
            ->where('ai_generated', true)
            ->whereIn('item_style', QuestionBankQuestion::ITEM_STYLES)
            ->whereRaw("COALESCE(NULLIF(subject, ''), '') <> ''")
            ->selectRaw('subject, COUNT(*) as total')
            ->groupBy('subject')
            ->orderByDesc('total')
            ->value('subject');

        if (is_string($subjectPoolFallback) && trim($subjectPoolFallback) !== '') {
            return [
                'basis' => 'subject',
                'subject' => trim($subjectPoolFallback),
                'subject_score' => null,
                'topic_id' => null,
                'topic' => null,
                'topic_score' => null,
                'fallback_subject' => null,
            ];
        }

        return [];
    }
}
