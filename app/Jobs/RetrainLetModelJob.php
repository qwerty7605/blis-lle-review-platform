<?php

namespace App\Jobs;

use App\Models\LleResult;
use App\Services\AuditLogService;
use App\Services\MachineLearning\LetModelTrainer;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;

/**
 * Retrains the LET readiness model after licensure results change, which is
 * what makes the model learn from each batch without anyone asking it to.
 *
 * A batch's results are typed in one student at a time, so this is queued on
 * every save but only one copy ever waits, and it holds off until the typing
 * has stopped. Retraining on the first few names of a list would judge a new
 * model on a handful of results.
 */
class RetrainLetModelJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** No result saved for this long means the list has been finished. */
    private const QUIET_MINUTES = 10;

    public int $timeout = 300;

    /** Long enough to outlast an afternoon of data entry. */
    public int $uniqueFor = 21600;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(LetModelTrainer $trainer, AuditLogService $audit): void
    {
        if ($this->resultsAreStillBeingEntered()) {
            $this->release(120);

            return;
        }

        $result = $trainer->run();

        if ($result['model'] !== null) {
            $audit->record(
                null,
                'prediction.let.retrain',
                'let_model',
                $result['model']->id,
                $result['message'],
                ['outcome' => $result['outcome'], 'trigger' => $result['model']->trigger] + ($result['model']->metrics ?? []),
            );
        }
    }

    private function resultsAreStillBeingEntered(): bool
    {
        // Run inline (tests, a sync queue) there is nothing to come back to.
        if ($this->job === null || $this->job instanceof SyncJob) {
            return false;
        }

        $latest = LleResult::query()->max('updated_at');

        return $latest !== null && now()->subMinutes(self::QUIET_MINUTES)->lt($latest);
    }
}
