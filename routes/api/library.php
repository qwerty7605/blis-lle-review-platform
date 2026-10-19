<?php

use App\Http\Controllers\Api\LibraryController;
use Illuminate\Support\Facades\Route;

Route::get('/library/banks', [LibraryController::class, 'index']);

Route::post('/library/import/preview', [LibraryController::class, 'previewImport']);

Route::post('/library/banks', [LibraryController::class, 'store']);

Route::patch('/library/banks/{bank}', [LibraryController::class, 'update']);

Route::delete('/library/banks/{bank}', [LibraryController::class, 'destroy']);

Route::get('/library/banks/{bank}/questions', [LibraryController::class, 'questions']);

Route::get('/library/topics', [LibraryController::class, 'topics']);

Route::post('/library/banks/{bank}/queue-mapping', [LibraryController::class, 'queueMapping']);

Route::get('/library/mapping-queue', [LibraryController::class, 'mappingQueue']);

Route::patch('/library/banks/{bank}/tags/status', [LibraryController::class, 'updateTagStatus']);

Route::patch('/library/questions/{question}', [LibraryController::class, 'updateQuestion']);
