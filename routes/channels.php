<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('vehicle.{id}', function ($user, $id) {
    return true;
});

Broadcast::channel('vehicles', function ($user) {
    return true;
});

// Public channel — students don't need auth to receive announcements.
// ShouldBroadcastNow uses a plain Channel (not PrivateChannel) so no
// auth check is required here; this entry is just for documentation.
Broadcast::channel('announcements', function () {
    return true;
});