<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::apiResource('employees', EmployeeController::class)->except('destroy');
    Route::post('/employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
    Route::post('/employees/{employee}/activate', [EmployeeController::class, 'activate']);
});
