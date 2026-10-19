<?php

use App\Http\Controllers\Api\ReviewBotAdminController;
use Illuminate\Support\Facades\Route;

Route::post('/library/review-bot/generate', [ReviewBotAdminController::class, 'generate']);

Route::get('/library/review-bot/questions', [ReviewBotAdminController::class, 'questions']);

Route::patch('/library/review-bot/questions/{question}', [ReviewBotAdminController::class, 'updateQuestion']);

Route::post('/library/review-bot/publish', [ReviewBotAdminController::class, 'publish']);

Route::post('/library/review-bot/reject', [ReviewBotAdminController::class, 'reject']);

Route::get('/library/review-bot/coverage', [ReviewBotAdminController::class, 'coverage']);

Route::post('/library/review-bot/unpublish', [ReviewBotAdminController::class, 'unpublish']);

Route::delete('/library/review-bot/questions/{question}', [ReviewBotAdminController::class, 'destroyQuestion']);
