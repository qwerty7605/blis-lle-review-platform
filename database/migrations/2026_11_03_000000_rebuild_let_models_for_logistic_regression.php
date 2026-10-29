<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Estimated Outcome model is now ridge-penalized logistic regression
 * (WEKA 3.8.6 Logistic in CVParameterSelection), not a J48 tree. Every
 * version keeps what the app computes from (the coefficients, the fill
 * values, means and standard deviations, the ridge), the at-risk cutoff, the
 * evaluation it was judged on and how many rows it learned from.
 *
 * The database starts empty when this ships, so the old tree columns and
 * the never-used let_training_records table go, with no conversion.
 *
 * Version 1 is the model trained in WEKA (ml/models/logistic-v1.json, see
 * ml/README.md). It is loaded here, as reference data, so that a fresh
 * install predicts without anyone retraining first.
 */
return new class extends Migration
{
    private const VERSION_ONE = 'ml/models/logistic-v1.json';

    public function up(): void
    {
        Schema::dropIfExists('let_training_records');
        Schema::dropIfExists('let_models');

        Schema::create('let_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            // active | retired | rejected (App\Models\LetModel)
            $table->string('status', 20)->index();
            // weka: trained in WEKA and loaded; retrained: trained here
            $table->string('source', 20);
            $table->string('algorithm');
            // App\Services\MachineLearning\LogisticModel::toArray()
            $table->json('parameters');
            $table->double('ridge');
            $table->double('at_risk_cutoff');
            $table->unsignedInteger('training_count');
            $table->unsignedInteger('failed_count');
            $table->unsignedInteger('imported_count');
            $table->unsignedInteger('graduate_count');
            // The 10 x 10-fold cross-validation means the version was judged on.
            $table->double('roc_area');
            $table->double('failed_recall');
            $table->double('false_alarm_rate');
            $table->double('failed_precision');
            $table->double('failed_f_measure');
            $table->double('accuracy');
            $table->double('kappa');
            $table->double('zero_r_accuracy');
            $table->double('zero_r_kappa');
            $table->boolean('meets_targets');
            // Everything else: confusion matrices, ZeroR, each repeat, the
            // ridge candidates and their errors, and why a version was not
            // taken up.
            $table->json('evaluation');
            $table->json('selection');
            $table->json('checks')->nullable();
            // Which graduates' results it learned from (see LetTrainingSetBuilder).
            $table->json('training_keys');
            $table->string('file_path')->nullable();
            $table->string('trigger', 20);
            $table->foreignId('trained_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        $this->loadVersionOne();
    }

    public function down(): void
    {
        Schema::dropIfExists('let_models');
    }

    private function loadVersionOne(): void
    {
        $path = base_path(self::VERSION_ONE);

        if (!is_file($path)) {
            return;
        }

        $export = json_decode((string) file_get_contents($path), true);
        $model = \App\Services\MachineLearning\LogisticModel::fromWekaExport($export);
        $figures = $export['evaluation']['model'];
        $zeroR = $export['evaluation']['zero_r'];
        $now = now();

        DB::table('let_models')->insert([
            'version' => 1,
            'status' => 'active',
            'source' => 'weka',
            'algorithm' => $export['scheme'],
            'parameters' => json_encode($model->toArray()),
            'ridge' => $model->ridge,
            'at_risk_cutoff' => (float) $export['evaluation']['cutoff_p_failed'],
            'training_count' => (int) $export['rows'],
            'failed_count' => (int) $export['failed_rows'],
            'imported_count' => (int) $export['rows'],
            'graduate_count' => 0,
            'roc_area' => (float) $figures['roc_area'],
            'failed_recall' => (float) $figures['failed_recall'],
            'false_alarm_rate' => (float) $figures['false_alarm_rate'],
            'failed_precision' => (float) $figures['failed_precision'],
            'failed_f_measure' => (float) $figures['failed_f_measure'],
            'accuracy' => (float) $figures['accuracy'],
            'kappa' => (float) $figures['kappa'],
            'zero_r_accuracy' => (float) $zeroR['accuracy'],
            'zero_r_kappa' => (float) $zeroR['kappa'],
            'meets_targets' => $figures['roc_area'] >= 0.70 && $figures['failed_recall'] >= 0.60,
            'evaluation' => json_encode([
                'rows' => (int) $export['rows'],
                'failed_rows' => (int) $export['failed_rows'],
                'cutoff_p_failed' => (float) $export['evaluation']['cutoff_p_failed'],
                'folds' => (int) $export['evaluation']['folds'],
                'repeats' => (int) $export['evaluation']['repeats'],
                'model' => $figures,
                'zero_r' => $zeroR,
                'per_repeat' => $export['evaluation']['per_repeat'],
            ]),
            'selection' => json_encode(['ridge' => $model->ridge, 'candidates' => $export['candidates']]),
            'checks' => null,
            'training_keys' => json_encode([]),
            'file_path' => 'ml/models/logistic-v1.model',
            'trigger' => 'weka',
            'trained_by' => null,
            'activated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
