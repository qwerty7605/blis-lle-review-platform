<?php

use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamSeriesController;
use Illuminate\Support\Facades\Route;

Route::get('/exams', [ExamController::class, 'index']);

Route::post('/exams', [ExamController::class, 'store']);

Route::patch('/exams/{exam}', [ExamController::class, 'update']);

Route::delete('/exams/{exam}', [ExamController::class, 'destroy']);

Route::post('/exams/{exam}/archive', [ExamController::class, 'archive']);

Route::get('/exams/{exam}/item-analytics', [ExamController::class, 'itemAnalytics']);

Route::get('/exams/{exam}/live-dashboard', [ExamController::class, 'liveDashboard']);

// Pre-test and mock board series: the six subject exams that belong together.
Route::get('/exam-series', [ExamSeriesController::class, 'index']);

Route::post('/exam-series', [ExamSeriesController::class, 'store']);

Route::patch('/exam-series/{series}', [ExamSeriesController::class, 'update']);

Route::delete('/exam-series/{series}', [ExamSeriesController::class, 'destroy']);
