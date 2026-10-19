<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/me', [AuthController::class, 'me']);

Route::post('/auth/logout', [AuthController::class, 'logout']);
