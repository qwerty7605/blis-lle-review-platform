<?php

use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FeedbackReviewController;
use Illuminate\Support\Facades\Route;

/*
 * Feedback, sent by anyone from the Settings page.
 *
 * A problem is emailed to the developers as it arrives (see
 * config/services.php for where): nobody decides to leave the system
 * broken. An idea, a question or anything else waits for the administrator,
 * the head of BLIS, who answers it, turns it down, or sends it on to the
 * developers; nothing reaches them before that. The sender sees the status
 * and the administrator's reply under "Your feedback".
 */

Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:feedback');

Route::get('/feedback', [FeedbackController::class, 'index']);

Route::get('/admin/feedback', [FeedbackReviewController::class, 'index']);

Route::get('/admin/feedback/{feedback}', [FeedbackReviewController::class, 'show']);

Route::patch('/admin/feedback/{feedback}', [FeedbackReviewController::class, 'update']);
