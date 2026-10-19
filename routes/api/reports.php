<?php

use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports/overview', [ReportController::class, 'overview']);

Route::get('/reports/exams/{exam}/rooms/{room}/complete-results.csv', [ReportController::class, 'completeResultsCsv']);

Route::get('/reports/exams/{exam}/rooms/{room}/complete-results.xlsx', [ReportController::class, 'completeResultsXlsx']);

Route::get('/reports/exams/{exam}/rooms/{room}/summary.pdf', [ReportController::class, 'summaryPdf']);

Route::get('/reports/exams/{exam}/rooms/{room}/answer-key.pdf', [ReportController::class, 'answerKeyPdf']);

Route::get('/reports/exams/{exam}/rooms/{room}/students/{student}/student-report.pdf', [ReportController::class, 'studentReportPdf']);

Route::post('/reports/exams/{exam}/rooms/{room}/students/{student}/student-report.email', [ReportController::class, 'emailStudentReport']);

Route::post('/reports/exams/{exam}/rooms/{room}/student-reports.email', [ReportController::class, 'emailStudentReports']);

Route::get('/reports/exams/{exam}/rooms/{room}/student-reports.zip', [ReportController::class, 'studentReportsZip']);
