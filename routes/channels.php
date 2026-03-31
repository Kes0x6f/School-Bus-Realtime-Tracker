<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('vehicle.{id}', function ($user, $id) {
    return $user->vehicle && $user->vehicle->id == $id
        || $user->role === 'student';
});