<?php

use App\Http\Controllers\Api\TaskController as ApiTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::post('/tasks', [ApiTaskController::class, 'store'])->name('api.tasks.store');
    Route::get('/tasks/{taskId}/logs', [ApiTaskController::class, 'logs'])->name('api.tasks.logs');
});
