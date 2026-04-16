<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Events\VehicleStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShiftController extends Controller
{
    /**
     * Driver starts their shift.
     * Sets shift_active = true, resets GPS state.
     *
     * POST /api/driver/shift/start
     */
    public function start(Request $request)
    {
        \Log::info('DEBUG SHIFT START', [
            'auth_id' => Auth::id(),
            'vehicle' => Vehicle::where('user_id', Auth::id())->first()
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
            'is_active'        => false, // GPS not yet active; will flip true on first update
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
            ],
        ]);
    }

    /**
     * Driver ends their shift manually.
     * Sets shift_active = false, stops GPS tracking expectations.
     *
     * POST /api/driver/shift/end
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