<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\GpsController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\RouteController;

// ─── Authenticated tracking reads ────────────────────────────────────────────
// Consumed by the student tracking page and admin tracking tools.
// Requires an authenticated, active student or administrator session.
// The response is limited to tracking fields and excludes internal assignment
// identifiers.
//
// throttle:60,1 → max 60 requests per minute per IP.
// This is generous enough for the tracking page (which polls on location
// events, not on a fixed interval) but blocks scrapers and abuse.
Route::middleware([
    'web',
    'auth',
    'active',
    'role:student,admin',
    'throttle:60,1',
])->group(function () {
    Route::get('/vehicles/active', [VehicleController::class, 'activeVehicles'])
        ->name('api.vehicles.active');
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])
        ->name('api.vehicles.show');
});

// ─── Authenticated (web session) ──────────────────────────────────────────────
// The frontend sends cookies + CSRF headers (credentials: 'same-origin').
// We include the 'web' middleware group so the session is started and
// auth()->user() resolves correctly — no Sanctum needed.
Route::middleware(['web', 'auth', 'active'])->group(function () {

    // ── Driver-only mutations ─────────────────────────────────────────────────
    Route::middleware('role:driver')->group(function () {

        // GPS update — HTTP fallback fires every 5 s (SERVER_INTERVAL = 5000 ms
        // in driver-dashboard.js), so 12 requests/min at most per driver.
        // throttle:30,1 gives 2.5× headroom for retries without being abusive.
        // Laravel's throttle middleware keys authenticated routes on user ID,
        // so each driver gets their own independent bucket.
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/gps/update',         [GpsController::class, 'update']);
            Route::post('/vehicles/occupancy', [VehicleController::class, 'updateOccupancy']);
            Route::post('/driver/route',       [RouteController::class, 'update']);
        });
    });

    // ── Admin — full vehicle list with computed gps_status ────────────────────
    Route::middleware(['role:admin', 'throttle:60,1'])->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index']);
    });
});
