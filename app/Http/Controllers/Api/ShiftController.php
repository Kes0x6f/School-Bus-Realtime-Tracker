<?php

namespace App\Http\Controllers\Api;

use App\Enums\ShiftEndReason;
use App\Http\Controllers\Controller;
use App\Enums\VehicleRoute;
use App\Http\Requests\StartShiftRequest;
use App\Models\Vehicle;
use App\Events\VehicleStatusChanged;
use App\Services\ShiftCompletionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    /**
     * POST /driver/shift/start
     *
     * Accepts route_name from the driver's selected dropdown so the
     * active-jeeps card shows the correct route immediately on shift start,
     * not the stale route_name from the previous shift.
     */
    public function start(StartShiftRequest $request)
    {
        $validated = $request->validated();

        $vehicle = DB::transaction(function () use ($validated): ?Vehicle {
            $vehicle = Vehicle::query()
                ->where('user_id', Auth::id())
                ->lockForUpdate()
                ->firstOrFail();

            if ($vehicle->shift_active) {
                return null;
            }

            $startedAt = now();

            $vehicle->update([
                'shift_active'     => true,
                'shift_started_at' => $startedAt,
                'shift_ended_at'   => null,
                'is_active'        => false,
                // A new shift must not inherit GPS state from the previous one.
                // Coordinates are cleared as well so they cannot be presented as
                // current before the first accepted fix for this shift.
                'latitude'         => null,
                'longitude'        => null,
                'speed'            => null,
                'last_seen'        => null,
                'last_moved_at'    => null,
                // Do not carry a legacy/unapproved value forward when a driver
                // starts a shift without selecting a route.
                'route_name'       => $validated['route_name']
                    ?? VehicleRoute::tryFrom((string) $vehicle->route_name)?->value,
            ]);

            return $vehicle->fresh();
        });

        if (!$vehicle) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Shift is already active.',
            ], 422);
        }

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
    public function end(ShiftCompletionService $shiftCompletion)
    {
        $vehicle = Vehicle::where('user_id', Auth::id())->firstOrFail();

        if (!$vehicle->shift_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No active shift to end.',
            ], 422);
        }

        $vehicle = $shiftCompletion->complete($vehicle, ShiftEndReason::MANUAL);

        if (!$vehicle) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No active shift to end.',
            ], 422);
        }

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
