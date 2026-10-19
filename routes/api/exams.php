<?php

use App\Http\Controllers\Api\ExamController;
use Illuminate\Support\Facades\Route;

Route::get('/exams', [ExamController::class, 'index']);

Route::post('/exams', [ExamController::class, 'store']);

Route::patch('/exams/{exam}', [ExamController::class, 'update']);

Route::delete('/exams/{exam}', [ExamController::class, 'destroy']);

Route::post('/exams/{exam}/archive', [ExamController::class, 'archive']);

Route::get('/exams/{exam}/item-analytics', [ExamController::class, 'itemAnalytics']);

Route::get('/exams/{exam}/live-dashboard', [ExamController::class, 'liveDashboard']);
