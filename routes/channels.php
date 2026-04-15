<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('vehicle.{id}', function ($user, $id) {
    return true;
});

Broadcast::channel('vehicles', function ($user) {
    return true;
});