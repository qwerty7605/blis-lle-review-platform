<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LetModel;
use App\Services\MachineLearning\LetModelTrainer;
use App\Services\MachineLearning\LetPredictionService;
use App\Services\MachineLearning\LetReadinessFeatureBuilder;
use Illuminate\Http\Request;

class PredictionController extends Controller
{
    public function run(Request $request)
    {
        // Predicts with whichever model is in use: the built-in tree, or the
        // retrained version that is active (see LetPredictionService::liveModel()).
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // The six subject scores. One left out is missing, not zero; `overall`
        // is worked out here from the ones given, never sent.
        $rules = [];
        foreach (array_keys(LetReadinessFeatureBuilder::SUBJECTS) as $subject) {
            $rules[$subject] = ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        $features = LetReadinessFeatureBuilder::features($request->validate($rules));

        $service = new LetPredictionService();
        $result = $service->predict($features);

        $this->recordAudit(
            $user,
            'prediction.let.run',
            null,
            null,
            'Ran board exam pass/fail prediction',
            [
                'prediction' => $result['prediction'],
                'confidence' => $result['confidence'],
                'support' => $result['support'],
            ],
            $request,
        );

        return response()->json([
            'prediction' => $result['prediction'],
            'confidence' => $result['confidence'],
            'support' => $result['support'],
            'leaf_errors' => $result['leaf_errors'],
            'path' => $result['path'],
            'features' => $features,
            'model_version' => $service->liveModel()['version'],
            'disclaimer' => 'This prediction is based on past review-exam data and is a guide, not a guarantee.',
        ]);
    }

    /**
     * The model behind the predictions: which version is in use, whether it can
     * be retrained, and every version with the figures it was judged on.
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
     */
    public function retrain(Request $request, LetModelTrainer $trainer)
    {
        $user = $request->user();

        if (!$this->isAdmin($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = $trainer->run($user);
        $model = $result['model'];

        $this->recordAudit(
            $user,
            'prediction.let.retrain',
            'let_model',
            $model?->id,
            $result['message'],
            ['outcome' => $result['outcome']] + ($model ? [
                'version' => $model->version,
                'rows' => $model->training_count,
                'accuracy' => $model->accuracy,
                'kappa' => $model->kappa,
                'baseline' => $model->baseline,
            ] : []),
            $request,
        );

        // A retraining that was not allowed to start is refused, not reported
        // as done.
        $blocked = in_array($result['outcome'], [
            LetModelTrainer::OUTCOME_NO_BASE_DATASET,
            LetModelTrainer::OUTCOME_NOT_ENOUGH_NEW_RESULTS,
        ], true);

        return response()->json([
            'outcome' => $result['outcome'],
            'message' => $result['message'],
            ...$trainer->status(),
        ], $blocked ? 422 : 200);
    }

    /**
     * Admin only: put an earlier version back into use. `version` 1 is the
     * original model; any other must be one that was in use before.
     */
    public function activate(Request $request, LetModelTrainer $trainer)
    {
        $user = $request->user();

        if (!$this->isAdmin($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $model = $version === LetPredictionService::BUILT_IN_VERSION
            ? null
            : LetModel::query()->where('version', $version)->first();

        $result = $version !== LetPredictionService::BUILT_IN_VERSION && $model === null
            ? null
            : $trainer->activate($model);

        if ($result === null) {
            return response()->json([
                'message' => 'That version is already in use, or was never in use and cannot be gone back to.',
            ], 422);
        }

        $this->recordAudit(
            $user,
            'prediction.let.activate',
            'let_model',
            $model?->id,
            $result['message'],
            ['version' => $version],
            $request,
        );

        return response()->json([
            'outcome' => $result['outcome'],
            'message' => $result['message'],
            ...$trainer->status(),
        ]);
    }
}
