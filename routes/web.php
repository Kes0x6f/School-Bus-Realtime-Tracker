<?php

use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\GpsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use App\Models\Vehicle;
use App\Http\Controllers\Api\ShiftController;


Broadcast::routes(['middleware' => ['web']]);

require base_path('routes/channels.php');

Route::post('/login', function (Request $request){
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (Auth::attempt($credentials)) {
        $request->session()->regenerate();

        $user = Auth::user();

        if ($user->role === 'driver') {
            return redirect('/driver/dashboard');
        }

        return redirect('/student/active-jeeps');
    }

    return back()->withErrors([
        'email' => 'Invalid credentials',
    ]);
});

Route::post('/logout', function (Request $request) {
    Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
});

Route::get('/', function () {
     return view('login'); 
})->name('login');


Route::get('/driver/dashboard', function () {
     $vehicle = auth()->user()->vehicle;

     if (!$vehicle) {
        abort(403, 'No vehicle assigned to this driver');
    }

    return view('driver.dashboard', [
        'vehicleId' => $vehicle->id,
        'vehicle' => $vehicle
    ]);

})->middleware('auth');


Route::get('/student/track/{id}', function ($id) {

     $vehicle = Vehicle::with('user')->findOrFail($id);

    return view('student.track', [
        'jeepId' => $id,
        'vehicle' => $vehicle
    ]);

});

Route::get('/student/active-jeeps', [StudentController::class, 'active'])
    ->middleware('auth');


use App\Events\VehicleLocationUpdated;

Route::get('/debug-broadcast', function () {
    $vehicle = Vehicle::first();

    if (!$vehicle) {
        return "No vehicle found";
    }

    $vehicle->latitude += 0.0001;
    $vehicle->longitude += 0.0001;
    $vehicle->last_seen = now();
    $vehicle->save();

    event(new VehicleLocationUpdated($vehicle));

    return "Broadcast sent for vehicle ID: " . $vehicle->id;
});

Route::middleware('auth')->group(function () {
    Route::post('/driver/shift/start', [ShiftController::class, 'start']);
    Route::post('/driver/shift/end',   [ShiftController::class, 'end']);
});