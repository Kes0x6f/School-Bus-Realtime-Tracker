<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Vehicle;
use App\Events\VehicleStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShiftController extends Controller
{
    /**
     * POST /driver/shift/start
     *
     * Accepts route_name from the driver's selected dropdown so the
     * active-jeeps card shows the correct route immediately on shift start,
     * not the stale route_name from the previous shift.
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'route_name' => 'nullable|string|max:255',
        ]);

        $vehicle = Vehicle::where('user_id', Auth::id())->firstOrFail();

        if ($vehicle->shift_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Shift is already active.',
            ], 422);
        }

        $vehicle->update([
            'shift_active'     => true,
            'shift_started_at' => now(),
            'shift_ended_at'   => null,
            'is_active'        => false,
            'last_moved_at'    => null,   // reset so idle/traffic clock starts fresh
            'route_name'       => $validated['route_name'] ?? $vehicle->route_name,
        ]);

        $vehicle->refresh();
        broadcast(new VehicleStatusChanged($vehicle));

        return response()->json([
            'status'  => 'success',
            'message' => 'Shift started.',
            'data'    => [
                'id'               => $vehicle->id,
                'shift_active'     => $vehicle->shift_active,
                'shift_started_at' => $vehicle->shift_started_at,
                'gps_status'       => $vehicle->gps_status,
                'route_name'       => $vehicle->route_name,
            ],
        ]);
    }

    /**
     * POST /driver/shift/end
     */
    public function end(Request $request)
    {
        $vehicle = Vehicle::where('user_id', Auth::id())->firstOrFail();

        if (!$vehicle->shift_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No active shift to end.',
            ], 422);
        }

        Shift::log($vehicle, 'manual');

        $vehicle->update([
            'shift_active'   => false,
            'shift_ended_at' => now(),
            'is_active'      => false,
        ]);

        $vehicle->refresh();
        broadcast(new VehicleStatusChanged($vehicle));

        return response()->json([
            'status'  => 'success',
            'message' => 'Shift ended.',
            'data'    => [
                'id'             => $vehicle->id,
                'shift_active'   => $vehicle->shift_active,
                'shift_ended_at' => $vehicle->shift_ended_at,
                'gps_status'     => $vehicle->gps_status,
            ],
        ]);
    }
}