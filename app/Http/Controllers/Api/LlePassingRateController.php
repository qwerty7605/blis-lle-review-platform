<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LlePassingRate;
use App\Services\LlePassingRateTrend;
use App\Services\LleResultRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Licensure page's passing-rate trend: LNU against the whole country,
 * year by year (LlePassingRateTrend), and the figures typed in for it.
 *
 * Teachers and administrators see it, and enter LNU's figures for the years
 * before BLIS kept its results; the national figures are an
 * administrator's to enter.
 */
class LlePassingRateController extends Controller
{
    public function __construct(
        private readonly LlePassingRateTrend $trend,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->canManageRooms($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->payload());
    }

    /**
     * Saving a scope and year already entered replaces those figures, as
     * recording a result again corrects it.
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (!$this->canManageRooms($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'scope' => ['required', Rule::in(LlePassingRate::SCOPES)],
            'exam_year' => ['required', 'integer', 'min:1900', 'max:2999'],
            'passers' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'takers' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            // Used only without the two counts: with them, it is worked out.
            'passing_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', 'max:255'],
        ], [
            'takers.min' => 'At least one person must have taken it.',
            'passing_rate.min' => 'A passing rate is between 0 and 100.',
            'passing_rate.max' => 'A passing rate is between 0 and 100.',
        ]);

        $scope = $validated['scope'];

        if ($scope === LlePassingRate::SCOPE_NATIONAL && !$this->isAdmin($actor)) {
            return response()->json(['message' => 'Only an administrator enters the national figures.'], 403);
        }

        $examYear = (int) $validated['exam_year'];
        $passers = isset($validated['passers']) ? (int) $validated['passers'] : null;
        $takers = isset($validated['takers']) ? (int) $validated['takers'] : null;
        $problems = $this->trend->figureProblems($examYear, $passers, $takers, $validated['passing_rate'] ?? null);

        if ($problems !== []) {
            return response()->json([
                'message' => reset($problems),
                'errors' => array_map(fn (string $problem): array => [$problem], $problems),
            ], 422);
        }

        $rate = $passers !== null && $takers !== null
            ? LlePassingRate::rateOf($passers, $takers)
            : round((float) $validated['passing_rate'], 2);

        $before = LlePassingRate::query()->where('scope', $scope)->where('exam_year', $examYear)->first();

        $figure = LlePassingRate::query()->updateOrCreate(
            ['scope' => $scope, 'exam_year' => $examYear],
            [
                'passers' => $passers,
                'takers' => $takers,
                'passing_rate' => $rate,
                'source' => filled($validated['source'] ?? null) ? trim((string) $validated['source']) : null,
                'recorded_by' => $actor->id,
            ],
        );

        $whose = $scope === LlePassingRate::SCOPE_NATIONAL ? 'the national' : "LNU's";

        $this->recordAudit(
            $actor,
            'lle-result.rate.record',
            null,
            null,
            sprintf('Entered %s LLE passing rate for %d', $whose, $examYear),
            [
                'scope' => $scope,
                'exam_year' => $examYear,
                'passers' => $passers,
                'takers' => $takers,
                'passing_rate' => $rate,
                'replaced' => $before === null ? null : [
                    'passers' => $before->passers,
                    'takers' => $before->takers,
                    'passing_rate' => (float) $before->passing_rate,
                ],
            ],
            $request,
        );

        return response()->json([
            'message' => sprintf('Saved %s figures for %d.', $whose, $examYear),
            ...$this->payload(),
        ], $figure->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, LlePassingRate $lleRate): JsonResponse
    {
        $actor = $request->user();

        if (!$this->canManageRooms($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($lleRate->scope === LlePassingRate::SCOPE_NATIONAL && !$this->isAdmin($actor)) {
            return response()->json(['message' => 'Only an administrator enters the national figures.'], 403);
        }

        $whose = $lleRate->scope === LlePassingRate::SCOPE_NATIONAL ? 'the national' : "LNU's";
        $examYear = (int) $lleRate->exam_year;
        $removed = [
            'scope' => $lleRate->scope,
            'exam_year' => $examYear,
            'passers' => $lleRate->passers,
            'takers' => $lleRate->takers,
            'passing_rate' => (float) $lleRate->passing_rate,
        ];

        $lleRate->delete();

        $this->recordAudit(
            $actor,
            'lle-result.rate.delete',
            null,
            null,
            sprintf('Removed %s LLE passing rate for %d', $whose, $examYear),
            $removed,
            $request,
        );

        return response()->json([
            'message' => sprintf('Removed %s figures for %d.', $whose, $examYear),
            ...$this->payload(),
        ]);
    }

    /** @return array{years: list<array<string, mixed>>, this_year: int} */
    private function payload(): array
    {
        return [
            'years' => $this->trend->years(),
            // The school's year, so the page refuses the same future years.
            'this_year' => LleResultRules::today()->year,
        ];
    }
}
