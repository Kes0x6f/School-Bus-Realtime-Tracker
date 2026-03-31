<?php

namespace App\Http\Controllers;
use App\Models\Vehicle;

use Illuminate\Http\Request;

class StudentController extends Controller
{
     public function active()
    {
        $jeeps = Vehicle::where('last_seen', '>=', now()->subSeconds(60))->get();

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
