<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vehicle;


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
        $vehicles = Vehicle::with('user')
            ->where('shift_active', true)
            ->get()
            ->map(function ($vehicle) {
                return [
                    'id'               => $vehicle->id,
                    'route_name'       => $vehicle->route_name,
                    'is_full'          => $vehicle->is_full,
                    'user_id'          => $vehicle->user_id,
                    'user'             => $vehicle->user ? ['name' => $vehicle->user->name] : null,
                    'latitude'         => $vehicle->latitude,
                    'longitude'        => $vehicle->longitude,
                    'speed'            => $vehicle->speed,
                    'last_seen'        => $vehicle->last_seen?->toISOString(),
                    'shift_active'     => $vehicle->shift_active,
                    'is_active'        => $vehicle->is_active,
                    'gps_status'       => $vehicle->gps_status,
                    'shift_started_at' => $vehicle->shift_started_at?->toISOString(),
                ];
            });
 
        return response()->json($vehicles);
    }

    /**
     * Single vehicle detail.
     * Used by the tracking page on initial load.
     * Includes shift state so tracking.js can decide what to show immediately.
     */
    public function show($id)
    {
        $vehicle = Vehicle::findOrFail($id);
 
        return response()->json([
            'id'               => $vehicle->id,
            'plate_number'     => $vehicle->plate_number,
            'latitude'         => $vehicle->latitude,
            'longitude'        => $vehicle->longitude,
            'speed'            => $vehicle->speed,
            'last_seen'        => $vehicle->last_seen?->toISOString(),
            'shift_active'     => $vehicle->shift_active,
            'is_active'        => $vehicle->is_active,
            'gps_status'       => $vehicle->gps_status,
            'shift_started_at' => $vehicle->shift_started_at?->toISOString(),
            'shift_ended_at'   => $vehicle->shift_ended_at?->toISOString(),
            'route_name'       => $vehicle->route_name,
        ]);
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
 