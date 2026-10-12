<?php

namespace App\Console\Commands;

use App\Models\LetModel;
use App\Services\MachineLearning\LetModelTrainer;
use Illuminate\Console\Command;

class RetrainLetModelCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'let:retrain';

    /**
     * @var string
     */
    protected $description = 'Retrain the Exam Outcome model from recorded licensure results, and use it if it does at least as well as the current one';

    public function handle(LetModelTrainer $trainer): int
    {
        $result = $trainer->run(null, LetModel::TRIGGER_MANUAL);

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
