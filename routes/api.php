<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\GpsController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\RouteController;

// ─── Public read-only ─────────────────────────────────────────────────────────
// These are read-only and consumed by the student tracking page.
// They return no sensitive data — just vehicle position and status.

Route::get('/vehicles/active', [VehicleController::class, 'activeVehicles']);
Route::get('/vehicles/{id}',   [VehicleController::class, 'show']);

// ─── Authenticated (web session) ──────────────────────────────────────────────
// The frontend sends cookies + CSRF headers (credentials: 'same-origin').
// We include the 'web' middleware group so the session is started and
// auth()->user() resolves correctly — no Sanctum needed.

Route::middleware(['web', 'auth'])->group(function () {

    // Driver-only mutations
    Route::middleware('role:driver')->group(function () {
        Route::post('/gps/update',       [GpsController::class, 'update']);
        Route::post('/vehicles/occupancy', [VehicleController::class, 'updateOccupancy']);
        Route::post('/driver/route',       [RouteController::class, 'update']);
    });

    // Admin or internal — full vehicle list with computed gps_status
    Route::middleware('role:admin')->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index']);
    });
});