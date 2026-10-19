<?php

use App\Http\Controllers\Api\ExamAttemptController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentDirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('/student/dashboard', [StudentController::class, 'dashboard']);

Route::get('/student/analytics/overview', [StudentController::class, 'analyticsOverview']);

Route::get('/student/analytics', [StudentController::class, 'analytics']);

Route::post('/student/exams/{exam}/start', [ExamAttemptController::class, 'start']);

Route::get('/student/exam-attempts/{attempt}', [ExamAttemptController::class, 'show']);

Route::patch('/student/exam-attempts/{attempt}/answers', [ExamAttemptController::class, 'saveAnswers']);

Route::patch('/student/exam-attempts/{attempt}/questions/{questionId}/bookmark', [ExamAttemptController::class, 'bookmark']);

Route::post('/student/exam-attempts/{attempt}/submit', [ExamAttemptController::class, 'submit']);

Route::get('/students/directory', [StudentDirectoryController::class, 'index']);

Route::get('/students/{student}/analytics', [StudentDirectoryController::class, 'analytics'])->whereNumber('student');
