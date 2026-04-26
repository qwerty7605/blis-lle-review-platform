<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { margin: 0 0 8px; font-size: 18px; }
        p.meta { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 5px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>Room Gradebook</h1>

    <p class="meta"><strong>Room:</strong> {{ $gradebook['room']['name'] ?? 'N/A' }} ({{ $gradebook['room']['code'] ?? 'N/A' }})</p>
    <p class="meta"><strong>Students:</strong> {{ $gradebook['summary']['students_total'] ?? 0 }}</p>
    <p class="meta"><strong>Exams:</strong> {{ $gradebook['summary']['exams_total'] ?? 0 }}</p>
    <p class="meta"><strong>Average Score %:</strong> {{ is_null($gradebook['summary']['average_score_percent'] ?? null) ? 'N/A' : number_format((float) $gradebook['summary']['average_score_percent'], 2) }}</p>
    <p class="meta"><strong>Generated:</strong> {{ optional($gradebook['generated_at'] ?? null)->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s') }}</p>

    <table>
        <thead>
            <tr>
                <th>Student Name</th>
                <th>Student ID</th>
                <th>Email</th>
                <th>Completed Exams</th>
                <th>Average %</th>
                @foreach (($gradebook['exams'] ?? []) as $exam)
                    <th>{{ $exam['title'] ?? 'Exam' }} (%)</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse (($gradebook['rows'] ?? []) as $row)
                <tr>
                    <td>{{ $row['student']['name'] ?? '' }}</td>
                    <td>{{ $row['student']['student_id'] ?? '' }}</td>
                    <td>{{ $row['student']['email'] ?? '' }}</td>
                    <td>{{ $row['summary']['completed_exams'] ?? 0 }}</td>
                    <td>
                        {{ is_null($row['summary']['average_score_percent'] ?? null) ? 'N/A' : number_format((float) $row['summary']['average_score_percent'], 2) }}
                    </td>
                    @foreach (($row['exam_entries'] ?? []) as $entry)
                        <td>
                            {{ is_null($entry['score_percent'] ?? null) ? 'N/A' : number_format((float) $entry['score_percent'], 2) }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 5 + count($gradebook['exams'] ?? []) }}" class="muted">No enrolled students were found for this room.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
