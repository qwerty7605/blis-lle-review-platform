<?php

use App\Http\Controllers\Api\RoomController;
use Illuminate\Support\Facades\Route;

Route::get('/rooms', [RoomController::class, 'index']);

Route::get('/rooms/{room}', [RoomController::class, 'show']);

Route::patch('/rooms/{room}', [RoomController::class, 'update']);

Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);

Route::post('/rooms/{room}/archive', [RoomController::class, 'archive']);

Route::post('/rooms/{room}/restore', [RoomController::class, 'restore']);

Route::put('/rooms/{room}/joining', [RoomController::class, 'setJoining']);

Route::post('/rooms', [RoomController::class, 'store']);

Route::post('/rooms/join', [RoomController::class, 'join']);

Route::delete('/rooms/{room}/members/{member}', [RoomController::class, 'removeMember']);

Route::delete('/rooms/{room}/leave', [RoomController::class, 'leave']);

Route::get('/rooms/{room}/export-grades', [RoomController::class, 'exportGrades']);

Route::post('/rooms/{room}/exams/{exam}/archive', [RoomController::class, 'archiveExam']);

Route::post('/rooms/{room}/exams/{exam}/restore', [RoomController::class, 'restoreExam']);
