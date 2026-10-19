<?php

use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/system', [SettingsController::class, 'show']);

Route::put('/settings/system', [SettingsController::class, 'update']);
