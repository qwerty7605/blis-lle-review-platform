<?php

use App\Http\Controllers\Api\ReviewBotController;
use Illuminate\Support\Facades\Route;

Route::get('/student/review-bot/catalog', [ReviewBotController::class, 'catalog']);

Route::post('/student/review-bot/sessions', [ReviewBotController::class, 'startSession']);

Route::post('/student/review-bot/sessions/{attempt}/submit', [ReviewBotController::class, 'submitSession']);

Route::get('/student/review-bot/sessions/{attempt}', [ReviewBotController::class, 'showSession']);
