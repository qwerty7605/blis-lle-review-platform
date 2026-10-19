<?php

use App\Http\Controllers\Api\LleResultController;
use Illuminate\Support\Facades\Route;

/**
 * Recording actual Librarian Licensure Examination outcomes.
 *
 * These are the labels that make the accumulated mock-exam history trainable
 * (see the lle_results migration and LetPredictionService's retraining note).
 * Results are published by the PRC, so a staff member transcribes them once a
 * batch has sat the exam -- there is nothing to derive them from automatically.
 */
Route::middleware([])->group(function () {
    Route::get('/lle-results', [LleResultController::class, 'index']);

    Route::post('/lle-results', [LleResultController::class, 'store']);

    Route::get('/lle-results/batch', [LleResultController::class, 'batch']);

    Route::post('/lle-results/batch', [LleResultController::class, 'storeBatch']);

    Route::delete('/lle-results/{lleResult}', [LleResultController::class, 'destroy']);
});
