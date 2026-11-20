<?php

namespace App\Services;

use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\ReviewBotAttemptAnswer;
use App\Models\ReviewBotAttemptQuestion;
use App\Models\ReviewBotItemState;
use App\Models\User;
use App\Support\LleTaxonomy;
use App\Support\PassingMark;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Student-facing Review Bot: serves questions from the teacher-curated
 * Review Bot Question Bank (QuestionBankQuestion rows with
 * usage_type = review_bot and status = published). Never calls OpenAI
 * itself -- when a session's scope comes up short it queues a background
 * top-up job (see ReviewBotAdminService::queueTopUp()) so the bank fills
 * in for next time, rather than making the student wait on a live call.
 *
 * A student only ever sees questions built from exam items they were dealt
 * in an exam that is finished for them (ReviewBotEligibility), in the
 * catalog and in every session.
 */
class ReviewBotService
{
    /**
     * A practice score under this is still a weak spot; at or above the other
     * the student moves on. On the teachers' scale (PassingMark::scoreFor()):
     * the second is the students' passing mark itself.
     */
    private const WEAK_SCORE_THRESHOLD = 60.0;
    private const STRONG_SCORE_THRESHOLD = 75.0;

    /**
     * Static copy for the subject picker (description/focus areas only --
     * actual question inventory always comes from the database), keyed by
     * subject key; the names come from the Table of Specifications.
     *
     * @var array<string, array{description: string, focus_areas: array<int, string>}>
     */
    private const SUBJECT_PROFILES = [
        'cataloging' => [
            'description' => 'Describing, classifying and organizing library materials: AACR2, subject headings, DDC and LC call numbers, and MARC.',
            'focus_areas' => ['Descriptive Cataloging', 'Subject Cataloging', 'Classification'],
        ],
        'indexing' => [
            'description' => 'Writing abstracts, building indexes and thesauri, and judging retrieval by recall and precision.',
            'focus_areas' => ['Abstracting', 'Indexing', 'Automated Abstracting and Indexing'],
        ],
        'it' => [
            'description' => 'Information storage and retrieval, library systems and databases, networks, and the issues technology raises.',
            'focus_areas' => ['Computer Applications in Library Operations', 'Computer Technologies', 'Issues in Information Technology'],
        ],
        'management' => [
            'description' => 'Management theory, library administration, personnel and budget, and the laws on Philippine librarianship.',
            'focus_areas' => ['Major Administrative Concerns', 'Administration of the Library', 'Laws, Related Practices, and Trends'],
        ],
        'reference' => [
            'description' => 'The nature and functions of reference service, reference sources, the reference interview and information services.',
            'focus_areas' => ['Reference and Information Sources', 'Reference and Information Services', 'Functions of Reference Service'],
        ],
        'selection' => [
            'description' => 'Collection development principles, the acquisition process, collection evaluation and policy, and preservation.',
            'focus_areas' => ['Selection of Multi-media Information Sources', 'Acquisition Process', 'Evaluation of Collection'],
        ],
    ];

    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
        private readonly ReviewBotAdminService $reviewBotAdminService,
        private readonly ReviewBotEligibility $eligibility,
    ) {
    }

    /**
     * Subject/topic/subtopic counts for the student's Review Bot landing page:
     * only the questions this student may practise, so the picker only ever
     * offers combinations that have content for them.
     *
     * Counted in SQL. This used to hydrate every published Review Bot question
     * as an Eloquent model with its topic and subtopic relations attached,
     * purely to group them and call count() -- on a bank that only ever grows,
     * for a page every student opens. The grouped result is bounded by the
     * taxonomy (a few dozen rows) rather than by how many questions exist.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(User $student): array
    {
        $sourceIds = $this->eligibility->sourceIds($student);

        $counts = DB::table('question_bank_questions as q')
            ->leftJoin('topics as t', 't.id', '=', 'q.topic_id')
            ->leftJoin('subtopics as st', 'st.id', '=', 'q.subtopic_id')
            ->where('q.usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('q.status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->tap(fn ($query) => $this->eligibility->constrain($query, $sourceIds, 'q.source_question_id'))
            ->groupBy('q.subject', 'q.topic_id', 't.name', 'q.subtopic_id', 'st.name')
            ->selectRaw('q.subject, q.topic_id, t.name as topic_name, q.subtopic_id, st.name as subtopic_name, COUNT(*) as question_count')
            ->get();

        $difficulties = DB::table('question_bank_questions')
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->tap(fn ($query) => $this->eligibility->constrain($query, $sourceIds, 'source_question_id'))
            ->whereNotNull('difficulty')
            ->where('difficulty', '!=', '')
            ->distinct()
            ->get(['subject', 'difficulty'])
            ->groupBy(fn ($row) => $this->normalizeSubjectLabel($row->subject))
            ->map(fn (Collection $rows) => $rows->pluck('difficulty')->unique()->values()->all());

        $bySubject = $counts->groupBy(fn ($row) => $this->normalizeSubjectLabel($row->subject));

        return collect(self::SUBJECT_PROFILES)
            ->map(function (array $profile, string $key) use ($bySubject, $difficulties): array {
                $subject = (string) LleTaxonomy::subjectName($key);
                $rows = $bySubject->get($subject, collect());

                // Rows with no topic, or "Other / Unclassified", still count
                // toward the subject total, but are not listed under a topic
                // heading: Other is not a topic to practise.
                $topics = $rows
                    ->filter(fn ($row) => $row->topic_id !== null && $row->topic_name !== null && !LleTaxonomy::isOther($row->topic_name))
                    ->groupBy('topic_id')
                    ->map(function (Collection $topicRows): array {
                        $first = $topicRows->first();

                        $subtopics = $topicRows
                            ->filter(fn ($row) => $row->subtopic_id !== null && $row->subtopic_name !== null)
                            ->map(fn ($row): array => [
                                'id' => (int) $row->subtopic_id,
                                'name' => (string) $row->subtopic_name,
                                'question_count' => (int) $row->question_count,
                            ])
                            ->values()
                            // Names start with their TOS code: "1.2" before "1.10".
                            ->sortBy('name', SORT_NATURAL)
                            ->values()
                            ->all();

                        return [
                            'id' => (int) $first->topic_id,
                            'name' => (string) $first->topic_name,
                            'question_count' => (int) $topicRows->sum('question_count'),
                            'subtopics' => $subtopics,
                        ];
                    })
                    ->values()
                    ->sortBy('name', SORT_NATURAL)
                    ->values()
                    ->all();

                $questionCount = (int) $rows->sum('question_count');

                return [
                    'subject' => $subject,
                    'description' => $profile['description'],
                    'focus_areas' => $profile['focus_areas'],
                    'question_count' => $questionCount,
                    'bot_ready' => $questionCount > 0,
                    'topics' => $topics,
                    'difficulties' => $difficulties->get($subject, []),
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
            throw new RuntimeException(ReviewBotEligibility::EMPTY_MESSAGE);
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

        // Every scope only ever holds questions this student may practise.
        $sourceIds = $this->eligibility->sourceIds($student);
        $scopes = array_map(
            fn (callable $scope): callable => fn ($query) => $scope($this->eligibility->constrain($query, $sourceIds)),
            $scopes,
        );

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

        // A thin bank should fix itself over time rather than staying thin
        // forever. Widening past subtopic/topic, relaxing difficulty, or simply
        // coming up short are all signs this exact area needs more content --
        // queue a background top-up rather than making this student wait on a
        // live call. The 'missed' scope widening on its own doesn't count: that
        // just means the student has already worked through everything built
        // from their wrong answers, which is success, not scarcity.
        $isShort = $picked->count() < $questionCount
            || $difficultyRelaxed
            || ($requestedScope !== 'missed' && $scopeUsed !== $requestedScope);

        if ($isShort) {
            // Only from items this student may practise: variants of anything
            // else would never reach them.
            $this->reviewBotAdminService->queueTopUp($subject, $topicId, $subtopicId, $sourceIds);
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
    /**
     * Fold a whole session's outcomes into review_bot_item_states in two
     * queries rather than two per question.
     *
     * The existing rows are locked before they are read, because every column
     * here is derived from its own current value. Two sessions finishing at the
     * same moment would otherwise both read the same starting count and one
     * increment would be lost -- and these counters are what the adaptive
     * queue's weak/learning/mastered bucketing reads.
     *
     * @param  array<int, bool>  $outcomes  question id => answered correctly
     */
    protected function recordItemStates(int $userId, array $outcomes): void
    {
        if ($outcomes === []) {
            return;
        }

        $existing = ReviewBotItemState::query()
            ->where('user_id', $userId)
            ->whereIn('question_bank_question_id', array_keys($outcomes))
            ->lockForUpdate()
            ->get()
            ->keyBy('question_bank_question_id');

        $now = now();
        $rows = [];

        foreach ($outcomes as $questionId => $wasCorrect) {
            $state = $existing->get($questionId);

            $rows[] = [
                'user_id' => $userId,
                'question_bank_question_id' => $questionId,
                'times_seen' => (int) ($state->times_seen ?? 0) + 1,
                'times_correct' => (int) ($state->times_correct ?? 0) + ($wasCorrect ? 1 : 0),
                // A wrong answer resets the streak outright: the bucket query
                // reads consecutive_correct, not the correct/seen ratio.
                'consecutive_correct' => $wasCorrect ? (int) ($state->consecutive_correct ?? 0) + 1 : 0,
                'last_seen_at' => $now,
                'created_at' => $state->created_at ?? $now,
                'updated_at' => $now,
            ];
        }

        ReviewBotItemState::query()->upsert(
            $rows,
            ['user_id', 'question_bank_question_id'],
            ['times_seen', 'times_correct', 'consecutive_correct', 'last_seen_at', 'updated_at'],
        );
    }

    /**
     * @param  array<int, array{question_id: int, option_id?: int|null, answer_text?: string|null}>  $answers
     * @return array<string, mixed>
     */
    /**
     * Grade and close a review session.
     *
     * The whole thing runs in one transaction. Grading writes an answer row and
     * a mastery-state row per question, and the mastery row is an increment
     * rather than an assignment -- so a failure partway through used to leave
     * the attempt unsubmitted (meaning the guard below would not stop a retry)
     * while the questions already processed kept their bumped times_seen and
     * consecutive_correct. A second run then counted them again, promoting
     * items into the "mastered" bucket on evidence the student never produced.
     */
    public function submitSession(ReviewBotAttempt $attempt, array $answers): array
    {
        return DB::transaction(function () use ($attempt, $answers): array {
            // Lock the row before trusting its status, so two submissions of the
            // same session racing each other cannot both pass this check.
            $locked = ReviewBotAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new RuntimeException('This review session no longer exists.');
            }

            if ($locked->status === ReviewBotAttempt::STATUS_SUBMITTED) {
                throw new RuntimeException('This review session was already submitted.');
            }

            return $this->gradeSession($attempt, $answers);
        });
    }

    /**
     * @param  array<int, array{question_id: int, option_id?: int|null, answer_text?: string|null}>  $answers
     * @return array<string, mixed>
     */
    protected function gradeSession(ReviewBotAttempt $attempt, array $answers): array
    {
        $attempt->loadMissing(['attemptQuestions.question.options', 'topic:id,name', 'subtopic:id,name']);

        $answersByQuestionId = collect($answers)->keyBy(fn (array $answer) => (int) ($answer['question_id'] ?? 0));

        $correctCount = 0;
        $answeredCount = 0;
        $missedItems = [];
        $answerRows = [];
        $outcomes = [];
        $now = now();

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

            $answerRows[] = [
                'review_bot_attempt_id' => (int) $attempt->id,
                'question_bank_question_id' => (int) $question->id,
                'question_bank_option_id' => $selectedOption?->id,
                'answer_text' => $answerText !== '' ? $answerText : null,
                'is_correct' => $isCorrect,
                'answered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $outcomes[(int) $question->id] = $isCorrect;

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

        // Two statements for the whole session, where this used to be four
        // queries per question.
        if ($answerRows !== []) {
            ReviewBotAttemptAnswer::query()->upsert(
                $answerRows,
                ['review_bot_attempt_id', 'question_bank_question_id'],
                ['question_bank_option_id', 'answer_text', 'is_correct', 'answered_at', 'updated_at'],
            );
        }

        $this->recordItemStates((int) $attempt->user_id, $outcomes);

        $totalItems = $attempt->attemptQuestions->count();
        $scorePercent = $totalItems > 0 ? round(($correctCount / $totalItems) * 100, 2) : 0.0;

        $attempt->forceFill([
            'status' => ReviewBotAttempt::STATUS_SUBMITTED,
            'answered_count' => $answeredCount,
            'correct_answers' => $correctCount,
            'score_percent' => $scorePercent,
            'submitted_at' => $now,
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
            // The mark the page colours this score against.
            'passing_mark' => PassingMark::current()->mark,
        ];
    }

    /**
     * @return array{action: string, message: string, next_focus: array<string, mixed>|null}
     */
    protected function buildRecommendation(ReviewBotAttempt $attempt, float $scorePercent): array
    {
        $mark = PassingMark::current();

        if ($scorePercent < $mark->scoreFor(self::WEAK_SCORE_THRESHOLD)) {
            return [
                'action' => 'repeat_topic',
                'message' => 'This is still a weak spot. Review the explanations above, then try this same topic again.',
                'next_focus' => null,
            ];
        }

        if ($scorePercent >= $mark->scoreFor(self::STRONG_SCORE_THRESHOLD)) {
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

    /**
     * A board subject's official name for any of its spellings; anything
     * else title-cased, and an empty subject "General".
     */
    protected function normalizeSubjectLabel(mixed $subject): string
    {
        $raw = trim((string) $subject);

        if ($raw === '') {
            return 'General';
        }

        return LleTaxonomy::canonicalSubject($raw) ?? Str::title(LleTaxonomy::normalize($raw));
    }
}
