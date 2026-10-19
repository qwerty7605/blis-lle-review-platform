<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

/*
 * The API. Each area's routes are in their own file under routes/api/, and
 * each endpoint's code is a method of a controller in
 * app/Http/Controllers/Api. Rate limits are in
 * AppServiceProvider::configureRateLimiting().
 */

// Signed out: signing up and in, password resets, email links, and the
// settings the sign-in page shows.
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:password-reset-request');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset-confirm');
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->name('verification.verify');
Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:verification');
Route::get('/account/email/confirm/{id}/{hash}', [AccountController::class, 'confirmEmailChange'])->name('account.email.confirm');
Route::get('/settings/public/legal', [SettingsController::class, 'legal']);
Route::get('/settings/public/notice', [SettingsController::class, 'notice']);

// Signed in, with an account that is still active (EnsureAccountIsActive).
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    require __DIR__ . '/api/auth.php';
    require __DIR__ . '/api/account.php';
    require __DIR__ . '/api/feedback.php';
    require __DIR__ . '/api/rooms.php';
    require __DIR__ . '/api/student.php';
    require __DIR__ . '/api/library.php';
    require __DIR__ . '/api/review-bot.php';
    require __DIR__ . '/api/review-bot-admin.php';
    require __DIR__ . '/api/exams.php';
    require __DIR__ . '/api/reports.php';
    require __DIR__ . '/api/settings.php';
    require __DIR__ . '/api/admin.php';
    require __DIR__ . '/api/predictions.php';
    require __DIR__ . '/api/lle-results.php';
});
