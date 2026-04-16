<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Events\VehicleLocationUpdated;
use App\Events\VehicleStatusChanged;
class GpsController extends Controller
{
    /**
     * Receive a GPS update from the driver.
     *
     * Guards:
     *  - Vehicle must exist
     *  - shift_active must be true (driver must have started a shift first)
     *
     * Side effects:
     *  - Sets is_active = true (GPS is fresh)
     *  - Fires VehicleStatusChanged if GPS was previously stale (is_active was false)
     *  - Always fires VehicleLocationUpdated
     *
     * POST /api/gps/update
     */
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
        // Reject GPS updates if the driver has not started a shift.
        // This prevents stale background pings from keeping a vehicle visible.
        if (!$vehicle->shift_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No active shift. Start a shift before sending GPS updates.',
            ], 403);
        }
 
        // Was GPS considered stale before this update?
        $wasGpsStale = !$vehicle->is_active;
    
        $vehicle->update([
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'speed' => $validated['speed'] ?? null,
            'last_seen' => now(),
            'is_active' => true,
            'route_name' => $validated['route_name'] ?? $vehicle->route_name,
        ]);

        $vehicle->refresh();
        
        if ($wasGpsStale) {
            event(new VehicleStatusChanged($vehicle));
        }

        event(new VehicleLocationUpdated($vehicle));

        return response()->json([
            'status' => 'success',
            "message" => "GPS update received",
            'data' => $vehicle
        ]);
    }
}