<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use App\Models\Shift;
use App\Models\Vehicle;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Events\VehicleStatusChanged;

Broadcast::routes(['middleware' => ['web']]);

require base_path('routes/channels.php');

// ─── Public ───────────────────────────────────────────────────────────────────

Route::get('/', function () {
    return view('login');
})->name('login');

// Safety net: Laravel's auth middleware redirects unauthenticated users to
// the named 'login' route — which is '/' — but if the browser ever lands on
// GET /login directly (e.g. after a failed POST), this prevents a 404.
Route::get('/login', function () {
    return redirect()->route('login');
});

Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    if (Auth::attempt($credentials)) {
        $request->session()->regenerate();

        if (!Auth::user()->is_active) {
            Auth::logout();
            return redirect()->route('login')
                ->withErrors(['email' => 'Your account has been deactivated.'])
                ->withInput($request->only('email'));
        }

        return match (Auth::user()->role) {
            'driver' => redirect('/driver/dashboard'),
            'admin'  => redirect('/admin/dashboard'),
            default  => redirect('/student/active-jeeps'),
        };
    }

    return redirect()->route('login')
        ->withErrors(['email' => 'Invalid credentials.'])
        ->withInput($request->only('email'));
});

Route::post('/logout', function (Request $request) {
    $user = Auth::user();

    if ($user && $user->role === 'driver') {
        $vehicle = Vehicle::where('user_id', $user->id)
                          ->where('shift_active', true)
                          ->first();

        if ($vehicle) {
            Shift::log($vehicle, 'logout');

            $vehicle->update([
                'shift_active'   => false,
                'shift_ended_at' => now(),
                'is_active'      => false,
            ]);

            $vehicle->refresh();
            broadcast(new VehicleStatusChanged($vehicle));
        }
    }

    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
});

// ─── Driver ───────────────────────────────────────────────────────────────────

Route::middleware(['auth', 'role:driver'])->group(function () {
    Route::get('/driver/dashboard', function () {
        $vehicle = auth()->user()->vehicle;
        if (!$vehicle) abort(403, 'No vehicle assigned to this driver.');
        return view('driver.dashboard', [
            'vehicleId' => $vehicle->id,
            'vehicle'   => $vehicle,
        ]);
    });

    Route::post('/driver/shift/start', [ShiftController::class, 'start']);
    Route::post('/driver/shift/end',   [ShiftController::class, 'end']);
});

// ─── Student ──────────────────────────────────────────────────────────────────

Route::middleware(['auth', 'role:student,admin'])->group(function () {
    Route::get('/student/active-jeeps', [StudentController::class, 'active']);
    Route::get('/student/track/{id}',   [StudentController::class, 'track']);
});

// ─── Admin ────────────────────────────────────────────────────────────────────

Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'index']);

    Route::get( '/api/users',                              [AdminController::class, 'users']);
    Route::post('/api/users',                              [AdminController::class, 'storeUser']);
    Route::put( '/api/users/{user}',                       [AdminController::class, 'updateUser']);
    Route::post('/api/users/{user}/deactivate',            [AdminController::class, 'deactivateUser']);
    Route::post('/api/users/{user}/reactivate',            [AdminController::class, 'reactivateUser']);
    Route::post('/api/users/{user}/assign-vehicle',        [AdminController::class, 'assignVehicle']);

    Route::get(   '/api/vehicles',                         [AdminController::class, 'vehicleList']);
    Route::post(  '/api/vehicles',                         [AdminController::class, 'storeVehicle']);
    Route::put(   '/api/vehicles/{vehicle}',               [AdminController::class, 'updateVehicle']);
    Route::delete('/api/vehicles/{vehicle}',               [AdminController::class, 'destroyVehicle']);
    Route::post(  '/api/vehicles/{vehicle}/assign-driver', [AdminController::class, 'assignDriver']);

    Route::get('/api/shifts',    [AdminController::class, 'shifts']);
    Route::get('/api/analytics', [AdminController::class, 'analytics']);

    // ─── Announcements ─────────────────────────────────────────────────────────
    Route::get(   '/api/announcements',                        [AnnouncementController::class, 'index']);
    Route::post(  '/api/announcements',                        [AnnouncementController::class, 'store']);
    Route::post(  '/api/announcements/{announcement}/deactivate', [AnnouncementController::class, 'deactivate']);
    Route::delete('/api/announcements/{announcement}',         [AnnouncementController::class, 'destroy']);
});
