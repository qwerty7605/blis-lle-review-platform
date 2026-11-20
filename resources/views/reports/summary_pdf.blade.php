@extends('reports.layout')
@php
    use App\Support\Reports\ReportOutput;
    $summary = $session['summary'] ?? [];
    // The students' passing mark. Once it is above 75 this teacher's summary
    // reads in grades on LNU's review scale (PassingMark), the score beside.
    $mark = \App\Services\ReportExportService::markOf($summary['passing'] ?? null);
    $grades = $mark->converts();
    $staff = \App\Support\PassingMark::STAFF_MARK;
    $passing = $grades ? $staff : $mark->mark;
    $number = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $pct = fn ($value) => $value === null ? '–' : $number($value) . '%';
    // A figure as the teacher reads it: the grade, or the score when nothing converts.
    $figure = fn ($value) => $value === null ? '–' : ($grades ? $number($mark->grade((float) $value)) : $pct($value));
    $submittedRows = collect($session['rows'] ?? [])->filter(fn ($row) => ($row['attempt']['status'] ?? null) === 'submitted');
    $scores = $submittedRows->map(fn ($row) => (float) ($row['attempt']['score_percent'] ?? 0));
    // Banded on the teacher's scale: by grade once grades show.
    $values = $grades ? $scores->map(fn ($s) => (float) $mark->grade($s)) : $scores;
    $unit = $grades ? '' : '%';
    $bands = [
        "90–100{$unit}" => $values->filter(fn ($s) => $s >= 90)->count(),
        "{$passing}–89{$unit}" => $values->filter(fn ($s) => $s >= $passing && $s < 90)->count(),
        "50–" . ($passing - 1) . $unit => $values->filter(fn ($s) => $s >= 50 && $s < $passing)->count(),
        "Below 50{$unit}" => $values->filter(fn ($s) => $s < 50)->count(),
    ];
    $classSize = max(1, $scores->count());
    $notSubmitted = collect($session['rows'] ?? [])->filter(fn ($row) => ($row['attempt']['status'] ?? null) !== 'submitted');
    $hardest = collect($session['hardest_items'] ?? [])->filter(fn ($item) => ($item['correct_percent'] ?? 100) < 76)->values();
@endphp

@section('title', 'Class Summary')
@section('kind', 'Class Summary')
@section('styles')
    table.bands td { border: 0; padding: 3px 0; }
    table.bands td.band { width: 70px; color: #5f6577; white-space: nowrap; }
    table.bands td.track { padding-right: 10px; }
    table.bands td.count { width: 110px; text-align: right; font-weight: bold; white-space: nowrap; }
    table.bands .share { font-weight: normal; color: #5f6577; }
    .key { display: inline-block; width: 9px; height: 9px; margin: 0 3px 0 10px; vertical-align: -1px; }
    .key-pass { background: #3949ab; }
    .key-below { background: #c98a1e; }
    .rail { height: 11px; background: #eceef6; }
    .bar { height: 11px; background: #3949ab; }
    .bar.below { background: #c98a1e; }
@endsection

@section('content')
    <h1>{{ $session['exam']['title'] ?? 'Exam' }}</h1>
    <p class="subtitle">How the class did on this exam: each student's latest attempt counts.</p>

    <table class="facts">
        <tr>
            <td class="label">Room</td><td class="value">{{ $session['room']['name'] ?? '' }} ({{ $session['room']['code'] ?? '' }})</td>
            <td class="label">Subject</td><td class="value">{{ $session['exam']['subject'] ?: '–' }}</td>
        </tr>
        <tr>
            <td class="label">Questions</td><td class="value">{{ $session['exam']['total_items'] ?? 0 }} per student{{ !empty($session['exam']['shuffle_questions']) ? ', shuffled' : '' }}</td>
            <td class="label">Pass mark</td><td class="value">{{ $grades ? "Grade {$staff} (a score of {$mark->mark}%)" : "{$passing}%" }}</td>
        </tr>
    </table>

    <table class="stats">
        <tr>
            <td><span class="figure">{{ $summary['students_total'] ?? 0 }}</span><span class="caption">Students in room</span></td>
            <td><span class="figure">{{ $summary['attempts_submitted'] ?? 0 }}</span><span class="caption">Submitted</span></td>
            <td><span class="figure">{{ $figure($summary['average_score_percent'] ?? null) }}</span><span class="caption">{{ $grades ? 'Average grade (' . $pct($summary['average_score_percent'] ?? null) . ')' : 'Average score' }}</span></td>
            <td><span class="figure">{{ $pct($summary['pass_rate_percent'] ?? null) }}</span><span class="caption">Passed ({{ $summary['passed_count'] ?? 0 }} of {{ $summary['attempts_submitted'] ?? 0 }})</span></td>
            <td><span class="figure">{{ $figure($summary['highest_score_percent'] ?? null) }}</span><span class="caption">{{ $grades ? 'Highest (' . $pct($summary['highest_score_percent'] ?? null) . ')' : 'Highest' }}</span></td>
            <td><span class="figure">{{ $figure($summary['lowest_score_percent'] ?? null) }}</span><span class="caption">{{ $grades ? 'Lowest (' . $pct($summary['lowest_score_percent'] ?? null) . ')' : 'Lowest' }}</span></td>
        </tr>
    </table>

    @if ($scores->isNotEmpty())
        <h2>{{ $grades ? 'Grade Breakdown' : 'Score Breakdown' }}</h2>
        <p class="muted">
            The share of the class {{ $grades ? 'with a grade' : 'that scored' }} in each range. A full line is the whole class ({{ $scores->count() }} {{ $scores->count() === 1 ? 'student' : 'students' }} who submitted).
            @if ($grades)
                Grades are on LNU's review scale, where {{ $staff }} is passing: students are held to {{ $mark->mark }}%, so a score of {{ $mark->mark }}% is a grade of {{ $staff }}.
            @endif
            <br>
            <span class="key key-pass" style="margin-left: 0"></span> {{ $passing }}{{ $unit }} and above (passed)
            <span class="key key-below"></span> below {{ $passing }}{{ $unit }}
        </p>
        <table class="bands">
            @foreach ($bands as $label => $count)
                <tr>
                    <td class="band">{{ $label }}</td>
                    <td class="track"><div class="rail"><div class="bar {{ $loop->index >= 2 ? 'below' : '' }}" style="width: {{ $count > 0 ? max(1, round($count / $classSize * 100)) : 0 }}%"></div></div></td>
                    <td class="count">{{ round($count / $classSize * 100) }}% <span class="share">({{ $count }} {{ $count === 1 ? 'student' : 'students' }})</span></td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($hardest->isNotEmpty())
        <h2>Questions to Review</h2>
        <p class="muted">The questions the fewest students got right. Q numbers match the Answer Key.</p>
        <table class="grid">
            <thead><tr><th style="width: 26px">Q</th><th class="text">Question</th><th style="width: 52px">% Correct</th><th class="text" style="width: 150px">Most Chosen Wrong Answer</th></tr></thead>
            @foreach ($hardest as $item)
                <tr>
                    <td>{{ $item['item_number'] }}</td>
                    <td class="text">{{ $item['question_text'] ?? '' }}</td>
                    <td>{{ $pct($item['correct_percent']) }}</td>
                    <td class="text">{{ $item['common_wrong_answer'] ? $item['common_wrong_answer'] . ' (' . $item['common_wrong_count'] . ')' : '–' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Every Question</h2>
    @if (empty($session['item_summary']))
        <p class="muted">This exam has no questions yet.</p>
    @else
        <table class="grid">
            <thead><tr><th style="width: 26px">Q</th><th class="text">Question</th><th class="text" style="width: 130px">Correct Answer</th><th style="width: 52px">% Correct</th><th style="width: 54px">Difficulty</th></tr></thead>
            @foreach ($session['item_summary'] as $item)
                <tr>
                    <td>{{ $item['item_number'] }}</td>
                    <td class="text">{{ $item['question_text'] ?? '' }}</td>
                    <td class="text">{{ $item['correct_answer'] ?? '–' }}</td>
                    <td>{{ $item['started_count'] > 0 ? $pct($item['correct_percent']) : '–' }}</td>
                    <td>@if ($item['difficulty'])<span class="tag tag-{{ strtolower($item['difficulty']) }}">{{ $item['difficulty'] }}</span>@else – @endif</td>
                </tr>
            @endforeach
        </table>
        <p class="note">% Correct is out of everyone who was given the question; a blank counts as wrong. Easy: 76% or more got it right. Hard: 25% or fewer.</p>
    @endif

    @if ($notSubmitted->isNotEmpty())
        <h2>Not Submitted ({{ $notSubmitted->count() }})</h2>
        <p>
            @foreach ($notSubmitted as $row)
                {{ $row['student']['name'] }}{{ ($row['attempt'] ?? null) ? ' (in progress)' : '' }}@if (!$loop->last), @endif
            @endforeach
        </p>
    @endif

    @if (!empty($session['short_answer_dump']))
        <h2>Written Answers</h2>
        @foreach ($session['short_answer_dump'] as $item)
            <p><strong>Q{{ $item['item_number'] }}.</strong> {{ $item['question_text'] }}</p>
            <table class="grid">
                <thead><tr><th class="text" style="width: 150px">Student</th><th class="text">Answer</th><th style="width: 70px">Marked</th></tr></thead>
                @foreach (($item['responses'] ?? []) as $response)
                    <tr>
                        <td class="text">{{ $response['student_name'] ?? '' }}</td>
                        <td class="text">{{ $response['answer_text'] ?? '' }}</td>
                        <td>{{ match ($response['is_correct'] ?? null) { true => 'Correct', false => 'Wrong', default => 'Not yet' } }}</td>
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endif
@endsection
