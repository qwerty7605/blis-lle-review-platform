<?php

namespace App\Console\Commands;

use App\Models\ExamAttempt;
use App\Services\AuditLogService;
use App\Services\ExamAttemptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Submits every exam attempt whose deadline has passed.
 *
 * The routes also submit an expired attempt when its student next opens it,
 * but a student who closed the tab and never came back would otherwise stay
 * "in progress" for good, with no score in the teacher's reports. Runs every
 * minute from the scheduler.
 */
class SubmitExpiredAttemptsCommand extends Command
{
    protected $signature = 'exams:submit-expired';

    protected $description = 'Submit exam attempts whose time has run out';

    public function handle(ExamAttemptService $attempts, AuditLogService $audit): int
    {
        $submitted = 0;

        ExamAttempt::query()
            ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($attempts, $audit, &$submitted): void {
                DB::transaction(function () use ($id, $attempts, $audit, &$submitted): void {
                    // Locked and read again: the student may be submitting it
                    // themselves at this very moment.
                    $attempt = ExamAttempt::query()->lockForUpdate()->find($id);

                    if (!$attempt || $attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
                        return;
                    }

                    $attempt = $attempts->autoSubmitExpiredAttempt($attempt);

                    $audit->record(
                        null,
                        'exam.attempt.auto_submit',
                        'exam_attempt',
                        $attempt->id,
                        'Time ran out; the exam attempt was submitted automatically',
                        [
                            'student_id' => $attempt->user_id,
                            'exam_id' => $attempt->exam_id,
                            'room_id' => $attempt->room_id,
                            'score_percent' => $attempt->score_percent,
                            'answered_count' => $attempt->answered_count,
                            'total_items' => $attempt->total_items,
                        ],
                    );

                    $submitted++;
                });
            });

        $this->info(sprintf('Submitted %d expired attempt(s).', $submitted));

        return self::SUCCESS;
    }
}
