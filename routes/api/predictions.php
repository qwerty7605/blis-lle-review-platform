<?php

use App\Http\Controllers\Api\PredictionController;
use Illuminate\Support\Facades\Route;

Route::post('/predictions/let', [PredictionController::class, 'run']);

Route::get('/predictions/let/model', [PredictionController::class, 'model']);

Route::post('/predictions/let/model/retrain', [PredictionController::class, 'retrain']);

Route::post('/predictions/let/model/activate', [PredictionController::class, 'activate']);
