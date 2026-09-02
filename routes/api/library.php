<?php

use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Topic;
use App\Services\Library\DocxQuestionParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

Route::get('/library/banks', function (Request $request) use ($isStaffMasterExaminer, $ensureActive) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $query = QuestionBank::query()
        ->excludingReviewBotBanks()
        ->with('creator:id,name')
        ->withCount('questions')
        ->where('created_by', $user->id)
        ->latest();

    return response()->json([
        'banks' => $query->get(),
    ]);
});

Route::post('/library/import/preview', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'file' => [
            'required',
            'file',
            'max:20480',
            static function (string $attribute, mixed $value, \Closure $fail): void {
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

    $recordAudit(
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

    return response()->json([
        'message' => 'DOCX parsed successfully.',
        'preview' => [
            'questions' => $result['questions'],
            'warnings' => $result['warnings'],
            'answer_key_detected' => $result['answer_key_detected'],
            'answer_key_items' => $result['answer_key_items'],
            'total_items' => count($result['questions']),
            'source_filename' => $file->getClientOriginalName(),
        ],
    ]);
});

Route::post('/library/banks', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $recordAudit, $questionMappingService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
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
            $optionRows = collect($question['options'] ?? [])
                ->map(function (array $option, int $optionIndex): array {
                    $defaultLabel = chr(ord('A') + min($optionIndex, 25));

                    return [
                        'sort_order' => $optionIndex + 1,
                        'label' => Str::upper(trim((string) ($option['label'] ?? $defaultLabel))),
                        'text' => trim((string) ($option['text'] ?? '')),
                        'is_correct' => (bool) ($option['is_correct'] ?? false),
                    ];
                })
                ->filter(fn (array $option): bool => $option['text'] !== '')
                ->values();

            if ($optionRows->isNotEmpty()) {
                $answerLabel = Str::upper(trim((string) ($question['answer_label'] ?? '')));
                if ($answerLabel === '') {
                    $firstCorrectOption = $optionRows->first(fn (array $option): bool => $option['is_correct']);
                    $answerLabel = is_array($firstCorrectOption)
                        ? Str::upper(trim((string) ($firstCorrectOption['label'] ?? '')))
                        : '';
                }

                $optionRows = $optionRows->map(function (array $option) use ($answerLabel): array {
                    $option['is_correct'] = $answerLabel !== '' && $option['label'] === $answerLabel;

                    return $option;
                })->values();

                $resolvedCorrectOption = $optionRows->first(fn (array $option): bool => $option['is_correct']);
                $answerText = is_array($resolvedCorrectOption)
                    ? ($resolvedCorrectOption['text'] ?? null)
                    : null;
                $type = $question['question_type'] ?? 'multiple_choice';
                $type = in_array($type, ['multiple_choice', 'true_false', 'open_ended'], true) ? $type : 'multiple_choice';
            } else {
                $answerLabel = null;
                $answerText = $question['answer_text'] ?? null;
                $type = 'open_ended';
            }

            return [
                'item_number' => $index + 1,
                'text' => trim((string) ($question['text'] ?? '')),
                'topic_name' => filled($question['topic_name'] ?? null) ? trim((string) $question['topic_name']) : null,
                'question_type' => $type,
                'answer_label' => $answerLabel,
                'answer_text' => $answerText,
                'options' => $optionRows->all(),
            ];
        })
        ->filter(fn (array $question): bool => $question['text'] !== '')
        ->values();

    if ($questions->isEmpty()) {
        return response()->json([
            'message' => 'At least one valid question is required.',
        ], 422);
    }

    $bank = DB::transaction(function () use ($validated, $questions, $user, $questionMappingService) {
        $bankSubject = filled($validated['subject'] ?? null)
            ? trim((string) $validated['subject'])
            : null;

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
                ? $questionMappingService->resolveTopic($bankSubject, $question['topic_name'], $user->id)
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

    $mapping = $questionMappingService->queueBankMapping($bank, $taggingMode);

    $bank->load('creator:id,name')->loadCount(['questions']);

    $recordAudit(
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
});

Route::delete('/library/banks/{bank}', function (
    Request $request,
    QuestionBank $bank
) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $bank->created_by !== (int) $user->id) {
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

    $recordAudit(
        $user,
        'library.bank.delete',
        'question_bank',
        $bank->id,
        'Question bank deleted',
        [
            'title' => $bankTitle,
            'linked_exams_count' => $linkedExamCount,
        ],
        $request,
    );

    return response()->json([
        'message' => 'Question bank deleted.',
    ]);
});

Route::get('/library/banks/{bank}/questions', function (
    Request $request,
    QuestionBank $bank
) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $bank->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $bank->load('creator:id,name')->loadCount(['assessmentQuestions']);

    $questions = $bank->assessmentQuestions()
        ->with([
            'topic:id,subject,name',
            'options:id,question_bank_question_id,option_label,option_text,is_correct',
        ])
        ->orderBy('item_number')
        ->get();

    $recordAudit(
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
});

Route::get('/library/topics', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $questionMappingService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'subject' => ['nullable', 'string', 'max:255'],
    ]);

    $subject = trim((string) ($validated['subject'] ?? ''));

    if ($subject === '') {
        return response()->json(['curated_topics' => $questionMappingService->curatedTopics(), 'topics' => []]);
    }

    $existingTopicNames = Topic::query()
        ->where('subject', $subject)
        ->orderBy('name')
        ->pluck('name');

    $curated = $questionMappingService->curatedTopicsForSubject($subject);

    return response()->json([
        'subject' => $subject,
        'curated_topics' => $curated,
        'topics' => $existingTopicNames->concat($curated)->unique()->sort()->values(),
    ]);
});

Route::post('/library/banks/{bank}/queue-mapping', function (
    Request $request,
    QuestionBank $bank
) use (
    $isStaffMasterExaminer,
    $ensureActive,
    $recordAudit,
    $questionMappingService
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $bank->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'mode' => ['nullable', Rule::in(QuestionBank::MAPPING_MODES)],
    ]);

    $mapping = $questionMappingService->queueBankMapping(
        $bank,
        (string) ($validated['mode'] ?? $bank->mapping_mode ?? QuestionBank::MAPPING_MODE_REVIEW),
    );

    $bank->load('creator:id,name')->loadCount(['questions']);

    $recordAudit(
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
});

Route::get('/library/mapping-queue', function (
    Request $request
) use ($isStaffMasterExaminer, $ensureActive) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $questions = QuestionBankQuestion::query()
        ->with([
            'questionBank:id,title,subject,created_by,mapping_status',
            'topic:id,subject,name',
            'options:id,question_bank_question_id,option_label,option_text,is_correct',
        ])
        ->whereHas('questionBank', fn ($query) => $query->where('created_by', $user->id))
        ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
        ->where('tag_status', QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW)
        ->orderBy('question_bank_id')
        ->orderBy('item_number')
        ->get();

    return response()->json([
        'questions' => $questions,
    ]);
});

Route::patch('/library/banks/{bank}/tags/status', function (
    Request $request,
    QuestionBank $bank
) use (
    $isStaffMasterExaminer,
    $ensureActive,
    $recordAudit,
    $questionMappingService
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $bank->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $questions = QuestionBankQuestion::query()
        ->where('question_bank_id', $bank->id)
        ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
        ->where('tag_status', QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW)
        ->get();

    foreach ($questions as $question) {
        $questionMappingService->updateTagStatus($question, QuestionBankQuestion::TAG_STATUS_CONFIRMED);
    }

    $recordAudit(
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
});

Route::patch('/library/questions/{question}/tags', function (
    Request $request,
    QuestionBankQuestion $question
) use (
    $isStaffMasterExaminer,
    $ensureActive,
    $recordAudit,
    $questionMappingService
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $question->loadMissing('questionBank:id,created_by,title,subject');

    if ((int) ($question->questionBank?->created_by ?? 0) !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'topic_name' => ['nullable', 'string', 'max:255'],
        'difficulty' => ['nullable', Rule::in(QuestionBankQuestion::DIFFICULTIES)],
        'cognitive_level' => ['nullable', Rule::in(QuestionBankQuestion::COGNITIVE_LEVELS)],
    ]);

    $question = $questionMappingService->applyManualTags(
        $question,
        $validated['topic_name'] ?? null,
        $validated['difficulty'] ?? null,
        $validated['cognitive_level'] ?? null,
        $user->id,
    );

    $question->load('topic:id,subject,name');

    $recordAudit(
        $user,
        'library.mapping.edit',
        'question_bank_question',
        $question->id,
        'Edited/confirmed an item\'s topic, difficulty, or cognitive level',
        [
            'question_bank_id' => $question->question_bank_id,
            'topic_id' => $question->topic_id,
            'difficulty' => $question->difficulty,
            'cognitive_level' => $question->cognitive_level,
        ],
        $request,
    );

    return response()->json([
        'message' => 'Item tags updated.',
        'question' => $question,
    ]);
});
