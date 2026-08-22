<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('vehicle.{id}', function ($user, $id) {
    if (!$user->is_active) {
        return false;
    }

    if (in_array($user->role, ['student', 'admin'])) {
        return true;
    }
 
    if ($user->role === 'driver') {
        return $user->vehicle?->id === (int) $id;
    }
 
    return false;
});

Broadcast::channel('vehicles', function ($user) {
    return $user->is_active && in_array($user->role, ['student', 'admin', 'driver']);
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return $user->is_active && $user->id === (int) $id;
});

// Public channel — students don't need auth to receive announcements.
// ShouldBroadcastNow uses a plain Channel (not PrivateChannel) so no
// auth check is required here; this entry is just for documentation.
Broadcast::channel('announcements', function () {
    return true;
});
