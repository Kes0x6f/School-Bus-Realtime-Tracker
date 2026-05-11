<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Events\AnnouncementBroadcast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    /**
     * GET /admin/api/announcements
     * Returns all announcements (active + inactive) for the admin panel.
     */
    public function index()
    {
        $announcements = Announcement::with('creator:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($a) => [
                'id'         => $a->id,
                'message'    => $a->message,
                'route'      => $a->route,
                'is_active'  => $a->is_active,
                'expires_at' => $a->expires_at?->toISOString(),
                'created_at' => $a->created_at->toISOString(),
                'created_by' => $a->creator?->name ?? 'Admin',
            ]);

        return response()->json($announcements);
    }

    /**
     * POST /admin/api/announcements
     * Create and immediately broadcast a new announcement.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'message'    => 'required|string|max:300',
            'route'      => 'nullable|string|max:100',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $announcement = Announcement::create([
            'message'    => $validated['message'],
            'route'      => $validated['route'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'is_active'  => true,
            'created_by' => Auth::id(),
        ]);

        broadcast(new AnnouncementBroadcast($announcement, 'created'));

        return response()->json(['status' => 'ok', 'announcement' => $announcement], 201);
    }

    /**
     * POST /admin/api/announcements/{id}/deactivate
     * Deactivate an announcement and notify connected students to hide it.
     */
    public function deactivate(Announcement $announcement)
    {
        $announcement->update(['is_active' => false]);
        $announcement->refresh();

        broadcast(new AnnouncementBroadcast($announcement, 'deactivated'));

        return response()->json(['status' => 'ok']);
    }

    /**
     * DELETE /admin/api/announcements/{id}
     * Hard delete — no broadcast needed since deactivate already hid it.
     */
    public function destroy(Announcement $announcement)
    {
        $announcement->delete();
        return response()->json(['status' => 'ok']);
    }
}