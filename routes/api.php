<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\PositionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Departemen & jabatan: semua user login boleh lihat, hanya HR yang boleh ubah
    Route::apiResource('departments', DepartmentController::class)->only(['index', 'show']);
    Route::apiResource('positions', PositionController::class)->only(['index', 'show']);

    Route::middleware('role:hr,super_admin')->group(function () {
        Route::apiResource('departments', DepartmentController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('positions', PositionController::class)->only(['store', 'update', 'destroy']);
    });

    // Karyawan: izin per aksi dicek lewat EmployeePolicy
    Route::apiResource('employees', EmployeeController::class);

    // Absensi milik sendiri (semua role)
    Route::prefix('attendance')->group(function () {
        Route::get('/today', [AttendanceController::class, 'today']);
        Route::get('/me', [AttendanceController::class, 'me']);
        Route::post('/clock-in', [AttendanceController::class, 'clockIn'])->middleware('throttle:10,1');
        Route::post('/clock-out', [AttendanceController::class, 'clockOut'])->middleware('throttle:10,1');
    });

    // Laporan absensi: HR (semua) dan manager (bawahan langsung)
    Route::middleware('role:hr,super_admin,manager')->prefix('attendances')->group(function () {
        Route::get('/', [AttendanceReportController::class, 'index']);
        Route::get('/recap', [AttendanceReportController::class, 'recap']);
    });
});