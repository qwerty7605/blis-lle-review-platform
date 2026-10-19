<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SchoolYearEndService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class YearEndController extends Controller
{
    /**
     * Closing a school year, in two requests: read what it would do, then
     * send back the students an admin actually confirmed. See
     * SchoolYearEndService for why it is a proposal and not a scheduled job.
     */
    public function preview(Request $request, SchoolYearEndService $schoolYearEnd)
    {
        if (!$this->isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($schoolYearEnd->plan());

    }

    public function apply(Request $request, SchoolYearEndService $schoolYearEnd)
    {
        $actor = $request->user();
        if (!$this->isAdmin($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'students' => ['required', 'array', 'min:1', 'max:5000'],
            'students.*.id' => ['required', 'integer', 'distinct'],
            'students.*.action' => ['required', Rule::in(SchoolYearEndService::ACTIONS)],
            // The level the admin was looking at when they confirmed. A record
            // that has since moved on is skipped, not changed a second time.
            'students.*.year_level' => ['required', 'integer', 'min:' . User::YEAR_LEVEL_MIN, 'max:' . User::YEAR_LEVEL_MAX],
        ]);

        $result = $schoolYearEnd->apply($actor, $validated['students'], $request);

        return response()->json([
            'message' => sprintf(
                'School year closed: %d graduated, %d moved up.',
                $result['graduated'],
                $result['advanced'],
            ),
            ...$result,
        ]);

    }
}
