<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\LeaveBalanceController;
use App\Http\Controllers\Api\LeaveTypeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\CandidateController;
use App\Http\Controllers\Api\JobOpeningController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\PasswordController;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::apiResource('employees', EmployeeController::class)->except('destroy');
    Route::post('/employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
    Route::post('/employees/{employee}/activate', [EmployeeController::class, 'activate']);

    Route::apiResource('attendances', AttendanceController::class)->except('destroy');

    Route::apiResource('leave-types', LeaveTypeController::class)->except(['show', 'destroy']);

    Route::get('/leave-balances', [LeaveBalanceController::class, 'index']);
    Route::put('/leave-balances', [LeaveBalanceController::class, 'set']);
    Route::post('/leave-balances/generate', [LeaveBalanceController::class, 'generate']);

    Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
    Route::get('/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
    Route::post('/leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
    Route::post('/leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
    Route::post('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);

    Route::get('/payrolls', [PayrollController::class, 'index']);
    Route::post('/payrolls', [PayrollController::class, 'store']);
    Route::get('/payrolls/{payroll}', [PayrollController::class, 'show']);
    Route::delete('/payrolls/{payroll}', [PayrollController::class, 'destroy']);
    Route::put('/payrolls/{payroll}/items', [PayrollController::class, 'updateItems']);
    Route::post('/payrolls/{payroll}/finalize', [PayrollController::class, 'finalize']);
    Route::post('/payrolls/{payroll}/adjustments', [PayrollController::class, 'adjust']);

    Route::apiResource('job-openings', JobOpeningController::class)->except('destroy');
    Route::post('/job-openings/{jobOpening}/close', [JobOpeningController::class, 'close']);

    Route::apiResource('candidates', CandidateController::class)->except('destroy');
    Route::post('/candidates/{candidate}/move', [CandidateController::class, 'move']);
    Route::post('/candidates/{candidate}/convert', [CandidateController::class, 'convert']);

    Route::put('/user/password', [PasswordController::class, 'update'])->middleware('throttle:5,1');

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::put('/accounts/{user}/role', [AccountController::class, 'changeRole']);
    Route::post('/accounts/{user}/lock', [AccountController::class, 'lock']);
    Route::post('/accounts/{user}/unlock', [AccountController::class, 'unlock']);
    Route::post('/accounts/{user}/reset-password', [AccountController::class, 'resetPassword']);
});
