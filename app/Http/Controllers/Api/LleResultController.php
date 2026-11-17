<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\LleResult;
use App\Models\User;
use App\Services\LleResultRules;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What a result may say (a pass needs the passing average, nothing comes
 * after a pass, no sitting in the future) is LleResultRules; both ways of
 * recording go through it before anything is saved.
 */
class LleResultController extends Controller
{
    public function __construct(
        private readonly LleResultRules $rules,
    ) {
    }

    public function index(Request $request)
    {
        if (!$this->canManageRooms($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            // One student's results: the One Student form checks a new
            // result against the others.
            'user_id' => ['nullable', 'integer'],
            'exam_year' => ['nullable', 'integer', 'min:1900', 'max:2999'],
            'result' => ['nullable', Rule::in(LleResult::RESULTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $results = LleResult::query()
            ->with(['student:id,name,student_id,email,year_level', 'recorder:id,name'])
            ->when(
                filled($validated['user_id'] ?? null),
                fn ($query) => $query->where('user_id', (int) $validated['user_id']),
            )
            ->when(
                filled($validated['exam_year'] ?? null),
                fn ($query) => $query->where('exam_year', (int) $validated['exam_year']),
            )
            ->when(
                filled($validated['result'] ?? null),
                fn ($query) => $query->where('result', $validated['result']),
            )
            ->when(
                filled($validated['search'] ?? null),
                fn ($query) => $query->whereHas('student', fn ($student) => Search::whereAnyContains(
                    $student,
                    ['name', 'student_id', 'email'],
                    trim((string) $validated['search']),
                )),
            )
            ->orderByDesc('exam_year')
            ->orderBy('id')
            ->paginate(
                (int) ($validated['per_page'] ?? 30),
                ['*'],
                'page',
                (int) ($validated['page'] ?? 1),
            );

        return response()->json([
            'results' => $results->items(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'from' => $results->firstItem() ?? 0,
                'to' => $results->lastItem() ?? 0,
            ],
            // So the page checks against the same mark the server does.
            'passing_averages' => LleResultRules::PASSING_AVERAGES,
        ]);

    }

    public function store(Request $request)
    {
        $actor = $request->user();

        if (!$this->canManageRooms($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'exam_year' => ['required', 'integer', 'min:1900', 'max:2999'],
            'taken_at' => ['nullable', 'date'],
            'result' => ['required', Rule::in(LleResult::RESULTS)],
            // The PRC general average. Nullable because a pass/fail is often
            // all that is published or retained -- a missing rating must not
            // be stored as a zero.
            'rating' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = User::query()->find($validated['user_id']);

        if (!$student || $student->role !== User::ROLE_STUDENT) {
            return response()->json([
                'message' => 'Licensure results can only be recorded against student accounts.',
            ], 422);
        }

        $examYear = (int) $validated['exam_year'];
        $problems = $this->rules->sittingProblems($examYear, $validated['taken_at'] ?? null)
            + $this->rules->notTakenDateProblems($validated['result'], $validated['taken_at'] ?? null)
            + $this->rules->resultProblems(
                (string) $student->name,
                $examYear,
                $validated['result'],
                $validated['rating'] ?? null,
                $this->rules->otherSittings([(int) $student->id], $examYear)->get($student->id, collect()),
            );

        if ($problems !== []) {
            return $this->refused($problems);
        }

        // Re-recording the same sitting corrects it rather than duplicating --
        // transcription from a published list is exactly where typos happen.
        $result = LleResult::query()->updateOrCreate(
            [
                'user_id' => $student->id,
                'exam_year' => $validated['exam_year'],
            ],
            [
                'taken_at' => $validated['taken_at'] ?? null,
                'result' => $validated['result'],
                'rating' => $validated['rating'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'recorded_by' => $actor->id,
            ],
        );

        $this->recordAudit(
            $actor,
            'lle-result.record',
            'user',
            $student->id,
            'Recorded a licensure examination result',
            [
                'exam_year' => $result->exam_year,
                'result' => $result->result,
                'rating' => $result->rating,
            ],
            $request,
        );

        return response()->json([
            'result' => $result->load(['student:id,name,student_id,email,year_level', 'recorder:id,name']),
        ], $result->wasRecentlyCreated ? 201 : 200);

    }

    /**
     * Recording a whole batch at once. A batch is an admission year: the
     * students who entered together sit the board exam together, and the PRC
     * publishes their results as one list. Looking each student up to record
     * one result at a time was the slow part of transcribing that list.
     *
     * GET answers the batches there are and, for one of them, its students
     * with whatever is already recorded for the exam year, so the page can
     * show a roster to fill in.
     */
    public function batch(Request $request)
    {
        if (!$this->canManageRooms($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'admission_year' => ['nullable', 'integer', 'min:1900', 'max:2999'],
            'exam_year' => ['nullable', 'integer', 'min:1900', 'max:2999'],
        ]);

        $batches = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->whereNotNull('admission_year')
            ->groupBy('admission_year')
            ->orderByDesc('admission_year')
            ->selectRaw('admission_year, COUNT(*) as students_count')
            ->get()
            ->map(fn ($row) => [
                'admission_year' => (int) $row->admission_year,
                'students_count' => (int) $row->students_count,
            ]);

        if (!filled($validated['admission_year'] ?? null)) {
            return response()->json([
                'batches' => $batches,
                'students' => [],
                'passing_averages' => LleResultRules::PASSING_AVERAGES,
            ]);
        }

        $students = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('admission_year', (int) $validated['admission_year'])
            ->orderBy('name')
            ->get(['id', 'name', 'student_id', 'year_level', 'student_status', 'archived_at']);

        // What is already on record for that sitting, to show and to correct.
        $examYear = filled($validated['exam_year'] ?? null) ? (int) $validated['exam_year'] : null;
        $recorded = $examYear !== null
            ? LleResult::query()
                ->whereIn('user_id', $students->pluck('id'))
                ->where('exam_year', $examYear)
                ->get(['user_id', 'result', 'rating'])
                ->keyBy('user_id')
            : collect();

        // And their other sittings, which this one must agree with: a batch
        // opened for a later year still shows who passed the year before,
        // instead of listing them blank (and "Mark the Rest as Failed"
        // failing them again).
        $others = $examYear !== null
            ? $this->rules->otherSittings($students->pluck('id')->map(fn ($id): int => (int) $id)->all(), $examYear)
            : collect();

        return response()->json([
            'batches' => $batches,
            'students' => $students->map(fn (User $student) => [
                'id' => $student->id,
                'name' => $student->name,
                'student_id' => $student->student_id,
                'year_level' => $student->year_level,
                'student_status' => $student->student_status,
                'archived_at' => $student->archived_at,
                'result' => $recorded->get($student->id)?->result,
                'rating' => $recorded->get($student->id)?->rating,
                'other_results' => $others->get($student->id, collect())
                    ->map(fn (LleResult $sitting): array => [
                        'exam_year' => (int) $sitting->exam_year,
                        'result' => $sitting->result,
                    ])
                    ->values(),
            ]),
            'passing_averages' => LleResultRules::PASSING_AVERAGES,
        ]);

    }

    public function storeBatch(Request $request)
    {
        $actor = $request->user();

        if (!$this->canManageRooms($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'exam_year' => ['required', 'integer', 'min:1900', 'max:2999'],
            'taken_at' => ['nullable', 'date'],
            // Only the students given a result (passed, failed or did not
            // take): one left blank is not on the list yet, and is not sent.
            'entries' => ['required', 'array', 'min:1', 'max:1000'],
            'entries.*.user_id' => ['required', 'integer', 'distinct'],
            'entries.*.result' => ['required', Rule::in(LleResult::RESULTS)],
            'entries.*.rating' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $entries = collect($validated['entries']);

        $studentIds = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->whereIn('id', $entries->pluck('user_id'))
            ->pluck('id');

        if ($studentIds->count() !== $entries->count()) {
            return response()->json([
                'message' => 'Licensure results can only be recorded against student accounts.',
            ], 422);
        }

        // Checked before anything is saved: one entry that cannot be right
        // stops the whole list, as any other problem does.
        $examYear = (int) $validated['exam_year'];
        $problems = $this->rules->sittingProblems($examYear, $validated['taken_at'] ?? null);
        $names = User::query()->whereIn('id', $studentIds)->pluck('name', 'id');
        $others = $this->rules->otherSittings($studentIds->map(fn ($id): int => (int) $id)->all(), $examYear);

        foreach ($entries as $index => $entry) {
            $userId = (int) $entry['user_id'];
            $name = (string) $names->get($userId, 'A student');
            $entryProblems = $this->rules->resultProblems(
                $name,
                $examYear,
                $entry['result'],
                $entry['rating'] ?? null,
                $others->get($userId, collect()),
            );

            foreach ($entryProblems as $field => $problem) {
                // The page names the student before a rating problem too.
                $problems["entries.{$index}.{$field}"] = $field === 'rating' ? "{$name}: {$problem}" : $problem;
            }
        }

        if ($problems !== []) {
            return $this->refused($problems);
        }

        // All of the list or none of it: half a batch saved would leave
        // nobody sure which half.
        $created = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($entries, $validated, $actor, &$created): void {
            foreach ($entries as $entry) {
                $result = LleResult::query()->updateOrCreate(
                    ['user_id' => (int) $entry['user_id'], 'exam_year' => (int) $validated['exam_year']],
                    [
                        // The list's date is the sitting's: nobody who did not
                        // take the exam took it then.
                        'taken_at' => $entry['result'] === LleResult::RESULT_DID_NOT_TAKE ? null : ($validated['taken_at'] ?? null),
                        'result' => $entry['result'],
                        'rating' => $entry['rating'] ?? null,
                        'recorded_by' => $actor->id,
                    ],
                );

                if ($result->wasRecentlyCreated) {
                    $created++;
                }
            }
        });

        $this->recordAudit(
            $actor,
            'lle-result.record-batch',
            null,
            null,
            sprintf('Recorded licensure examination results for %d student(s)', $entries->count()),
            [
                'exam_year' => (int) $validated['exam_year'],
                'recorded' => $entries->count(),
                'new' => $created,
                'corrected' => $entries->count() - $created,
                'passed' => $entries->where('result', LleResult::RESULT_PASS)->count(),
                'failed' => $entries->where('result', LleResult::RESULT_FAIL)->count(),
                'did_not_take' => $entries->where('result', LleResult::RESULT_DID_NOT_TAKE)->count(),
            ],
            $request,
        );

        return response()->json([
            'message' => sprintf('Recorded results for %d student(s).', $entries->count()),
            'recorded' => $entries->count(),
            'new' => $created,
            'corrected' => $entries->count() - $created,
        ]);

    }

    public function destroy(Request $request, LleResult $lleResult)
    {
        $actor = $request->user();

        if (!$this->canManageRooms($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $studentId = (int) $lleResult->user_id;
        $examYear = (int) $lleResult->exam_year;

        $lleResult->delete();

        $this->recordAudit(
            $actor,
            'lle-result.delete',
            'user',
            $studentId,
            'Removed a recorded licensure examination result',
            ['exam_year' => $examYear],
            $request,
        );

        return response()->json(['message' => 'Result removed.']);

    }

    /**
     * A 422 shaped like Laravel's own, so the page puts each reason under
     * the field it names.
     *
     * @param  array<string, string>  $problems  field => reason
     */
    private function refused(array $problems): JsonResponse
    {
        return response()->json([
            'message' => reset($problems),
            'errors' => array_map(fn (string $problem): array => [$problem], $problems),
        ], 422);
    }
}
