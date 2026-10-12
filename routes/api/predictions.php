<?php

use App\Models\LetModel;
use App\Services\MachineLearning\LetModelTrainer;
use App\Services\MachineLearning\LetPredictionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/predictions/let', function (
    Request $request
) use (
    $canManageRooms,
    $ensureActive,
    $recordAudit
) {
    // Predicts with whichever model is in use: the built-in tree, or the
    // latest retrained one (see LetPredictionService::liveModel()).
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $rules = [];
    foreach (LetPredictionService::requiredFeatures() as $feature) {
        $rules[$feature] = ['required', 'numeric', 'min:0', 'max:100'];
    }

    $validated = $request->validate($rules);

    $service = new LetPredictionService();
    $result = $service->predict($validated);

    $recordAudit(
        $user,
        'prediction.let.run',
        null,
        null,
        'Ran LET pass/fail prediction',
        [
            'prediction' => $result['prediction'],
            'confidence' => $result['confidence'],
            'support' => $result['support'],
        ],
        $request,
    );

    $model = $service->liveModel();

    return response()->json([
        'prediction' => $result['prediction'],
        'confidence' => $result['confidence'],
        'support' => $result['support'],
        'leaf_errors' => $result['leaf_errors'],
        'path' => $result['path'],
        'disclaimer' => $model['source'] === 'built_in'
            ? 'This model was trained on a single small student batch. Treat this as a rough indicator, not a certainty.'
            : sprintf(
                'This model was retrained on %d recorded licensure results. Treat this as a rough indicator, not a certainty.',
                $model['trained_on'],
            ),
    ]);
});

/*
 * The model behind the predictions: which one is in use, how much there is to
 * learn from, and what recent retraining decided. Retraining also happens by
 * itself after licensure results are recorded (see RetrainLetModelJob).
 */
Route::get('/predictions/let/model', function (Request $request, LetModelTrainer $trainer) use ($canManageRooms, $ensureActive) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    if (!$canManageRooms($request->user())) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    return response()->json($trainer->status());
});

Route::post('/predictions/let/model/retrain', function (Request $request, LetModelTrainer $trainer) use ($canManageRooms, $ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $result = $trainer->run($user, LetModel::TRIGGER_MANUAL);

    $recordAudit(
        $user,
        'prediction.let.retrain',
        'let_model',
        $result['model']?->id,
        $result['message'],
        ['outcome' => $result['outcome']] + ($result['model']?->metrics ?? []),
        $request,
    );

    return response()->json([
        'outcome' => $result['outcome'],
        'message' => $result['message'],
        ...$trainer->status(),
    ]);
});

/*
 * Admin only: retraining is checked before a model is used, but putting one
 * back out of use overrides that check, which is not a teacher's call.
 */
Route::post('/predictions/let/model/revert', function (Request $request, LetModelTrainer $trainer) use ($isAdmin, $ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isAdmin($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $result = $trainer->revert();

    if ($result === null) {
        return response()->json(['message' => 'The built-in model is already in use.'], 422);
    }

    $recordAudit(
        $user,
        'prediction.let.revert',
        'let_model',
        $result['model']?->id,
        $result['message'],
        [],
        $request,
    );

    return response()->json([
        'outcome' => $result['outcome'],
        'message' => $result['message'],
        ...$trainer->status(),
    ]);
});
