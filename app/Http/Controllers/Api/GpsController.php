<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Events\VehicleLocationUpdated;

class GpsController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'latitude'   => 'required|numeric',
            'longitude'  => 'required|numeric',
            'speed'      => 'nullable|numeric',
        ]);

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);

        $vehicle->update([
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'speed' => $validated['speed'] ?? null,
            'last_seen' => now()
        ]);

        $vehicle->refresh();
    
        event(new VehicleLocationUpdated($vehicle));

        return response()->json([
            'status' => 'success',
            "message" => "gps update received",
            'data' => $vehicle
        ]);
    }
}