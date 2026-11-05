<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Services\ExamAttemptService;
use App\Services\Library\DocxQuestionParser;
use App\Services\QuestionMappingService;
use App\Support\LleTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use ZipArchive;

class LibraryController extends Controller
{
    public function __construct(
        private readonly QuestionMappingService $questionMappingService,
        private readonly ExamAttemptService $examAttemptService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // The tag counts are what the list's status is told from: a set whose
        // tagging has finished still has tags to confirm while any is pending.
        $query = QuestionBank::query()
            ->excludingReviewBotBanks()
            ->with('creator:id,name')
            ->withCount([
                'questions',
                'assessmentQuestions as pending_tags_count' => fn ($query) => $query
                    ->where('tag_status', QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW),
                'assessmentQuestions as confirmed_tags_count' => fn ($query) => $query
                    ->where('tag_status', QuestionBankQuestion::TAG_STATUS_CONFIRMED),
            ])
            ->when(!$this->oversees($user), fn ($query) => $query->where('created_by', $user->id))
            ->latest();

        return response()->json([
            'banks' => $query->get(),
        ]);
    }

    public function previewImport(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!$value instanceof \Illuminate\Http\UploadedFile) {
                        $fail('The uploaded file is invalid.');
                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());
                    if ($extension === 'doc') {
                        $fail('Legacy .doc files are not supported. Open the file in Word or Google Docs and save it as .docx, then try again.');
                        return;
                    }

                    if ($extension !== 'docx') {
                        $fail('Please upload a valid .docx file.');
                        return;
                    }

                    $realPath = $value->getRealPath();
                    if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
                        $fail('The uploaded .docx file could not be read. Please upload it again.');
                        return;
                    }

                    $zip = new ZipArchive();
                    if ($zip->open($realPath) !== true) {
                        $fail('The uploaded .docx file is invalid or corrupted. Save/export it again as .docx and try again.');
                        return;
                    }

                    $requiredEntries = [
                        '[Content_Types].xml',
                        '_rels/.rels',
                        'word/document.xml',
                    ];

                    foreach ($requiredEntries as $entry) {
                        if ($zip->locateName($entry) === false) {
                            $zip->close();
                            $fail('The uploaded .docx file is invalid or corrupted. Save/export it again as .docx and try again.');
                            return;
                        }
                    }

                    $zip->close();
                },
            ],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        try {
            $result = app(DocxQuestionParser::class)->parse($file->getRealPath());
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => 'Unable to parse DOCX file. Please check the file format.',
            ], 422);
        }

        if (count($result['questions']) === 0) {
            return response()->json([
                'message' => 'No valid question pattern detected from this DOCX file.',
                'preview' => [
                    'questions' => [],
                    'warnings' => $result['warnings'],
                    'answer_key_detected' => $result['answer_key_detected'],
                    'answer_key_items' => $result['answer_key_items'],
                    'total_items' => 0,
                    'source_filename' => $file->getClientOriginalName(),
                ],
            ], 422);
        }

        $this->recordAudit(
            $user,
            'library.import.preview',
            'docx',
            null,
            'Previewed DOCX question import',
            [
                'source_filename' => $file->getClientOriginalName(),
                'questions_detected' => count($result['questions']),
                'warnings' => count($result['warnings']),
                'answer_key_detected' => $result['answer_key_detected'],
                'answer_key_items' => $result['answer_key_items'],
            ],
            $request,
        );

        $suggestion = $this->questionMappingService->suggestSubjectForParsedQuestions($result['questions']);

        return response()->json([
            'message' => 'DOCX parsed successfully.',
            'preview' => [
                'questions' => $result['questions'],
                'warnings' => $result['warnings'],
                'answer_key_detected' => $result['answer_key_detected'],
                'answer_key_items' => $result['answer_key_items'],
                'total_items' => count($result['questions']),
                'source_filename' => $file->getClientOriginalName(),
                'suggested_subject' => $suggestion['subject'],
                'suggested_subject_confidence' => $suggestion['confidence'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'source_filename' => ['nullable', 'string', 'max:255'],
            'ai_tagging_mode' => ['nullable', Rule::in([QuestionBank::MAPPING_MODE_AUTO, QuestionBank::MAPPING_MODE_REVIEW])],
            'questions' => ['required', 'array', 'min:1', 'max:1000'],
            'questions.*.text' => ['required', 'string'],
            'questions.*.topic_name' => ['nullable', 'string', 'max:255'],
            'questions.*.question_type' => ['nullable', Rule::in(['multiple_choice', 'true_false', 'open_ended'])],
            'questions.*.answer_label' => ['nullable', 'string', 'max:2'],
            'questions.*.answer_text' => ['nullable', 'string'],
            'questions.*.options' => ['nullable', 'array', 'max:8'],
            'questions.*.options.*.label' => ['nullable', 'string', 'max:2'],
            'questions.*.options.*.text' => ['required_with:questions.*.options', 'string'],
            'questions.*.options.*.is_correct' => ['nullable', 'boolean'],
        ]);

        $taggingMode = $validated['ai_tagging_mode'] ?? QuestionBank::MAPPING_MODE_REVIEW;

        $questions = collect($validated['questions'])
            ->map(function (array $question, int $index): array {
                $resolved = $this->questionMappingService->resolveQuestionContent(
                    $question['options'] ?? [],
                    $question['answer_label'] ?? null,
                    $question['question_type'] ?? null,
                    $question['answer_text'] ?? null,
                );

                return [
                    'item_number' => $index + 1,
                    'text' => trim((string) ($question['text'] ?? '')),
                    'topic_name' => filled($question['topic_name'] ?? null) ? trim((string) $question['topic_name']) : null,
                    'question_type' => $resolved['question_type'],
                    'answer_label' => $resolved['answer_label'],
                    'answer_text' => $resolved['answer_text'],
                    'options' => $resolved['options'],
                ];
            })
            ->filter(fn (array $question): bool => $question['text'] !== '')
            ->values();

        if ($questions->isEmpty()) {
            return response()->json([
                'message' => 'At least one valid question is required.',
            ], 422);
        }

        $bank = DB::transaction(function () use ($validated, $questions, $user) {
            $bankSubject = $this->subjectToSave($validated['subject'] ?? null);

            $createdBank = QuestionBank::create([
                'title' => trim((string) $validated['title']),
                'subject' => $bankSubject,
                'source_filename' => filled($validated['source_filename'] ?? null)
                    ? trim((string) $validated['source_filename'])
                    : null,
                'total_items' => $questions->count(),
                'created_by' => $user->id,
            ]);

            foreach ($questions as $question) {
                // A teacher-entered topic always wins over the AI — it's resolved
                // immediately and never gets queued for AI classification.
                $manualTopic = $question['topic_name']
                    ? $this->questionMappingService->resolveTopic($bankSubject, $question['topic_name'], $user->id)
                    : null;

                $createdQuestion = $createdBank->questions()->create([
                    'item_number' => $question['item_number'],
                    'question_text' => $question['text'],
                    'subject' => $bankSubject,
                    'topic_id' => $manualTopic?->id,
                    'tag_status' => $manualTopic ? QuestionBankQuestion::TAG_STATUS_CONFIRMED : null,
                    'tag_source' => $manualTopic ? QuestionBankQuestion::TAG_SOURCE_FACULTY : null,
                    'question_type' => $question['question_type'],
                    'answer_label' => $question['answer_label'],
                    'answer_text' => $question['answer_text'],
                ]);

                foreach ($question['options'] as $option) {
                    $createdQuestion->options()->create([
                        'sort_order' => $option['sort_order'],
                        'option_label' => $option['label'],
                        'option_text' => $option['text'],
                        'is_correct' => $option['is_correct'],
                    ]);
                }
            }

            return $createdBank;
        });

        $mapping = $this->questionMappingService->queueBankMapping($bank, $taggingMode);

        $bank->load('creator:id,name')->loadCount(['questions']);

        $this->recordAudit(
            $user,
            'library.bank.create',
            'question_bank',
            $bank->id,
            'Question bank created from parsed DOCX',
            [
                'title' => $bank->title,
                'subject' => $bank->subject,
                'total_items' => $bank->total_items,
                'mapping_status' => $mapping['status'] ?? QuestionBank::MAPPING_STATUS_IDLE,
                'mapping_mode' => $taggingMode,
                'queued_mapping_count' => (int) ($mapping['queued'] ?? 0),
            ],
            $request,
        );

        return response()->json([
            'message' => 'Question bank saved. ' . ($mapping['message'] ?? 'AI item mapping status was updated.'),
            'bank' => $bank,
            'mapping_generation' => $mapping,
        ], 201);
    }

    public function update(
        Request $request,
            QuestionBank $bank,
    )
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $bank->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (Str::startsWith($bank->title, QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX)) {
            return response()->json(['message' => 'This is a Review Bot bank, not a Library set -- it cannot be edited here.'], 422);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        $newTitle = trim((string) $validated['title']);
        $newSubject = $this->subjectToSave($validated['subject'] ?? null);
        $subjectChanged = trim((string) $bank->subject) !== trim((string) $newSubject);
        $resetCount = 0;

        DB::transaction(function () use ($bank, $newTitle, $newSubject, $subjectChanged, &$resetCount) {
            $bank->title = $newTitle;
            $bank->subject = $newSubject;
            $bank->save();

            if (!$subjectChanged) {
                return;
            }

            // The question's own `subject` column (not the bank's) is what
            // mapping groups by -- see QuestionMappingService::classifyForAssessmentQuestions()
            // -- so it must stay in sync or reclassification would silently
            // keep using the old subject.
            $bank->assessmentQuestions()->update(['subject' => $newSubject]);

            // Only reset AI/rule-based guesses -- a teacher's own manually
            // confirmed tag (tag_source = faculty) is a deliberate human edit
            // and a subject correction shouldn't silently erase it.
            $resettableIds = $bank->assessmentQuestions()
                ->where(function ($query) {
                    $query->whereNull('tag_source')
                        ->orWhere('tag_source', '!=', QuestionBankQuestion::TAG_SOURCE_FACULTY);
                })
                ->pluck('id');

            $resetCount = $resettableIds->count();

            if ($resetCount > 0) {
                $bank->assessmentQuestions()
                    ->whereIn('id', $resettableIds)
                    ->update([
                        'topic_id' => null,
                        'subtopic_id' => null,
                        'difficulty' => null,
                        'cognitive_level' => null,
                        'tag_status' => null,
                        'tag_source' => null,
                    ]);
            }
        });

        $mapping = null;
        if ($subjectChanged && $resetCount > 0) {
            $mapping = $this->questionMappingService->queueBankMapping($bank, $bank->mapping_mode ?? QuestionBank::MAPPING_MODE_AUTO);
        }

        $bank->load('creator:id,name')->loadCount(['questions']);

        $this->recordAudit(
            $user,
            'library.bank.update',
            'question_bank',
            $bank->id,
            "Edited a question bank's title/subject",
            [
                'title' => $bank->title,
                'subject' => $bank->subject,
                'subject_changed' => $subjectChanged,
                'items_requeued' => $resetCount,
            ],
            $request,
        );

        return response()->json([
            'message' => $subjectChanged && $resetCount > 0
                ? sprintf('Question bank updated. %d item(s) queued for re-mapping under the new subject.', $resetCount)
                : 'Question bank updated.',
            'bank' => $bank,
            'mapping_generation' => $mapping,
        ]);
    }

    public function destroy(
        Request $request,
            QuestionBank $bank,
    )
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $bank->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Defense in depth: the Library listing already excludes these (see
        // scopeExcludingReviewBotBanks()), so the UI never surfaces a delete
        // button for one, but a direct request against a known bank id must
        // not be able to wipe out a subject's whole Review Bot bank through
        // the Library's delete flow -- that goes through the dedicated
        // per-question Review Bot admin delete instead.
        if (Str::startsWith($bank->title, QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX)) {
            return response()->json(['message' => 'This is a Review Bot bank, not a Library set -- it cannot be deleted here.'], 422);
        }

        $questionIdSubQuery = DB::table('question_bank_questions')
            ->select('id')
            ->where('question_bank_id', $bank->id);

        $hasAttemptQuestionUsage = DB::table('exam_attempt_questions')
            ->whereIn('question_bank_question_id', $questionIdSubQuery)
            ->exists();

        $hasAttemptAnswerUsage = DB::table('exam_attempt_answers')
            ->whereIn('question_bank_question_id', function ($query) use ($bank) {
                $query->select('id')
                    ->from('question_bank_questions')
                    ->where('question_bank_id', $bank->id);
            })
            ->exists();

        if ($hasAttemptQuestionUsage || $hasAttemptAnswerUsage) {
            return response()->json([
                'message' => 'Cannot delete this question bank because it already has exam attempt records.',
            ], 422);
        }

        $linkedExamCount = $bank->exams()->count();
        $bankTitle = $bank->title;

        $bank->delete();

        $this->recordAudit(
            $user,
            'library.bank.delete',
            'question_bank',
            $bank->id,
            'Question bank deleted',
            [
                'title' => $bankTitle,
                'linked_exams_count' => $linkedExamCount,
                'owner_id' => $bank->created_by,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Question bank deleted.',
        ]);
    }

    public function questions(
        Request $request,
            QuestionBank $bank,
    )
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $bank->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $bank->load('creator:id,name')->loadCount(['assessmentQuestions']);

        // The subtopic too: the edit form is filled from this list, and saving a
        // question with no subtopic in the form clears the one it had.
        $questions = $bank->assessmentQuestions()
            ->with([
                'topic:id,subject,name',
                'subtopic:id,topic_id,name',
                'options:id,question_bank_question_id,option_label,option_text,is_correct',
            ])
            ->orderBy('item_number')
            ->get();

        $this->recordAudit(
            $user,
            'library.bank.view_questions',
            'question_bank',
            $bank->id,
            'Viewed question bank questions',
            [
                'assessment_questions_count' => $questions->count(),
            ],
            $request,
        );

        return response()->json([
            'bank' => $bank,
            'questions' => $questions,
            'assessment_questions' => $questions,
        ]);
    }

    public function topics(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:255'],
        ]);

        $subject = trim((string) ($validated['subject'] ?? ''));

        if ($subject === '') {
            return response()->json(['curated_topics' => $this->questionMappingService->curatedTopics(), 'topics' => []]);
        }

        // The Table of Specifications is the only list: topics and
        // sub-topics in its order, each named by its code and name.
        $subject = LleTaxonomy::canonicalSubject($subject) ?? $subject;
        $curated = $this->questionMappingService->curatedTopicsForSubject($subject);

        $response = [
            'subject' => $subject,
            'curated_topics' => $curated,
            'topics' => $curated,
        ];

        $topic = trim((string) ($validated['topic'] ?? ''));

        if ($topic !== '') {
            $curatedSubtopics = $this->questionMappingService->curatedSubtopicsForTopic($subject, $topic);

            $response['curated_subtopics'] = $curatedSubtopics;
            $response['subtopics'] = $curatedSubtopics;
        }

        return response()->json($response);
    }

    public function queueMapping(
        Request $request,
            QuestionBank $bank,
    )
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $bank->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'mode' => ['nullable', Rule::in(QuestionBank::MAPPING_MODES)],
        ]);

        $mapping = $this->questionMappingService->queueBankMapping(
            $bank,
            (string) ($validated['mode'] ?? $bank->mapping_mode ?? QuestionBank::MAPPING_MODE_REVIEW),
        );

        $bank->load('creator:id,name')->loadCount(['questions']);

        $this->recordAudit(
            $user,
            'library.mapping.queue',
            'question_bank',
            $bank->id,
            'Queued AI item mapping for a set',
            [
                'mapping_status' => $mapping['status'] ?? QuestionBank::MAPPING_STATUS_IDLE,
                'queued_count' => (int) ($mapping['queued'] ?? 0),
            ],
            $request,
        );

        return response()->json([
            'message' => $mapping['message'] ?? 'AI item mapping status was updated.',
            'bank' => $bank,
            'mapping_generation' => $mapping,
        ]);
    }

    public function mappingQueue(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $questions = QuestionBankQuestion::query()
            ->with([
                'questionBank:id,title,subject,created_by,mapping_status',
                'topic:id,subject,name',
                'options:id,question_bank_question_id,option_label,option_text,is_correct',
            ])
            ->whereHas('questionBank', fn ($query) => $query->when(!$this->oversees($user), fn ($query) => $query->where('created_by', $user->id)))
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->where('tag_status', QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW)
            ->orderBy('question_bank_id')
            ->orderBy('item_number')
            ->get();

        return response()->json([
            'questions' => $questions,
        ]);
    }

    public function updateTagStatus(
        Request $request,
            QuestionBank $bank,
    )
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $bank->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $questions = QuestionBankQuestion::query()
            ->where('question_bank_id', $bank->id)
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->where('tag_status', QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW)
            ->get();

        foreach ($questions as $question) {
            $this->questionMappingService->updateTagStatus($question, QuestionBankQuestion::TAG_STATUS_CONFIRMED);
        }

        $this->recordAudit(
            $user,
            'library.mapping.bulk_confirm',
            'question_bank',
            $bank->id,
            'Bulk confirmed AI-suggested item tags',
            ['confirmed_count' => $questions->count()],
            $request,
        );

        return response()->json([
            'message' => $questions->count() > 0
                ? sprintf('Confirmed %d suggested tag(s) for this set.', $questions->count())
                : 'No pending tag suggestions were found for this set.',
            'confirmed_count' => $questions->count(),
        ]);
    }

    public function updateQuestion(
        Request $request,
            QuestionBankQuestion $question,
    )
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $question->loadMissing('questionBank:id,created_by,title,subject');

        if (!$this->oversees($user, $question->questionBank?->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'question_text' => ['required', 'string'],
            'question_type' => ['nullable', Rule::in(QuestionBankQuestion::TYPES)],
            'answer_label' => ['nullable', 'string', 'max:2'],
            'answer_text' => ['nullable', 'string'],
            'options' => ['nullable', 'array', 'max:8'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.label' => ['nullable', 'string', 'max:2'],
            'options.*.text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'topic_name' => ['nullable', 'string', 'max:255'],
            'subtopic_name' => ['nullable', 'string', 'max:255'],
            'difficulty' => ['nullable', Rule::in(QuestionBankQuestion::DIFFICULTIES)],
            'cognitive_level' => ['nullable', Rule::in(QuestionBankQuestion::COGNITIVE_LEVELS)],
        ]);

        $tagProblem = $this->questionMappingService->tagProblem(
            $question->subject,
            $validated['topic_name'] ?? null,
            $validated['subtopic_name'] ?? null,
        );

        if ($tagProblem !== null) {
            return response()->json(['message' => $tagProblem, 'errors' => ['topic_name' => [$tagProblem]]], 422);
        }

        // "Which option counts as correct" is resolved identically to bank
        // creation -- see QuestionMappingService::resolveQuestionContent().
        $resolved = $this->questionMappingService->resolveQuestionContent(
            $validated['options'] ?? [],
            $validated['answer_label'] ?? null,
            $validated['question_type'] ?? null,
            $validated['answer_text'] ?? null,
        );

        // Choices are edited where they are, by id: students' saved answers
        // point at them, and deleting and re-creating them (as this once did)
        // erased which choice every student had picked. A choice a student
        // picked in an exam is not removed at all.
        $saved = $question->options()->get(['id', 'option_label']);
        $keptIds = collect($resolved['options'])->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
        $removed = $saved->reject(fn ($option): bool => in_array((int) $option->id, $keptIds, true));
        $pickedLabels = $removed->isEmpty() ? collect() : $removed
            ->whereIn('id', DB::table('exam_attempt_answers')->whereIn('question_bank_option_id', $removed->pluck('id'))->distinct()->pluck('question_bank_option_id'))
            ->pluck('option_label');

        if ($pickedLabels->isNotEmpty()) {
            $problem = sprintf(
                'Students picked choice %s in an exam, so it cannot be removed. Edit its words instead.',
                $pickedLabels->implode(', '),
            );

            return response()->json(['message' => $problem, 'errors' => ['options' => [$problem]]], 422);
        }

        DB::transaction(function () use ($question, $validated, $resolved, $saved, $removed) {
            $question->forceFill([
                'question_text' => trim((string) $validated['question_text']),
                'question_type' => $resolved['question_type'],
                'answer_label' => $resolved['answer_label'],
                'answer_text' => $resolved['answer_text'],
            ])->save();

            $question->options()->whereIn('id', $removed->pluck('id'))->delete();

            $ownIds = $saved->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $rows = collect($resolved['options'])->values();

            // Labels are unique per question: kept choices step aside under a
            // placeholder first, so relettering (C becoming B) cannot collide.
            $rows->each(function (array $option, int $index) use ($question, $ownIds): void {
                if ($option['id'] && in_array($option['id'], $ownIds, true)) {
                    $question->options()->whereKey($option['id'])->update(['option_label' => '#' . ($index + 1)]);
                }
            });

            $rows->each(function (array $option) use ($question, $ownIds): void {
                $values = [
                    'sort_order' => $option['sort_order'],
                    'option_label' => $option['label'],
                    'option_text' => $option['text'],
                    'is_correct' => $option['is_correct'],
                ];

                if ($option['id'] && in_array($option['id'], $ownIds, true)) {
                    $question->options()->whereKey($option['id'])->update($values);
                } else {
                    $question->options()->create($values);
                }
            });
        });

        // A changed correct answer changes how students' answers are marked:
        // they are marked again, with each attempt's score.
        $regraded = $this->examAttemptService->regradeQuestion($question->fresh('options'));

        $question = $this->questionMappingService->applyManualTags(
            $question,
            $validated['topic_name'] ?? null,
            $validated['difficulty'] ?? null,
            $validated['cognitive_level'] ?? null,
            $user->id,
            $validated['subtopic_name'] ?? null,
        );

        $question->load([
            'topic:id,subject,name',
            'subtopic:id,topic_id,name',
            'options:id,question_bank_question_id,option_label,option_text,is_correct',
        ]);

        $this->recordAudit(
            $user,
            'library.question.edit',
            'question_bank_question',
            $question->id,
            "Edited a question's content and/or classification",
            [
                'question_bank_id' => $question->question_bank_id,
                'topic_id' => $question->topic_id,
                'subtopic_id' => $question->subtopic_id,
                'difficulty' => $question->difficulty,
                'cognitive_level' => $question->cognitive_level,
            ],
            $request,
        );

        if ($regraded['answers'] > 0) {
            $this->recordAudit(
                $user,
                'library.question.regrade',
                'question_bank_question',
                $question->id,
                sprintf(
                    'Re-graded %d %s in %d %s after the correct answer changed',
                    $regraded['answers'],
                    $regraded['answers'] === 1 ? 'answer' : 'answers',
                    $regraded['attempts'],
                    $regraded['attempts'] === 1 ? 'exam attempt' : 'exam attempts',
                ),
                [
                    'question_bank_id' => $question->question_bank_id,
                    'answer_label' => $question->answer_label,
                    'answers' => $regraded['answers'],
                    'attempt_ids' => $regraded['attempt_ids'],
                ],
                $request,
            );
        }

        return response()->json([
            'message' => $regraded['answers'] > 0
                ? sprintf(
                    'Question updated. The correct answer changed, so %d %s in %d %s %s re-graded.',
                    $regraded['answers'],
                    $regraded['answers'] === 1 ? 'answer' : 'answers',
                    $regraded['attempts'],
                    $regraded['attempts'] === 1 ? 'exam attempt' : 'exam attempts',
                    $regraded['answers'] === 1 ? 'was' : 'were',
                )
                : 'Question updated.',
            'question' => $question,
            'regraded' => $regraded['answers'],
        ]);
    }

    /**
     * A set's subject as it is saved: a board subject under its official
     * name whatever spelling was typed or detected ("Reference Services" is
     * saved as "Reference, Bibliography and User Services"), anything else as
     * typed.
     */
    private function subjectToSave(mixed $subject): ?string
    {
        $subject = trim((string) $subject);

        if ($subject === '') {
            return null;
        }

        return LleTaxonomy::canonicalSubject($subject) ?? $subject;
    }
}
