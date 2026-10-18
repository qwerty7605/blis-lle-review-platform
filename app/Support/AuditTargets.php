<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Feedback;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Names what each audit entry was done to ("Exam: Cataloging Midterm"), so
 * the page shows a name instead of a table name and an id. One query per
 * kind of target on the page, not one per row. A target deleted since keeps
 * the name the entry saved in its details, when it saved one.
 */
final class AuditTargets
{
    private const TYPE_LABELS = [
        'user' => 'User',
        'exam' => 'Exam',
        'exam_attempt' => 'Exam attempt',
        'room' => 'Room',
        'question_bank' => 'Question set',
        'question_bank_question' => 'Question',
        'docx' => 'Word file',
        'practice_attempt' => 'Practice set',
        'review_bot_attempt' => 'Review Bot session',
        'feedback' => 'Feedback',
        'system_settings' => 'System settings',
        'school_year' => 'School year',
    ];

    /** Kinds whose current name is read from their own table. */
    private const LOOKED_UP = ['user', 'exam', 'room', 'question_bank', 'question_bank_question', 'exam_attempt', 'review_bot_attempt', 'feedback'];

    public static function typeLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        return self::TYPE_LABELS[$type] ?? Str::ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, array{type: ?string, type_label: ?string, id: ?int, name: ?string, deleted: bool}>
     */
    public static function describe(Collection $logs): array
    {
        $idsByType = $logs
            ->filter(fn (AuditLog $log) => $log->target_type && $log->target_id)
            ->groupBy('target_type')
            ->map(fn (Collection $group) => $group->pluck('target_id')->unique()->values()->all());

        $names = [];
        foreach ($idsByType as $type => $ids) {
            $names[$type] = self::namesFor((string) $type, $ids);
        }

        $described = [];
        foreach ($logs as $log) {
            $type = $log->target_type ?: null;
            $id = $log->target_id ? (int) $log->target_id : null;
            $name = $id !== null ? ($names[$type][$id] ?? null) : null;
            $deleted = $id !== null && $name === null && in_array($type, self::LOOKED_UP, true);

            $name ??= self::nameFromDetails($type, (array) ($log->metadata ?? []));

            $described[$log->id] = [
                'type' => $type,
                'type_label' => self::typeLabel($type),
                'id' => $id,
                'name' => $name,
                'deleted' => $deleted,
            ];
        }

        return $described;
    }

    /** @return array<int, string> */
    private static function namesFor(string $type, array $ids): array
    {
        return match ($type) {
            'user' => User::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'exam' => Exam::query()->whereIn('id', $ids)->pluck('title', 'id')->all(),
            'room' => Room::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'question_bank' => QuestionBank::query()->whereIn('id', $ids)->pluck('title', 'id')->all(),
            'question_bank_question' => QuestionBankQuestion::query()
                ->whereIn('id', $ids)
                ->with('questionBank:id,title')
                ->get(['id', 'question_bank_id', 'item_number'])
                ->mapWithKeys(fn (QuestionBankQuestion $question) => [
                    $question->id => trim(
                        ($question->item_number ? "Item {$question->item_number}" : 'A question')
                        .($question->questionBank ? " in {$question->questionBank->title}" : '')
                    ),
                ])
                ->all(),
            'exam_attempt' => ExamAttempt::query()
                ->whereIn('id', $ids)
                ->with(['exam:id,title', 'student:id,name'])
                ->get(['id', 'exam_id', 'user_id'])
                ->mapWithKeys(fn (ExamAttempt $attempt) => [
                    $attempt->id => collect([$attempt->student?->name, $attempt->exam?->title])->filter()->implode(' · '),
                ])
                ->all(),
            'review_bot_attempt' => ReviewBotAttempt::query()
                ->whereIn('id', $ids)
                ->with('student:id,name')
                ->get(['id', 'user_id', 'subject'])
                ->mapWithKeys(fn (ReviewBotAttempt $attempt) => [
                    $attempt->id => collect([$attempt->student?->name, $attempt->subject])->filter()->implode(' · '),
                ])
                ->all(),
            'feedback' => Feedback::query()
                ->whereIn('id', $ids)
                ->with('user:id,name')
                ->get(['id', 'user_id', 'type'])
                ->mapWithKeys(fn (Feedback $feedback) => [
                    $feedback->id => collect([Feedback::typeLabel($feedback->type), $feedback->user?->name])->filter()->implode(' from '),
                ])
                ->all(),
            default => [],
        };
    }

    private static function nameFromDetails(?string $type, array $metadata): ?string
    {
        $name = match ($type) {
            'docx' => $metadata['source_filename'] ?? null,
            'system_settings' => 'System settings',
            'practice_attempt' => $metadata['subject'] ?? null,
            default => $metadata['title'] ?? $metadata['name'] ?? $metadata['exam_title'] ?? $metadata['email'] ?? null,
        };

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
