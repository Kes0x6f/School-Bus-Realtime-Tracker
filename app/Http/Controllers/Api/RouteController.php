<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRouteRequest;
use App\Models\Vehicle;
use App\Events\VehicleStatusChanged;
use Illuminate\Support\Facades\Auth;

class RouteController extends Controller
{
    /**
     * Update the driver's current route mid-shift.
     *
     * Only allowed while shift is active — no point changing a route
     * when the vehicle isn't on the active list.
     *
     * Broadcasts VehicleStatusChanged so:
     *   - active-jeeps page updates the card's route label
     *   - tracking page receives the new route_name and shows a toast
     *
     * POST /api/driver/route
     */
    public function update(UpdateRouteRequest $request)
    {
        $validated = $request->validated();

        $vehicle = Vehicle::where('user_id', Auth::id())->firstOrFail();

        if (!$vehicle->shift_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No active shift. Cannot change route.',
            ], 403);
        }

        $vehicle->update(['route_name' => $validated['route_name']]);
        $vehicle->refresh();

        broadcast(new VehicleStatusChanged($vehicle));

        return response()->json([
            'status'     => 'success',
            'route_name' => $vehicle->route_name,
        ]);
    }
}
