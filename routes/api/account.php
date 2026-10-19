<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountSessionController;
use Illuminate\Support\Facades\Route;

/*
 * What a signed-in person may change about their own account, from the
 * Settings page: their name and password (every role), and the address they
 * sign in with (staff and administrators). A student's student ID, year level
 * and email stay with the administrator -- they are what the school's records
 * are matched on.
 */

Route::patch('/account/profile', [AccountController::class, 'updateProfile']);

Route::put('/account/password', [AccountController::class, 'updatePassword']);

Route::post('/account/email', [AccountController::class, 'requestEmailChange'])->middleware('throttle:verification');

Route::put('/account/preferences', [AccountController::class, 'updatePreferences']);

Route::get('/account/sessions', [AccountSessionController::class, 'index']);

Route::delete('/account/sessions/{tokenId}', [AccountSessionController::class, 'destroy'])->whereNumber('tokenId');

Route::post('/account/sessions/sign-out-others', [AccountSessionController::class, 'destroyOthers']);
