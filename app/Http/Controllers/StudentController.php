<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StudentController extends Controller
{
    /**
     * Active jeeps page.
     * Passes active announcements so the blade can seed the banner
     * without a separate API call on page load.
     */
    public function active()
    {
        $jeeps = Vehicle::with('user')
            ->where('shift_active', true)
            ->get();

        $announcements = Announcement::active()
            ->orderByDesc('created_at')
            ->get(['id', 'message', 'route', 'expires_at']);

        return view('student.active-jeeps', compact('jeeps', 'announcements'));
    }

    public function track($id)
    {
        $vehicle = Vehicle::with('user')->findOrFail($id);

        $announcements = Announcement::active()
            ->where(function ($q) use ($vehicle) {
                $q->whereNull('route')
                  ->orWhere('route', $vehicle->route_name);
            })
            ->orderByDesc('created_at')
            ->get(['id', 'message', 'route', 'expires_at']);

        return view('student.track', [
            'jeepId'        => $id,
            'vehicle'       => $vehicle,
            'announcements' => $announcements,
        ]);
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = auth()->user();

        // Verify the current password before allowing the change.
        // This prevents someone who grabbed an unlocked phone from
        // silently locking the real owner out of their account.
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your current password is incorrect.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json(['status' => 'success']);
    }
}