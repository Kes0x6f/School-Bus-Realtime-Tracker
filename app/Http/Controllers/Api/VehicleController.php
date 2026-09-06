<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveVehicleCollection;
use App\Http\Resources\VehicleTrackingResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VehicleController extends Controller
{
    /**
     * Full vehicle list with computed gps_status.
     * Used for admin / internal purposes.
     */
    public function index()
    {
        $vehicles = Vehicle::get()->map(function ($vehicle) {
            $vehicle->gps_status = $vehicle->gps_status;
            return $vehicle;
        });

        return response()->json($vehicles);
    }
    /**
     * Active vehicles — shift_active = true only.
     * Returns all state fields so the frontend can render
     * the correct indicator per vehicle (moving/idle/disconnected).
     */
    public function activeVehicles()
    {
        Gate::authorize('viewAny', Vehicle::class);

        $vehicles = Vehicle::with('user')
            ->where('shift_active', true)
            ->get();

        return new ActiveVehicleCollection($vehicles);
    }

    /**
     * Single vehicle detail.
     * Used by the tracking page on initial load.
     * Includes shift state so tracking.js can decide what to show immediately.
     */
    public function show(Vehicle $vehicle)
    {
        Gate::authorize('view', $vehicle);

        return new VehicleTrackingResource($vehicle);
    }
    //Updating the status of occupancy
    public function updateOccupancy(Request $request)
    {
        // Was: Vehicle::first() — always hit the first row in the DB regardless
        // of which driver made the request. Broken with more than one vehicle.
        $vehicle = Vehicle::where('user_id', auth()->id())->firstOrFail();
 
        $validated = $request->validate([
            'is_full' => 'required|boolean',
        ]);
 
        $vehicle->update(['is_full' => $validated['is_full']]);
 
        $vehicle->refresh();
 
        broadcast(new \App\Events\VehicleStatusChanged($vehicle));
 
        return response()->json([
            'status'  => 'success',
            'is_full' => $vehicle->is_full,
        ]);
    }
}
