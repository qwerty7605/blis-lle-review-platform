@extends('reports.layout')
@php
    $exams = $gradebook['exams'] ?? [];
    $summary = $gradebook['summary'] ?? [];
    // The students' passing mark; once it is above 75 each cell shows the
    // grade (PassingMark) with the score under it.
    $mark = \App\Services\ReportExportService::markOf($gradebook['passing'] ?? null);
    $grades = $mark->converts();
    $staff = \App\Support\PassingMark::STAFF_MARK;
    $score = fn ($value) => $value === null ? '–' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $low = fn ($value) => $value !== null && !$mark->passes((float) $value);
@endphp

@section('title', 'Room Gradebook')
@section('kind', 'Gradebook')
@section('styles')
    table.legend td { border: 0; padding: 1px 12px 1px 0; vertical-align: top; }
    table.legend .code { font-weight: bold; color: #1a237e; width: 24px; }
    .low { color: #8a5a00; font-weight: bold; }
    .pct { display: block; font-size: 7px; font-weight: normal; color: #5f6577; }
@endsection

@section('content')
    <h1>{{ $gradebook['room']['name'] ?? 'Room' }} ({{ $gradebook['room']['code'] ?? '' }})</h1>
    <p class="subtitle">
        {{ $summary['students_total'] ?? 0 }} students · {{ $summary['exams_total'] ?? 0 }} exams ·
        average of all scores {{ $score($summary['average_score_percent'] ?? null) }}{{ ($summary['average_score_percent'] ?? null) === null ? '' : '%' }}
        @if ($grades && ($summary['average_score_percent'] ?? null) !== null)
            (grade {{ $score($mark->grade((float) $summary['average_score_percent'])) }})
        @endif
    </p>

    @if (!empty($exams))
        <table class="legend">
            @foreach (array_chunk($exams, 2) as $pair)
                <tr>
                    @foreach ($pair as $index => $exam)
                        <td class="code">E{{ $loop->parent->index * 2 + $index + 1 }}</td>
                        <td>{{ $exam['title'] }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif

    <table class="grid" style="margin-top: 10px">
        <thead>
            <tr>
                <th class="text">Student</th>
                <th style="width: 70px">Student ID</th>
                <th style="width: 44px">Exams Taken</th>
                <th style="width: 50px">{{ $grades ? 'Average' : 'Average %' }}</th>
                @foreach ($exams as $exam)
                    <th style="width: 40px">E{{ $loop->iteration }}</th>
                @endforeach
            </tr>
        </thead>
        @forelse (($gradebook['rows'] ?? []) as $row)
            <tr>
                <td class="text">{{ $row['student']['name'] ?? '' }}</td>
                <td>{{ $row['student']['student_id'] ?? '' }}</td>
                <td>{{ $row['summary']['completed_exams'] ?? 0 }}</td>
                @php $average = $row['summary']['average_score_percent'] ?? null; @endphp
                <td class="{{ $low($average) ? 'low' : '' }}">
                    @if ($grades && $average !== null)
                        <strong>{{ $score($mark->grade((float) $average)) }}</strong><span class="pct">{{ $score($average) }}%</span>
                    @else
                        <strong>{{ $score($average) }}</strong>
                    @endif
                </td>
                @foreach (($row['exam_entries'] ?? []) as $entry)
                    @php $value = $entry['score_percent'] ?? null; @endphp
                    <td class="{{ $low($value) ? 'low' : '' }}">
                        @if ($grades && $value !== null)
                            {{ $score($mark->grade((float) $value)) }}<span class="pct">{{ $score($value) }}%</span>
                        @else
                            {{ $score($value) }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ 4 + count($exams) }}" class="muted">This room has no current students.</td></tr>
        @endforelse
    </table>
    @if ($grades)
        <p class="note">Grades on LNU's review scale, where {{ $staff }} is passing, from each student's latest attempt; the score (percent right) is under each grade. Students are held to a passing mark of {{ $mark->mark }}%, so a score of {{ $mark->mark }}% is a grade of {{ $staff }}. – not submitted. Grades below {{ $staff }} are shown in amber.</p>
    @else
        <p class="note">Scores are percentages from each student's latest attempt. – not submitted. Scores below {{ $mark->mark }}% are shown in amber.</p>
    @endif
@endsection
