<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vehicle;


class VehicleController extends Controller
{
    public function index()
    {
        $vehicles = Vehicle::get()->map(function ($vehicle) {

            $vehicle->status =
                now()->diffInSeconds($vehicle->last_seen) < 60
                ? 'active'
                : 'offline';

            return $vehicle;
        });

        return response()->json($vehicles);
    }
//still unused
    public function activeVehicles()
    {
        $vehicles = Vehicle::where('last_seen', '>=', now()->subSeconds(60))
            ->select('id','plate_number','latitude','longitude','speed','last_seen')
            ->get();

        return response()->json($vehicles);
    }

    public function show($id)
    {
        $vehicle = Vehicle::select(
            'id',
            'plate_number',
            'latitude',
            'longitude',
            'speed',
            'last_seen'
        )->findOrFail($id);

        return response()->json($vehicle);
    }

}
