<?php

use App\Enums\ShiftEndReason;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vehicle;

it('preserves a vehicle and its shift history when its user is deleted', function () {
    $driver = User::factory()->create([
        'role'      => 'driver',
        'is_active' => true,
    ]);
    $vehicle = Vehicle::create([
        'plate_number' => 'DELETE-USER',
        'user_id'      => $driver->id,
    ]);
    $shift = Shift::create([
        'vehicle_id' => $vehicle->id,
        'user_id'    => $driver->id,
        'started_at' => now()->subHour(),
        'ended_at'   => now(),
        'end_reason' => ShiftEndReason::MANUAL,
    ]);

    $driver->delete();

    expect($vehicle->refresh()->user_id)->toBeNull()
        ->and(Vehicle::whereKey($vehicle->id)->exists())->toBeTrue()
        ->and(Shift::whereKey($shift->id)->exists())->toBeTrue()
        ->and($shift->refresh()->user_id)->toBeNull();
});
