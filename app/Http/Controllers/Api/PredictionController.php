<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RetrainLetModelJob;
use App\Models\Exam;
use App\Models\LetModel;
use App\Services\MachineLearning\BoardExamOutlook;
use App\Services\MachineLearning\LetModelTrainer;
use App\Services\MachineLearning\LetPredictionService;
use App\Services\MachineLearning\LogisticModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PredictionController extends Controller
{
    /**
     * The Estimated Outcome for a set of scores typed in by staff: a series
     * type and its six subject scores, read by the version in use. A score
     * left out is missing, never zero.
     */
    public function run(Request $request, LetPredictionService $service)
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rules = ['exam_type' => ['required', Rule::in(Exam::SERIES_TYPES)]];
        foreach (LogisticModel::SUBJECTS as $subject) {
            $rules[$subject] = ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        $features = $request->validate($rules);
        $result = $service->predict($features);

        if ($result === null) {
            return response()->json(['message' => 'No Estimated Outcome model is in use right now.'], 422);
        }

        $this->recordAudit(
            $user,
            'prediction.let.run',
            null,
            null,
            'Worked out an Estimated Outcome from typed-in scores',
            ['percent_passing' => $result['percent_passing'], 'at_risk' => $result['at_risk'], 'version' => $result['version']],
            $request,
        );

        return response()->json([
            'percent_passing' => $result['percent_passing'],
            'probability_passing' => round($result['probability_passing'], 4),
            'at_risk' => $result['at_risk'],
            'label' => $result['at_risk'] ? BoardExamOutlook::LABEL_AT_RISK : BoardExamOutlook::LABEL_NOT_AT_RISK,
            'features' => $features,
            'model_version' => $result['version'],
            'disclaimer' => BoardExamOutlook::NOTE,
        ]);
    }

    /**
     * The model behind the Estimated Outcome: the version in use, whether a
     * new one can be trained, and every version with its figures.
     */
    public function model(Request $request, LetModelTrainer $trainer)
    {
        if (!$this->canManageRooms($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($trainer->status());
    }

    /**
     * Admin only, and only on request: nothing retrains the model by itself.
     * The training runs on the queue; the page follows `training`.
     */
    public function retrain(Request $request, LetModelTrainer $trainer)
    {
        $user = $request->user();

        if (!$this->isAdmin($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($trainer->isRunning()) {
            return response()->json([
                'outcome' => LetModelTrainer::OUTCOME_ALREADY_RUNNING,
                'message' => 'A retraining is already running.',
                ...$trainer->status(),
            ], 422);
        }

        $blocked = $trainer->blockedReason();

        if ($blocked !== null) {
            return response()->json([...$blocked, ...$trainer->status()], 422);
        }

        $trainer->markRunning();

        $this->recordAudit($user, 'prediction.let.retrain.request', null, null, 'Asked for the Estimated Outcome model to be retrained', [], $request);

        RetrainLetModelJob::dispatch($user->id);

        return response()->json([
            'outcome' => LetModelTrainer::OUTCOME_QUEUED,
            'message' => 'Retraining has started. It takes a few minutes; this page shows the new version when it is done.',
            ...$trainer->status(),
        ], 202);
    }

    /**
     * Admin only: put a saved version into use. Any version that meets both
     * targets (ROC area and FAILED recall) can be chosen.
     */
    public function activate(Request $request, LetModelTrainer $trainer)
    {
        $user = $request->user();

        if (!$this->isAdmin($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $model = LetModel::query()->where('version', $version)->first();
        $result = $model === null ? null : $trainer->activate($model);

        if ($result === null) {
            return response()->json([
                'message' => 'That version is already in use, does not exist, or does not meet both targets (ROC area at least 0.70 and FAILED recall at least 0.60).',
            ], 422);
        }

        $this->recordAudit($user, 'prediction.let.activate', 'let_model', $model->id, $result['message'], ['version' => $version], $request);

        return response()->json([
            'outcome' => $result['outcome'],
            'message' => $result['message'],
            ...$trainer->status(),
        ]);
    }
}
