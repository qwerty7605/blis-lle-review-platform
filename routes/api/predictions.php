<?php

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

    return response()->json([
        'prediction' => $result['prediction'],
        'confidence' => $result['confidence'],
        'support' => $result['support'],
        'leaf_errors' => $result['leaf_errors'],
        'path' => $result['path'],
        'disclaimer' => 'This model was trained on a single small student batch. Treat this as a rough indicator, not a certainty.',
    ]);
});
