<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Vehicle;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Enums\ShiftEndReason;
use App\Services\ShiftCompletionService;

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

    // ── Brute-force protection ────────────────────────────────────────────────
    // Key combines the lower-cased email + IP so:
    //   • A single attacker cycling IPs is still throttled per email.
    //   • A shared IP (e.g. school WiFi) doesn't lock out legitimate users
    //     just because one person mistyped their password a few times.
    // Limit: 6 attempts per 60 seconds.
    $throttleKey = strtolower($request->input('email')) . '|' . $request->ip();

    if (RateLimiter::tooManyAttempts($throttleKey, 6)) {
        $seconds = RateLimiter::availableIn($throttleKey);

        return redirect()->route('login')
            ->withErrors(['email' => "Too many login attempts. Please try again in {$seconds} seconds."])
            ->withInput($request->only('email'));
    }

    if (Auth::attempt($credentials)) {
        // Clear the rate-limit bucket on successful login so the counter
        // doesn't carry over to the next session.
        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        if (!Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
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

    // Increment the failure counter. Decay window: 60 seconds.
    RateLimiter::hit($throttleKey, 60);

    // Return a generic message — do NOT reveal whether the email exists or
    // whether it was the password that was wrong (account enumeration).
    return redirect()->route('login')
        ->withErrors(['email' => 'Invalid credentials.'])
        ->withInput($request->only('email'));
});

Route::post('/logout', function (Request $request, ShiftCompletionService $shiftCompletion) {
    $user = Auth::user();

    // ── Auto-end shift on driver logout ───────────────────────────────────────
    // Prevents a vehicle from staying on the active list after the driver
    // closes their browser without explicitly ending the shift.
    if ($user && $user->role === 'driver') {
        $vehicle = Vehicle::where('user_id', $user->id)
                          ->first();

        if ($vehicle) {
            $shiftCompletion->complete($vehicle, ShiftEndReason::LOGOUT);
        }
    }

    Auth::logout();

    // Invalidate + regenerate the session token so the old session ID
    // cannot be reused even if someone has a copy of the cookie.
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
});

// ─── Driver ───────────────────────────────────────────────────────────────────

Route::middleware(['auth', 'active', 'role:driver'])->group(function () {
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

Route::middleware(['auth', 'active', 'role:student,admin'])->group(function () {
    Route::get('/student/active-jeeps',     [StudentController::class, 'active']);
    Route::get('/student/track/{id}',       [StudentController::class, 'track']);
    Route::post('/student/change-password', [StudentController::class, 'changePassword']);
});

// ─── Admin ────────────────────────────────────────────────────────────────────

Route::middleware(['auth', 'active', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'index']);

    Route::get( '/api/users',                              [AdminController::class, 'users']);
    Route::post('/api/users',                              [AdminController::class, 'storeUser']);
    Route::get('/api/users/import/template',               [AdminController::class, 'importTemplate']);
    Route::post('/api/users/import',                       [AdminController::class, 'importUsers']);
    Route::post('/api/users/{user}/change-password',       [AdminController::class, 'changePassword']);
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
    Route::get(   '/api/announcements',                           [AnnouncementController::class, 'index']);
    Route::post(  '/api/announcements',                           [AnnouncementController::class, 'store']);
    Route::post(  '/api/announcements/{announcement}/deactivate', [AnnouncementController::class, 'deactivate']);
    Route::delete('/api/announcements/{announcement}',            [AnnouncementController::class, 'destroy']);

});
