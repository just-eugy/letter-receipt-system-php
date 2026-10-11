<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PublicDeliveryController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AdminController;

// Public API endpoints (no authentication required)
Route::middleware('api')->prefix('public')->group(function () {
    Route::get('/recipient-roles', [PublicDeliveryController::class, 'recipientRoles']);
    Route::post('/deliveries', [PublicDeliveryController::class, 'create']);
    Route::get('/deliveries/{tracking}', [PublicDeliveryController::class, 'track']);
});

// Reception API endpoints (requires RECEPTIONIST or ADMIN role)
Route::middleware(['api', 'auth:sanctum', 'role:receptionist,admin'])->prefix('reception')->group(function () {
    Route::get('/deliveries/pending', [ReceptionController::class, 'pending']);
    Route::get('/deliveries/pending/page', [ReceptionController::class, 'pendingPage']);
    Route::get('/deliveries/received', [ReceptionController::class, 'received']);
    Route::get('/deliveries/received/page', [ReceptionController::class, 'receivedPage']);
    Route::post('/deliveries/{id}/receive', [ReceptionController::class, 'receive']);
    Route::get('/statistics', [ReceptionController::class, 'statistics']);
});

// Report API endpoints (requires RECEPTIONIST or ADMIN role)
Route::middleware(['api', 'auth:sanctum', 'role:receptionist,admin'])->prefix('reception/reports')->group(function () {
    Route::post('/generate', [ReportController::class, 'generate']);
    Route::get('/export/csv', [ReportController::class, 'exportCsv']);
    Route::get('/export/excel', [ReportController::class, 'exportExcel']);
});

// Admin API endpoints (requires ADMIN role, except login/me)
Route::middleware('api')->prefix('admin')->group(function () {
    Route::post('/login', [AdminController::class, 'login']);
    Route::post('/logout', [AdminController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AdminController::class, 'me'])->middleware('auth:sanctum');
});

// Authenticated user endpoint
Route::middleware(['api', 'auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});