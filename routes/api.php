<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\GpsController;
use App\Http\Controllers\Api\VehicleController;

Route::post('/gps/update', [GpsController::class, 'update']);

Route::get('/gps/latest/{vehicleId}', [GpsController::class, 'latest']);

Route::get('/vehicles', [VehicleController::class, 'index']);

Route::get('/vehicles/active', [VehicleController::class, 'activeVehicles']);

Route::get('/vehicles/{id}', [VehicleController::class, 'show']);

Route::post('/vehicles/occupancy', [VehicleController::class, 'updateOccupancy']);

Route::post('/test', function () {
    return response()->json(['success' => true]);
});

Route::get('/debug-broadcast', function () {
    $vehicle = \App\Models\Vehicle::find(1);
    $vehicle->latitude = 16.051 + rand(-100,100)/10000;
    $vehicle->longitude = 120.340 + rand(-100,100)/10000;
    event(new \App\Events\VehicleLocationUpdated($vehicle));
    return "event fired";
});