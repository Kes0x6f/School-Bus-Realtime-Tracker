<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;

class StudentController extends Controller
{
     /**
     * Active jeeps page.
     * Only show vehicles where the driver has an active shift.
     * is_active (GPS freshness) is intentionally NOT filtered here —
     * a vehicle that's disconnected but still on shift should still
     * appear in the list (with a disconnected indicator).
     */
     public function active()
    {
        $jeeps = Vehicle::with('user')
            ->where('shift_active', true)
            ->get();
 
        return view('student.active-jeeps', compact('jeeps'));
    }
    
    public function track($id)
    {
        $vehicle = Vehicle::with('user')->findOrFail($id);

        return view('student.track', [
            'jeepId' => $id,
            'vehicle' => $vehicle
        ]);
    }
}
