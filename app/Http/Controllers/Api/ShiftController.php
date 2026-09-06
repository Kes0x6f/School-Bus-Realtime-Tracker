<?php

namespace App\Http\Controllers\Api;

use App\Enums\ShiftEndReason;
use App\Enums\VehicleRoute;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartShiftRequest;
use App\Models\Vehicle;
use App\Services\ShiftCompletionService;
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
    public function start(StartShiftRequest $request, ShiftCompletionService $shiftLifecycle)
    {
        $validated = $request->validated();
        $route = isset($validated['route_name'])
            ? VehicleRoute::from($validated['route_name'])
            : null;
        $vehicle = $shiftLifecycle->startForDriver((int) Auth::id(), $route);

        if (!$vehicle) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Shift is already active.',
            ], 422);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Shift started.',
            'data'    => [
                'id'               => $vehicle->id,
                'shift_active'     => $vehicle->shift_active,
                'shift_started_at' => $vehicle->shift_started_at,
                'shift_id'         => $vehicle->current_shift_id,
                'gps_status'       => $vehicle->gps_status,
                'route_name'       => $vehicle->route_name,
            ],
        ]);
    }

    /**
     * POST /driver/shift/end
     */
    public function end(ShiftCompletionService $shiftCompletion)
    {
        $vehicle = Vehicle::where('user_id', Auth::id())->firstOrFail();

        $result = $shiftCompletion->complete($vehicle, ShiftEndReason::MANUAL);
        $vehicle = $result->vehicle;

        return response()->json([
            'status'  => 'success',
            'message' => $result->completed ? 'Shift ended.' : 'Shift was already ended.',
            'data'    => [
                'id'             => $vehicle->id,
                'shift_active'   => $vehicle->shift_active,
                'shift_ended_at' => $vehicle->shift_ended_at,
                'shift_id'       => $result->shift?->id,
                'already_ended'  => !$result->completed,
                'gps_status'     => $vehicle->gps_status,
            ],
        ]);
    }
}
