<?php

namespace App\Services;

use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\ReviewBotAttemptAnswer;
use App\Models\ReviewBotAttemptQuestion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Student-facing Review Bot: serves questions from the teacher-curated
 * Review Bot Question Bank (QuestionBankQuestion rows with
 * usage_type = review_bot and status = published). Never calls OpenAI --
 * that only happens on the teacher side, see ReviewBotAdminService.
 */
class ReviewBotService
{
    private const WEAK_SCORE_THRESHOLD = 60.0;
    private const STRONG_SCORE_THRESHOLD = 75.0;

    /**
     * Static copy for the subject picker (description/focus areas only --
     * actual question inventory always comes from the database).
     *
     * @var array<string, array{description: string, focus_areas: array<int, string>}>
     */
    private const SUBJECT_PROFILES = [
        'Cataloging and Classification' => [
            'description' => 'Bibliographic description, call numbers, and subject access tools used to organize collections.',
            'focus_areas' => ['RDA and descriptive cataloging', 'Classification systems and call numbers', 'Subject headings and access points'],
        ],
        'Indexing and Abstracting' => [
            'description' => 'Indexing terms, abstracts, retrieval quality, and subject analysis.',
            'focus_areas' => ['Descriptors and controlled vocabulary', 'Abstract types and functions', 'Precision, recall, and retrieval quality'],
        ],
        'Information Technology' => [
            'description' => 'Search systems, metadata, digital tools, and technology used in libraries.',
            'focus_areas' => ['OPAC, ILS, and discovery tools', 'Search strategy and Boolean logic', 'Digital systems, security, and interoperability'],
        ],
        'Reference Services' => [
            'description' => 'User assistance, reference interviews, source evaluation, and information service delivery.',
            'focus_areas' => ['Reference interview and user needs', 'Ready reference and referral', 'Information literacy and source evaluation'],
        ],
        'Library Management' => [
            'description' => 'Management functions, policy work, budgeting, staff support, and service quality decisions.',
            'focus_areas' => ['Planning, organizing, leading, and controlling', 'Policy, budgeting, and assessment', 'Staff development and risk management'],
        ],
        'Selection and Acquisition' => [
            'description' => 'Choosing materials, verifying records, ordering resources, and managing vendors.',
            'focus_areas' => ['Selection criteria and policy alignment', 'Acquisition methods and ordering workflow', 'Vendor review and bibliographic verification'],
        ],
    ];

    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
    ) {
    }

    /**
     * Every subject, with the real published-question inventory (topics,
     * subtopics, difficulties, counts) so the student picker only ever
     * offers combinations that actually have content.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        $published = QuestionBankQuestion::query()
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->with(['topic:id,name', 'subtopic:id,name,topic_id'])
            ->get(['id', 'subject', 'topic_id', 'subtopic_id', 'difficulty']);

        $bySubject = $published->groupBy(fn (QuestionBankQuestion $question) => $this->normalizeSubjectLabel($question->subject));

        return collect(self::SUBJECT_PROFILES)
            ->map(function (array $profile, string $subject) use ($bySubject): array {
                $questions = $bySubject->get($subject, collect());

                $topics = $questions
                    ->filter(fn (QuestionBankQuestion $question) => $question->topic)
                    ->groupBy('topic_id')
                    ->map(function (Collection $topicQuestions): array {
                        $topic = $topicQuestions->first()->topic;

                        $subtopics = $topicQuestions
                            ->filter(fn (QuestionBankQuestion $question) => $question->subtopic)
                            ->groupBy('subtopic_id')
                            ->map(fn (Collection $subtopicQuestions): array => [
                                'id' => (int) $subtopicQuestions->first()->subtopic->id,
                                'name' => (string) $subtopicQuestions->first()->subtopic->name,
                                'question_count' => $subtopicQuestions->count(),
                            ])
                            ->values()
                            ->sortBy('name')
                            ->values()
                            ->all();

                        return [
                            'id' => (int) $topic->id,
                            'name' => (string) $topic->name,
                            'question_count' => $topicQuestions->count(),
                            'subtopics' => $subtopics,
                        ];
                    })
                    ->values()
                    ->sortBy('name')
                    ->values()
                    ->all();

                $difficulties = $questions
                    ->pluck('difficulty')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'subject' => $subject,
                    'description' => $profile['description'],
                    'focus_areas' => $profile['focus_areas'],
                    'question_count' => $questions->count(),
                    'bot_ready' => $questions->isNotEmpty(),
                    'topics' => $topics,
                    'difficulties' => $difficulties,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The student's weakest area from their real exam history, at the most
     * specific level available (subtopic > topic > subject).
     *
     * @return array<string, mixed>
     */
    public function resolveWeakArea(User $student): array
    {
        return $this->dashboardAnalyticsService->buildWeakAreaRecommendation($student);
    }

    /**
     * @param  array{subject: string, topic_id?: int|null, subtopic_id?: int|null, difficulty?: string|null, question_count: int}  $filters
     * @return array{attempt: ReviewBotAttempt, questions: array<int, array<string, mixed>>}
     */
    public function startSession(User $student, array $filters): array
    {
        $subject = $this->normalizeSubjectLabel($filters['subject'] ?? null);
        $topicId = isset($filters['topic_id']) ? (int) $filters['topic_id'] : null;
        $subtopicId = isset($filters['subtopic_id']) ? (int) $filters['subtopic_id'] : null;
        $difficulty = filled($filters['difficulty'] ?? null) ? (string) $filters['difficulty'] : null;
        $questionCount = max(1, (int) ($filters['question_count'] ?? 10));
        $source = ($filters['source'] ?? null) === ReviewBotAttempt::SOURCE_AUTO_WEAK_AREA
            ? ReviewBotAttempt::SOURCE_AUTO_WEAK_AREA
            : ReviewBotAttempt::SOURCE_MANUAL;

        $questions = QuestionBankQuestion::query()
            ->with(['options:id,question_bank_question_id,option_label,option_text,is_correct'])
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->where('subject', $subject)
            ->when($topicId, fn ($query) => $query->where('topic_id', $topicId))
            ->when($subtopicId, fn ($query) => $query->where('subtopic_id', $subtopicId))
            ->when($difficulty, fn ($query) => $query->where('difficulty', $difficulty))
            ->inRandomOrder()
            ->limit($questionCount)
            ->get();

        if ($questions->isEmpty()) {
            throw new RuntimeException('No published Review Bot questions match this filter yet. Try a broader subject/topic, or check back after your teacher publishes more.');
        }

        $attempt = ReviewBotAttempt::create([
            'user_id' => $student->id,
            'subject' => $subject,
            'topic_id' => $topicId,
            'subtopic_id' => $subtopicId,
            'difficulty' => $difficulty,
            'source' => $source,
            'status' => ReviewBotAttempt::STATUS_IN_PROGRESS,
            'total_items' => $questions->count(),
            'started_at' => now(),
        ]);

        $questions->values()->each(function (QuestionBankQuestion $question, int $index) use ($attempt): void {
            ReviewBotAttemptQuestion::create([
                'review_bot_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'item_number' => $index + 1,
            ]);
        });

        return [
            'attempt' => $attempt,
            'questions' => $questions->values()->map(fn (QuestionBankQuestion $question, int $index): array => [
                'item_number' => $index + 1,
                'question_id' => (int) $question->id,
                'question_text' => (string) $question->question_text,
                'question_type' => (string) $question->question_type,
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->option_label,
                    'text' => $option->option_text,
                ])->values(),
            ])->all(),
        ];
    }

    /**
     * @param  array<int, array{question_id: int, option_id?: int|null, answer_text?: string|null}>  $answers
     * @return array<string, mixed>
     */
    public function submitSession(ReviewBotAttempt $attempt, array $answers): array
    {
        if ($attempt->status === ReviewBotAttempt::STATUS_SUBMITTED) {
            throw new RuntimeException('This review session was already submitted.');
        }

        $attempt->loadMissing(['attemptQuestions.question.options', 'topic:id,name', 'subtopic:id,name']);

        $answersByQuestionId = collect($answers)->keyBy(fn (array $answer) => (int) ($answer['question_id'] ?? 0));
        $questionIds = $attempt->attemptQuestions->pluck('question_bank_question_id')->map(fn ($id) => (int) $id);

        $correctCount = 0;
        $answeredCount = 0;
        $missedItems = [];

        foreach ($attempt->attemptQuestions as $attemptQuestion) {
            $question = $attemptQuestion->question;
            if (!$question) {
                continue;
            }

            $answer = $answersByQuestionId->get((int) $question->id);
            $optionId = $answer['option_id'] ?? null;
            $answerText = trim((string) ($answer['answer_text'] ?? ''));
            $hasAnswer = $answer !== null && ($optionId !== null || $answerText !== '');

            $selectedOption = $optionId
                ? $question->options->first(fn ($option) => (int) $option->id === (int) $optionId)
                : null;

            $isCorrect = $selectedOption
                ? (bool) $selectedOption->is_correct
                : ($hasAnswer && $this->normalizeAnswerText($answerText) === $this->normalizeAnswerText((string) $question->answer_text));

            if ($hasAnswer) {
                $answeredCount++;
            }

            if ($isCorrect) {
                $correctCount++;
            }

            ReviewBotAttemptAnswer::updateOrCreate(
                [
                    'review_bot_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $question->id,
                ],
                [
                    'question_bank_option_id' => $selectedOption?->id,
                    'answer_text' => $answerText !== '' ? $answerText : null,
                    'is_correct' => $isCorrect,
                    'answered_at' => now(),
                ]
            );

            if ($hasAnswer && !$isCorrect) {
                $correctOption = $question->options->first(fn ($option) => (bool) $option->is_correct);

                $missedItems[] = [
                    'item_number' => $attemptQuestion->item_number,
                    'question_text' => $question->question_text,
                    'your_answer' => $selectedOption?->option_text ?? $answerText,
                    'correct_answer' => $correctOption?->option_text ?? $question->answer_text,
                    'explanation' => $question->explanation,
                ];
            }
        }

        $totalItems = $attempt->attemptQuestions->count();
        $scorePercent = $totalItems > 0 ? round(($correctCount / $totalItems) * 100, 2) : 0.0;

        $attempt->forceFill([
            'status' => ReviewBotAttempt::STATUS_SUBMITTED,
            'answered_count' => $answeredCount,
            'correct_answers' => $correctCount,
            'score_percent' => $scorePercent,
            'submitted_at' => now(),
        ])->save();

        $previousAttempt = ReviewBotAttempt::query()
            ->where('user_id', $attempt->user_id)
            ->where('status', ReviewBotAttempt::STATUS_SUBMITTED)
            ->where('id', '!=', $attempt->id)
            ->when($attempt->subtopic_id, fn ($query) => $query->where('subtopic_id', $attempt->subtopic_id))
            ->when(!$attempt->subtopic_id && $attempt->topic_id, fn ($query) => $query->where('topic_id', $attempt->topic_id)->whereNull('subtopic_id'))
            ->when(!$attempt->subtopic_id && !$attempt->topic_id, fn ($query) => $query->where('subject', $attempt->subject)->whereNull('topic_id'))
            ->orderByDesc('submitted_at')
            ->first();

        $improved = $previousAttempt && $previousAttempt->score_percent !== null
            ? $scorePercent > (float) $previousAttempt->score_percent
            : null;

        return [
            'attempt_id' => (int) $attempt->id,
            'subject' => $attempt->subject,
            'topic' => $attempt->topic?->name,
            'subtopic' => $attempt->subtopic?->name,
            'total_items' => $totalItems,
            'answered_count' => $answeredCount,
            'correct_answers' => $correctCount,
            'score_percent' => $scorePercent,
            'missed_items' => $missedItems,
            'improved' => $improved,
            'previous_score_percent' => $previousAttempt?->score_percent !== null ? (float) $previousAttempt->score_percent : null,
            'recommendation' => $this->buildRecommendation($attempt, $scorePercent),
        ];
    }

    /**
     * @return array{action: string, message: string, next_focus: array<string, mixed>|null}
     */
    protected function buildRecommendation(ReviewBotAttempt $attempt, float $scorePercent): array
    {
        if ($scorePercent < self::WEAK_SCORE_THRESHOLD) {
            return [
                'action' => 'repeat_topic',
                'message' => 'This is still a weak spot. Review the explanations above, then try this same topic again.',
                'next_focus' => null,
            ];
        }

        if ($scorePercent >= self::STRONG_SCORE_THRESHOLD) {
            $student = $attempt->student ?? User::find($attempt->user_id);
            $nextWeakArea = $student ? $this->resolveWeakArea($student) : [];

            $isSameFocus = $nextWeakArea !== []
                && (int) ($nextWeakArea['topic_id'] ?? 0) === (int) ($attempt->topic_id ?? 0)
                && (int) ($nextWeakArea['subtopic_id'] ?? 0) === (int) ($attempt->subtopic_id ?? 0);

            if ($nextWeakArea !== [] && !$isSameFocus) {
                $label = collect([$nextWeakArea['subject'] ?? null, $nextWeakArea['topic'] ?? null, $nextWeakArea['subtopic'] ?? null])
                    ->filter()
                    ->implode(' → ');

                return [
                    'action' => 'next_topic',
                    'message' => "Solid result. Next up, consider reviewing: {$label}.",
                    'next_focus' => $nextWeakArea,
                ];
            }

            return [
                'action' => 'keep_going',
                'message' => 'Solid result. Keep this consistency going into your next review or exam.',
                'next_focus' => null,
            ];
        }

        return [
            'action' => 'keep_practicing',
            'message' => 'Decent attempt — a bit more practice on this topic should push you to a strong score.',
            'next_focus' => null,
        ];
    }

    protected function normalizeAnswerText(?string $value): string
    {
        return Str::lower(trim((string) $value));
    }

    protected function normalizeSubjectLabel(mixed $subject): string
    {
        $raw = trim((string) $subject);

        if ($raw === '') {
            return 'General';
        }

        $normalized = Str::of($raw)
            ->lower()
            ->replace('&', 'and')
            ->replace('/', ' ')
            ->replace('-', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        $map = [
            'cataloging and classification' => 'Cataloging and Classification',
            'cataloguing and classification' => 'Cataloging and Classification',
            'cataloging classification' => 'Cataloging and Classification',
            'indexing and abstracting' => 'Indexing and Abstracting',
            'indexing abstracting' => 'Indexing and Abstracting',
            'information technology' => 'Information Technology',
            'it' => 'Information Technology',
            'reference services' => 'Reference Services',
            'reference service' => 'Reference Services',
            'reference' => 'Reference Services',
            'library management' => 'Library Management',
            'selection and acquisition' => 'Selection and Acquisition',
            'selection acquisition' => 'Selection and Acquisition',
            'acquisition and selection' => 'Selection and Acquisition',
            'acquisition selection' => 'Selection and Acquisition',
        ];

        if (array_key_exists($normalized, $map)) {
            return $map[$normalized];
        }

        foreach ($map as $key => $canonical) {
            if (str_contains($normalized, $key) || str_contains($key, $normalized)) {
                return $canonical;
            }
        }

        return Str::title($normalized);
    }
}
