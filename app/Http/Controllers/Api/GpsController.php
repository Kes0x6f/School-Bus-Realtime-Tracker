<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Events\VehicleLocationUpdated;
use App\Events\VehicleStatusChanged;
use Illuminate\Support\Facades\Auth;

class GpsController extends Controller
{
    /**
     * POST /api/gps/update
     *
     * FIX: Vehicle is now resolved from Auth::id() instead of from the
     * request body. This prevents any authenticated driver from spoofing
     * another driver's vehicle_id and overwriting their GPS coordinates.
     *
     * The vehicle_id in the request body is still validated to exist, but
     * it is only used as a consistency check — if it doesn't match the
     * driver's assigned vehicle, we reject the request with 403.
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

        // Resolve vehicle from the authenticated driver — not from the request.
        $vehicle = Vehicle::where('user_id', Auth::id())->firstOrFail();

        // Sanity check: the vehicle_id the app sent must match the driver's
        // actual vehicle. Rejects any spoofing attempt.
        if ((int) $validated['vehicle_id'] !== $vehicle->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Vehicle ID does not match your assigned vehicle.',
            ], 403);
        }

        if (!$vehicle->shift_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No active shift. Start a shift before sending GPS updates.',
            ], 403);
        }

        $wasGpsStale = !$vehicle->is_active;

        $updateData = [
            'latitude'   => $validated['latitude'],
            'longitude'  => $validated['longitude'],
            'speed'      => $validated['speed'] ?? null,
            'last_seen'  => now(),
            'is_active'  => true,
            'route_name' => $validated['route_name'] ?? $vehicle->route_name,
        ];

        // Stamp last_moved_at whenever the jeep is clearly rolling.
        // 3 km/h sits above typical GPS noise at low speeds, so a stationary
        // jeep with jittery readings doesn't accidentally reset the timer.
        if (($validated['speed'] ?? 0) >= 3) {
            $updateData['last_moved_at'] = now();
        }

        $vehicle->update($updateData);

        $vehicle->refresh();

        if ($wasGpsStale) {
            event(new VehicleStatusChanged($vehicle));
        }

        event(new VehicleLocationUpdated($vehicle));

        return response()->json([
            'status'  => 'success',
            'message' => 'GPS update received',
            'data'    => $vehicle,
        ]);
    }
}