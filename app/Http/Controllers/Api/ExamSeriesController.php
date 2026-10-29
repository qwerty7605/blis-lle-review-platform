<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamSeries;
use App\Support\LleTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Exam series: the six subject exams that make up one pre-test or one mock
 * board. Every teacher and administrator sees every series and can put an
 * exam of theirs into one (the subjects are often written by different
 * teachers); its creator or an administrator can rename it, and delete it
 * while it is empty.
 */
class ExamSeriesController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $series = ExamSeries::query()
            ->with(['creator:id,name', 'exams:id,title,exam_series_id,series_subject,created_by'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExamSeries $series): array => $this->present($series, $user))
            ->values();

        return response()->json(['series' => $series]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('exam_series', 'name')],
            'type' => ['required', Rule::in(Exam::SERIES_TYPES)],
        ], [
            'name.unique' => 'A series with that name already exists.',
        ]);

        $series = ExamSeries::create([
            'name' => trim($validated['name']),
            'type' => $validated['type'],
            'created_by' => $user->id,
        ]);

        $this->recordAudit($user, 'exam.series.create', 'exam_series', $series->id, sprintf('Created the %s series "%s"', strtolower($series->typeLabel()), $series->name), [
            'name' => $series->name,
            'type' => $series->type,
        ], $request);

        return response()->json(['series' => $this->present($series->load(['creator:id,name', 'exams']), $user)], 201);
    }

    public function update(Request $request, ExamSeries $series)
    {
        $user = $request->user();

        if (!$this->canTeach($user) || !$this->oversees($user, $series->created_by)) {
            return response()->json(['message' => 'Only the teacher who created this series, or an administrator, can rename it.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('exam_series', 'name')->ignore($series->id)],
        ], [
            'name.unique' => 'A series with that name already exists.',
        ]);

        $before = $series->name;
        $series->update(['name' => trim($validated['name'])]);

        $this->recordAudit($user, 'exam.series.rename', 'exam_series', $series->id, sprintf('Renamed the series "%s" to "%s"', $before, $series->name), [
            'from' => $before,
            'to' => $series->name,
            'owner_id' => $series->created_by,
        ], $request);

        return response()->json(['series' => $this->present($series->load(['creator:id,name', 'exams']), $user)]);
    }

    public function destroy(Request $request, ExamSeries $series)
    {
        $user = $request->user();

        if (!$this->canTeach($user) || !$this->oversees($user, $series->created_by)) {
            return response()->json(['message' => 'Only the teacher who created this series, or an administrator, can delete it.'], 403);
        }

        if ($series->exams()->exists()) {
            return response()->json(['message' => 'Move its exams to another series, or change their type, before deleting this series.'], 422);
        }

        $this->recordAudit($user, 'exam.series.delete', 'exam_series', $series->id, sprintf('Deleted the series "%s"', $series->name), [
            'name' => $series->name,
            'type' => $series->type,
            'owner_id' => $series->created_by,
        ], $request);

        $series->delete();

        return response()->json(['message' => 'Series deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ExamSeries $series, $user): array
    {
        $exams = $series->exams
            ->sortBy(fn (Exam $exam): int => array_search($exam->series_subject, LleTaxonomy::keys(), true) ?: 0)
            ->map(fn (Exam $exam): array => [
                'id' => (int) $exam->id,
                'title' => $exam->title,
                'subject_key' => $exam->series_subject,
                'subject' => LleTaxonomy::subjectName((string) $exam->series_subject),
            ])
            ->values()
            ->all();

        return [
            'id' => (int) $series->id,
            'name' => $series->name,
            'type' => $series->type,
            'type_label' => $series->typeLabel(),
            'created_by' => $series->created_by,
            'creator' => $series->creator?->only(['id', 'name']),
            'exams' => $exams,
            'missing_subjects' => $series->missingSubjects($series->exams),
            'can_manage' => $this->oversees($user, $series->created_by),
        ];
    }
}
