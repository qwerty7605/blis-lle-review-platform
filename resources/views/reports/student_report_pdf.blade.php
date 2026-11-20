@extends('reports.layout')
@php
    use App\Support\Reports\ReportOutput;
    $attempt = $report['attempt'] ?? [];
    $showAnswers = $showAnswers ?? true;
    $submitted = ($attempt['status'] ?? null) === 'submitted';
    $passing = (int) ($attempt['passing_score'] ?? 75);
    // The teacher's copy shows the grade once the students' mark is above 75
    // (PassingMark); the student's copy reads their score against their mark.
    $forStudent = $forStudent ?? false;
    $mark = \App\Services\ReportExportService::markOf($attempt['passing'] ?? null);
    $grades = !$forStudent && $mark->converts();
    $staff = \App\Support\PassingMark::STAFF_MARK;
    $number = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $hasScore = $submitted && !is_null($attempt['score_percent'] ?? null);
    $marks = [
        'Correct' => ['✓', 'mark-correct'],
        'Wrong' => ['✗', 'mark-wrong'],
        'No Answer' => ['–', 'mark-blank'],
        'Pending Review' => ['?', 'mark-pending'],
    ];
@endphp

@section('title', 'Student Report')
@section('kind', 'Student Report')
@section('styles')
    table.score td { border: 1px solid #d7dbe7; padding: 10px 12px; vertical-align: middle; }
    table.score .big { font-size: 24px; font-weight: bold; color: #1a237e; }
    table.score .verdict { font-size: 13px; }
@endsection

@section('content')
    <h1>{{ $report['student']['name'] ?? 'Student' }}</h1>
    <p class="subtitle">Student ID {{ $report['student']['student_id'] ?: '–' }}</p>

    <table class="facts">
        <tr>
            <td class="label">Exam</td><td class="value">{{ $report['exam']['title'] ?? '' }}</td>
            <td class="label">Room</td><td class="value">{{ $report['room']['name'] ?? '' }} ({{ $report['room']['code'] ?? '' }})</td>
        </tr>
        <tr>
            <td class="label">Submitted</td><td class="value">{{ ReportOutput::time($attempt['submitted_at'] ?? null) ?? 'Not yet submitted' }}</td>
            <td class="label">Attempt</td><td class="value">{{ $attempt['attempt_number'] ?? 1 }} of {{ $attempt['attempts_total'] ?? 1 }}</td>
        </tr>
    </table>

    <table class="score" style="margin-top: 10px">
        <tr>
            <td style="width: 32%">
                @if ($grades && $hasScore)
                    <span class="big">Grade {{ $number($mark->grade((float) $attempt['score_percent'])) }}</span>
                @else
                    <span class="big">{{ $hasScore ? $number($attempt['score_percent']) . '%' : '–' }}</span>
                @endif
            </td>
            <td>
                <span class="verdict">
                    @if ($grades && $hasScore)
                        Score {{ $number($attempt['score_percent']) }}% ·
                    @endif
                    {{ $attempt['correct_answers'] ?? 0 }} of {{ $attempt['total_items'] ?? 0 }} correct · {{ $attempt['answered_count'] ?? 0 }} answered
                </span><br>
                @if ($submitted)
                    @if ($grades)
                        @if ($attempt['passed'] ?? false)
                            <span class="pass">Passed</span> <span class="muted">(grade {{ $staff }}, a score of {{ $mark->mark }}%)</span>
                        @else
                            <span class="below">Below passing: grade {{ $staff }} is a score of {{ $mark->mark }}%</span>
                        @endif
                    @elseif ($attempt['passed'] ?? false)
                        <span class="pass">Passed</span> <span class="muted">(pass mark {{ $passing }}%)</span>
                    @else
                        <span class="below">Below the {{ $passing }}% pass mark</span>
                    @endif
                @else
                    <span class="muted">This attempt is still in progress.</span>
                @endif
            </td>
        </tr>
    </table>

    <h2>Answers</h2>
    @unless ($showAnswers)
        <p class="muted">Your teacher has kept the correct answers for this exam hidden, so this list shows your answers only.</p>
    @endunless
    @if (empty($report['items'] ?? []))
        <p class="muted">No questions were recorded for this attempt.</p>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th style="width: 22px">#</th>
                    <th class="text">Question</th>
                    <th class="text" style="width: 140px">Answer Given</th>
                    @if ($showAnswers)
                        <th class="text" style="width: 140px">Correct Answer</th>
                        <th style="width: 30px">Mark</th>
                    @endif
                </tr>
            </thead>
            @foreach ($report['items'] as $item)
                <tr>
                    <td>{{ $item['item_number'] }}</td>
                    <td class="text">{{ $item['question_text'] ?? '' }}</td>
                    <td class="text">{{ $item['selected_answer'] ?? '–' }}</td>
                    @if ($showAnswers)
                        <td class="text">{{ $item['correct_answer']['display'] ?? '–' }}</td>
                        @php $mark = $marks[$item['status_label'] ?? ''] ?? ['', '']; @endphp
                        <td><span class="mark {{ $mark[1] }}">{{ $mark[0] }}</span></td>
                    @endif
                </tr>
            @endforeach
        </table>
        @if ($showAnswers)
            <p class="note">✓ right · ✗ wrong · – left blank · ? a written answer not marked yet. Questions are in the order this student saw them.</p>
        @endif
    @endif
@endsection
