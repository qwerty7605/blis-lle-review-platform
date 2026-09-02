<?php

namespace App\Services;

use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\ReviewBotAttemptAnswer;
use App\Models\ReviewBotAttemptQuestion;
use App\Models\ReviewBotItemState;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        $selection = $this->selectSessionQuestions(
            $student,
            $subject,
            $topicId,
            $subtopicId,
            $difficulty,
            $questionCount
        );

        $questions = $selection['questions'];

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
            'scope_used' => $selection['scope_used'],
            'scope_note' => $selection['scope_note'],
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
     * Build one session's worth of questions.
     *
     * Two things happen here. First the scope widens on demand: an empty or
     * thin subtopic tops up from its parent topic, then from the subject, so a
     * student never hits a dead end while the wider pool has content. Second,
     * selection is weighted by the student's own history with each item --
     * questions they keep missing come back, questions they have answered
     * correctly twice in a row drop out of rotation.
     *
     * @return array{questions: Collection<int, QuestionBankQuestion>, scope_used: string, scope_note: string|null}
     */
    protected function selectSessionQuestions(
        User $student,
        string $subject,
        ?int $topicId,
        ?int $subtopicId,
        ?string $difficulty,
        int $questionCount
    ): array {
        $scopes = [];

        // Most specific scope there is: questions generated from the exact exam
        // items this student got wrong. Everything below is a widening fallback.
        $missedSourceIds = $this->missedExamItemIds($student, $subject, $topicId, $subtopicId);

        if ($missedSourceIds !== []) {
            $scopes['missed'] = fn ($query) => $query->whereIn('question_bank_questions.source_question_id', $missedSourceIds);
        }

        if ($subtopicId) {
            $scopes['subtopic'] = fn ($query) => $query->where('question_bank_questions.subtopic_id', $subtopicId);
        }

        if ($topicId) {
            $scopes['topic'] = fn ($query) => $query->where('question_bank_questions.topic_id', $topicId);
        }

        $scopes['subject'] = fn ($query) => $query;

        $requestedScope = array_key_first($scopes);
        $picked = new Collection();
        $scopeUsed = $requestedScope;
        $difficultyRelaxed = false;

        foreach ($scopes as $label => $scope) {
            if ($picked->count() >= $questionCount) {
                break;
            }

            $before = $picked->count();
            $picked = $this->fillFromScope($student, $picked, $scope, $subject, $difficulty, $questionCount);

            if ($picked->count() > $before) {
                $scopeUsed = $label;
            }
        }

        // Widening the scope is preferred over ignoring the student's chosen
        // difficulty, so difficulty is only relaxed once every scope is spent.
        if ($picked->count() < $questionCount && $difficulty !== null) {
            foreach ($scopes as $label => $scope) {
                if ($picked->count() >= $questionCount) {
                    break;
                }

                $before = $picked->count();
                $picked = $this->fillFromScope($student, $picked, $scope, $subject, null, $questionCount);

                if ($picked->count() > $before) {
                    $difficultyRelaxed = true;
                    $scopeUsed = $label;
                }
            }
        }

        return [
            'questions' => $this->interleaveBySubtopic($picked),
            'scope_used' => $scopeUsed,
            'scope_note' => $this->scopeNote($requestedScope, $scopeUsed, $difficultyRelaxed, $picked->count(), $questionCount),
        ];
    }

    /**
     * Take as many questions as the scope can still contribute, honouring the
     * bucket mix first and then topping up by priority.
     *
     * @param  Collection<int, QuestionBankQuestion>  $picked
     * @return Collection<int, QuestionBankQuestion>
     */
    protected function fillFromScope(
        User $student,
        Collection $picked,
        callable $scope,
        string $subject,
        ?string $difficulty,
        int $questionCount
    ): Collection {
        $need = $questionCount - $picked->count();

        if ($need <= 0) {
            return $picked;
        }

        $excludeIds = $picked->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Pass one keeps a session from becoming all-revision or all-new.
        foreach ($this->bucketQuotas($need) as $bucket => $quota) {
            if ($quota <= 0) {
                continue;
            }

            $taken = $this->bucketQuery($student, $bucket, $scope, $subject, $difficulty, $excludeIds)
                ->limit($quota)
                ->get();

            $picked = $picked->concat($taken);
            $excludeIds = array_merge($excludeIds, $taken->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        // Pass two fills whatever the quotas could not, weakest items first.
        foreach ([
            ReviewBotItemState::BUCKET_WEAK,
            ReviewBotItemState::BUCKET_UNSEEN,
            ReviewBotItemState::BUCKET_LEARNING,
            ReviewBotItemState::BUCKET_MASTERED,
        ] as $bucket) {
            $need = $questionCount - $picked->count();

            if ($need <= 0) {
                break;
            }

            $taken = $this->bucketQuery($student, $bucket, $scope, $subject, $difficulty, $excludeIds)
                ->limit($need)
                ->get();

            $picked = $picked->concat($taken);
            $excludeIds = array_merge($excludeIds, $taken->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        return $picked;
    }

    /**
     * Roughly half revision of missed items, and most of the rest new material.
     *
     * @return array<string, int>
     */
    protected function bucketQuotas(int $need): array
    {
        $weak = min((int) ceil($need * 0.5), $need);
        $unseen = min((int) ceil($need * 0.4), $need - $weak);
        $learning = max(0, $need - $weak - $unseen);

        return [
            ReviewBotItemState::BUCKET_WEAK => $weak,
            ReviewBotItemState::BUCKET_UNSEEN => $unseen,
            ReviewBotItemState::BUCKET_LEARNING => $learning,
        ];
    }

    /**
     * @param  array<int, int>  $excludeIds
     * @return \Illuminate\Database\Eloquent\Builder<QuestionBankQuestion>
     */
    protected function bucketQuery(
        User $student,
        string $bucket,
        callable $scope,
        string $subject,
        ?string $difficulty,
        array $excludeIds
    ) {
        $query = QuestionBankQuestion::query()
            ->with(['options:id,question_bank_question_id,option_label,option_text,is_correct'])
            ->select('question_bank_questions.*')
            ->leftJoin('review_bot_item_states', function ($join) use ($student): void {
                $join->on('review_bot_item_states.question_bank_question_id', '=', 'question_bank_questions.id')
                    ->where('review_bot_item_states.user_id', '=', $student->id);
            })
            ->where('question_bank_questions.usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('question_bank_questions.status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->where('question_bank_questions.subject', $subject)
            ->when($difficulty, fn ($builder) => $builder->where('question_bank_questions.difficulty', $difficulty))
            ->when($excludeIds !== [], fn ($builder) => $builder->whereNotIn('question_bank_questions.id', $excludeIds))
            // Cognitive ladder: a student meets a concept at recall level first
            // and only climbs once the easier variants have dropped out of
            // rotation as mastered. Random order breaks ties within a level.
            ->orderByRaw($this->cognitiveLevelOrdering())
            ->inRandomOrder();

        $scope($query);

        return match ($bucket) {
            ReviewBotItemState::BUCKET_UNSEEN => $query
                ->whereNull('review_bot_item_states.id'),
            ReviewBotItemState::BUCKET_WEAK => $query
                ->whereNotNull('review_bot_item_states.id')
                ->where('review_bot_item_states.consecutive_correct', '=', 0),
            ReviewBotItemState::BUCKET_LEARNING => $query
                ->where('review_bot_item_states.consecutive_correct', '=', 1),
            ReviewBotItemState::BUCKET_MASTERED => $query
                ->where('review_bot_item_states.consecutive_correct', '>=', ReviewBotItemState::MASTERY_THRESHOLD),
            default => $query,
        };
    }

    /**
     * Orders cognitive levels easiest-first, portably across MySQL and the
     * SQLite the test suite runs on (neither FIELD() nor an enum would work on
     * both). Levels come from a fixed constant, never user input.
     */
    protected function cognitiveLevelOrdering(): string
    {
        $cases = [];

        foreach (QuestionBankQuestion::COGNITIVE_LEVELS as $rank => $level) {
            $cases[] = sprintf("WHEN '%s' THEN %d", $level, $rank + 1);
        }

        return sprintf(
            'CASE question_bank_questions.cognitive_level %s ELSE %d END',
            implode(' ', $cases),
            count(QuestionBankQuestion::COGNITIVE_LEVELS) + 1
        );
    }

    /**
     * Exam items this student answered incorrectly, narrowed to the scope they
     * asked for. These are the items whose generated variants should be served
     * before anything else.
     *
     * @return array<int, int>
     */
    protected function missedExamItemIds(User $student, string $subject, ?int $topicId, ?int $subtopicId): array
    {
        return DB::table('exam_attempt_answers')
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_attempt_answers.exam_attempt_id')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_answers.question_bank_question_id')
            ->where('exam_attempts.user_id', $student->id)
            ->where('exam_attempt_answers.is_correct', false)
            ->where('question_bank_questions.subject', $subject)
            ->when($subtopicId, fn ($query) => $query->where('question_bank_questions.subtopic_id', $subtopicId))
            ->when(!$subtopicId && $topicId, fn ($query) => $query->where('question_bank_questions.topic_id', $topicId))
            ->distinct()
            ->pluck('exam_attempt_answers.question_bank_question_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Alternate subtopics so a session does not run in blocks. Interleaved
     * practice is consistently better for retention than blocked practice.
     *
     * @param  Collection<int, QuestionBankQuestion>  $questions
     * @return Collection<int, QuestionBankQuestion>
     */
    protected function interleaveBySubtopic(Collection $questions): Collection
    {
        $groups = $questions
            ->groupBy(fn (QuestionBankQuestion $question) => (int) ($question->subtopic_id ?? 0))
            ->map(fn (Collection $group): array => $group->values()->all())
            ->values()
            ->all();

        $interleaved = [];

        while (true) {
            $movedAny = false;

            foreach (array_keys($groups) as $index) {
                if ($groups[$index] === []) {
                    continue;
                }

                $interleaved[] = array_shift($groups[$index]);
                $movedAny = true;
            }

            if (!$movedAny) {
                break;
            }
        }

        return new Collection($interleaved);
    }

    /**
     * Plain-language explanation when the session is not exactly what was asked
     * for, so a short session never looks like a bug to the student.
     */
    protected function scopeNote(
        string $requestedScope,
        string $scopeUsed,
        bool $difficultyRelaxed,
        int $found,
        int $requested
    ): ?string {
        $notes = [];

        if ($requestedScope === 'missed' && $scopeUsed !== 'missed') {
            $notes[] = 'You have already worked through the questions built from the items you missed, so this session widens to related material.';
        } elseif ($scopeUsed !== $requestedScope) {
            $notes[] = match ($scopeUsed) {
                'topic' => 'There were not enough questions for that subtopic yet, so this session also draws from the wider topic.',
                'subject' => 'There were not enough questions for that topic yet, so this session also draws from the wider subject.',
                default => null,
            };
        }

        if ($difficultyRelaxed) {
            $notes[] = 'Some questions fall outside the difficulty you picked, because that level did not have enough published questions.';
        }

        if ($found < $requested) {
            $notes[] = sprintf(
                'This session has %d question%s instead of %d -- that is everything published for this area so far.',
                $found,
                $found === 1 ? '' : 's',
                $requested
            );
        }

        $notes = array_values(array_filter($notes));

        return $notes === [] ? null : implode(' ', $notes);
    }

    /**
     * Fold one answered item into the student's recall history. An unanswered
     * item counts as a miss so it stays in rotation rather than being treated
     * as learned.
     */
    protected function recordItemState(int $userId, int $questionId, bool $wasCorrect): void
    {
        $state = ReviewBotItemState::query()->firstOrNew([
            'user_id' => $userId,
            'question_bank_question_id' => $questionId,
        ]);

        $state->times_seen = (int) $state->times_seen + 1;
        $state->times_correct = (int) $state->times_correct + ($wasCorrect ? 1 : 0);
        $state->consecutive_correct = $wasCorrect ? (int) $state->consecutive_correct + 1 : 0;
        $state->last_seen_at = now();
        $state->save();
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

            $this->recordItemState((int) $attempt->user_id, (int) $question->id, $isCorrect);

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
