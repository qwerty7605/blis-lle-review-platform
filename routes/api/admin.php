<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\YearEndController;
use Illuminate\Support\Facades\Route;

Route::prefix('/admin')->group(function () {
    Route::get('/users', [UserController::class, 'index']);

    Route::post('/users', [UserController::class, 'store']);

    Route::patch('/users/{user}', [UserController::class, 'update']);

    Route::post('/users/{user}/recover-account', [UserController::class, 'recoverAccount']);

    Route::get('/rooms', [RoomController::class, 'adminIndex']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    Route::get('/audit-logs/export', [AuditLogController::class, 'export']);

    Route::get('/year-end', [YearEndController::class, 'preview']);

    Route::post('/year-end', [YearEndController::class, 'apply']);
});
