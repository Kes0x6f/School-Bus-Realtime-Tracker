<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Events\VehicleLocationUpdated;
use App\Events\VehicleStatusChanged;
class GpsController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'latitude'   => 'required|numeric',
            'longitude'  => 'required|numeric',
            'speed'      => 'nullable|numeric',
            'route_name' => 'nullable|string',
        ]);

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);

        $wasActive = $vehicle->last_seen &&
        now()->diffInSeconds($vehicle->last_seen) < 60;

        $vehicle->update([
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'speed' => $validated['speed'] ?? null,
            'last_seen' => now(),
            'is_active' => true,
            'route_name' => $validated['route_name'] ?? $vehicle->route_name,
        ]);

        $vehicle->refresh();
        
        if (!$wasActive) {
            event(new VehicleStatusChanged($vehicle));
        }

        event(new VehicleLocationUpdated($vehicle));

        return response()->json([
            'status' => 'success',
            "message" => "gps update received",
            'data' => $vehicle
        ]);
    }
}