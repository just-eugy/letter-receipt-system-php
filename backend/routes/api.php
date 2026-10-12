<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Api\PublicDeliveryController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AdminController;

// Register broadcasting auth route with HTTP Basic Auth support
Broadcast::routes(['middleware' => ['api', 'auth.basic']]);

// Public API endpoints (no authentication required)
Route::middleware('api')->prefix('public')->group(function () {
    Route::get('/recipient-roles', [PublicDeliveryController::class, 'recipientRoles']);
    
    // Delivery creation with rate limiting (40 req/min per IP)
    Route::post('/deliveries', [PublicDeliveryController::class, 'create'])
        ->middleware('throttle:delivery-create');
    
    Route::get('/deliveries/{tracking}', [PublicDeliveryController::class, 'track']);
});

// Reception API endpoints (requires RECEPTIONIST or ADMIN role)
Route::middleware(['api', 'auth.basic', 'role:receptionist,admin'])->prefix('reception')->group(function () {
    Route::get('/deliveries/pending', [ReceptionController::class, 'pending']);
    Route::get('/deliveries/pending/page', [ReceptionController::class, 'pendingPage']);
    Route::get('/deliveries/received', [ReceptionController::class, 'received']);
    Route::get('/deliveries/received/page', [ReceptionController::class, 'receivedPage']);
    Route::post('/deliveries/{id}/receive', [ReceptionController::class, 'receive']);
    Route::get('/statistics', [ReceptionController::class, 'statistics']);
});

// Report API endpoints (requires RECEPTIONIST or ADMIN role)
Route::middleware(['api', 'auth.basic', 'role:receptionist,admin'])->prefix('reception/reports')->group(function () {
    Route::post('/generate', [ReportController::class, 'generate']);
    Route::get('/export/csv', [ReportController::class, 'exportCsv']);
    Route::get('/export/excel', [ReportController::class, 'exportExcel']);
});

// Admin API endpoints (requires ADMIN role, except login/me)
Route::middleware('api')->prefix('admin')->group(function () {
    // Login with rate limiting (8 req/min per IP) - no auth required
    Route::post('/login', [AdminController::class, 'login'])
        ->middleware('throttle:login');
    
    // Admin-only endpoints
    Route::middleware(['auth.basic', 'role:admin'])->group(function () {
        Route::post('/logout', [AdminController::class, 'logout']);
    });
    
    // Current user endpoint (accessible to both ADMIN and RECEPTIONIST)
    Route::middleware(['auth.basic', 'role:receptionist,admin'])->group(function () {
        Route::get('/me', [AdminController::class, 'me']);
    });
});

// Authenticated user endpoint (supports both Sanctum token and HTTP Basic)
Route::middleware(['api', 'auth.basic'])->get('/user', function (Request $request) {
    return $request->user();
});

// API routes with HTTP Basic fallback for Java compatibility
Route::middleware(['api', 'auth.basic'])->group(function () {
    // These routes will also accept HTTP Basic auth as fallback
});