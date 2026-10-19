<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\LleResult;
use App\Models\User;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LleResultController extends Controller
{
    public function index(Request $request)
    {
        if (!$this->canManageRooms($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'exam_year' => ['nullable', 'integer', 'min:1900', 'max:2999'],
            'result' => ['nullable', Rule::in(LleResult::RESULTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $results = LleResult::query()
            ->with(['student:id,name,student_id,email,year_level', 'recorder:id,name'])
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
            return response()->json(['batches' => $batches, 'students' => []]);
        }

        $students = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('admission_year', (int) $validated['admission_year'])
            ->orderBy('name')
            ->get(['id', 'name', 'student_id', 'year_level', 'student_status', 'archived_at']);

        // What is already on record for that sitting, to show and to correct.
        $recorded = filled($validated['exam_year'] ?? null)
            ? LleResult::query()
                ->whereIn('user_id', $students->pluck('id'))
                ->where('exam_year', (int) $validated['exam_year'])
                ->get(['user_id', 'result', 'rating'])
                ->keyBy('user_id')
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
            ]),
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
            // Only the students given a result: one left blank did not sit
            // the exam, or is not on the list yet, and is not sent at all.
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

        // All of the list or none of it: half a batch saved would leave
        // nobody sure which half.
        $created = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($entries, $validated, $actor, &$created): void {
            foreach ($entries as $entry) {
                $result = LleResult::query()->updateOrCreate(
                    ['user_id' => (int) $entry['user_id'], 'exam_year' => (int) $validated['exam_year']],
                    [
                        'taken_at' => $validated['taken_at'] ?? null,
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
}
