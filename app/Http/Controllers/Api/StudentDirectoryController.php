<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MachineLearning\BatteryEarlyEstimate;
use App\Services\StudentAnalyticsService;
use App\Services\StudentDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentDirectoryController extends Controller
{
    public function __construct(
        private readonly StudentAnalyticsService $studentAnalytics,
        private readonly StudentDirectoryService $directory,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'year_level' => ['nullable', 'integer'],
            'student_status' => ['nullable', Rule::in(User::STUDENT_STATUSES)],
            'admission_year' => ['nullable', 'integer', 'min:1900', 'max:2999'],
            'archive_state' => ['nullable', Rule::in(['current', 'archived', 'all'])],
            // Unknown sort keys fall back to name rather than erroring -- the
            // whitelist lives in StudentDirectoryService::DIRECTORY_SORTS, which
            // is also what stops the value reaching ORDER BY unchecked.
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->directory->paginateStudentDirectory($validated));
    }

    /**
     * A student's profile for staff and administrators: who they are, plus the
     * same analytics the student sees on their own Analytics page.
     */
    public function analytics(Request $request, User $student)
    {
        if (!$this->canManageRooms($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Only student accounts have a profile; a teacher's or an admin's id here
        // is treated as not found rather than answered with an empty one.
        if ($this->canManageRooms($student)) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $rooms = $student->rooms()
            ->select('rooms.id', 'rooms.name', 'rooms.code')
            ->orderBy('rooms.name')
            ->get()
            ->map(fn ($room) => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ])
            ->values();

        return response()->json([
            'student' => [
                'id' => (int) $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'student_id' => $student->student_id,
                'year_level' => $student->year_level,
                'student_status' => $student->student_status,
                'admission_year' => $student->admission_year,
                'is_active' => (bool) $student->is_active,
                'archived_at' => $student->archived_at,
                'rooms' => $rooms,
            ],
            ...$this->studentAnalytics->build($student),
            // Staff only for now; it shows only while the model in use is
            // ready for battery exams (BatteryEarlyEstimate).
            'early_estimate' => app(BatteryEarlyEstimate::class)->for($student),
        ]);
    }
}
