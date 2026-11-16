<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\MachineLearning\LetModelTrainer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Retrains the Estimated Outcome model on the queue worker: choosing the
 * ridge and the 10 x 10-fold evaluation fit about twenty thousand models,
 * which outlasts a request on the production server. The Licensure page asks
 * whether it is still running (LetModelTrainer::isRunning()) and shows the
 * new version once it is done; the outcome is written to the audit log.
 */
class RetrainLetModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Retraining is deliberate; a failed run is not tried again by itself. */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public ?int $requestedBy = null,
    ) {
    }

    public function handle(LetModelTrainer $trainer, AuditLogService $audit): void
    {
        $by = $this->requestedBy ? User::query()->find($this->requestedBy) : null;

        try {
            $result = $trainer->run($by);
            $model = $result['model'];

            // The description column holds 255 characters and a run's message
            // (its failed checks, its battery rows) can be longer: the entry
            // keeps the whole message in its metadata and a cut one up front.
            $audit->recordAction($by, 'prediction.let.retrain', 'let_model', $model?->id, Str::limit($result['message'], 250), ['outcome' => $result['outcome'], 'message' => $result['message']] + ($model ? [
                'version' => $model->version,
                'rows' => $model->training_count,
                'ridge' => $model->ridge,
                'roc_area' => $model->roc_area,
                'failed_recall' => $model->failed_recall,
                'kappa' => $model->kappa,
                'status' => $model->status,
            ] : []));
        } finally {
            $trainer->markFinished();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LetModelTrainer::class)->markFinished();
    }
}
