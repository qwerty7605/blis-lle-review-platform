@extends('reports.layout')

@section('title', 'Answer Key')
@section('kind', 'Answer Key')
@section('styles')
    .item { padding: 6px 0 7px; border-bottom: 1px solid #e6e8f0; page-break-inside: avoid; }
    .item .question { margin: 0 0 3px; }
    .item .number { display: inline-block; width: 22px; font-weight: bold; color: #1a237e; }
    .choices { margin-left: 22px; }
    .choice { padding: 1px 4px; }
    .choice.is-correct { background: #e7f4ea; color: #1e6b34; font-weight: bold; }
    .written { margin-left: 22px; color: #1e6b34; font-weight: bold; }
@endsection

@section('content')
    <h1>{{ $exam['title'] ?? 'Exam' }}</h1>
    <p class="subtitle">
        {{ $exam['subject'] ?: 'No subject' }} · {{ count($answerKey) }} {{ count($answerKey) === 1 ? 'question' : 'questions' }}
        · the correct answer is marked ✓
    </p>

    @if (!empty($exam['shuffle_questions']) && count($answerKey) > (int) ($exam['total_items'] ?? 0) && (int) ($exam['total_items'] ?? 0) > 0)
        <p class="muted">This exam shuffles its questions: each student gets {{ $exam['total_items'] }} of these {{ count($answerKey) }}, in their own order. The numbers here are the Q numbers in the Class Results and Class Summary.</p>
    @elseif (!empty($exam['shuffle_questions']))
        <p class="muted">This exam shuffles its questions, so students saw them in a different order. The numbers here are the Q numbers in the Class Results and Class Summary.</p>
    @endif

    @if (empty($answerKey))
        <p class="muted">This exam has no question set yet, so there is no answer key.</p>
    @else
        @foreach ($answerKey as $item)
            <div class="item">
                <p class="question"><span class="number">{{ $item['item_number'] }}.</span>{{ $item['question_text'] }}</p>
                @if (!empty($item['options']))
                    <div class="choices">
                        @foreach ($item['options'] as $option)
                            <div class="choice {{ $option['is_correct'] ? 'is-correct' : '' }}">
                                {{ $option['label'] ? $option['label'] . '.' : '' }} {{ $option['text'] }}@if ($option['is_correct']) ✓@endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="written">Answer: {{ $item['correct_answer']['display'] ?? '–' }}</p>
                @endif
            </div>
        @endforeach
    @endif
@endsection
