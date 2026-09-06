<?php

use App\Models\User;
use App\Models\Vehicle;

it('returns only the documented public fields for active vehicle tracking', function () {
    $student = User::factory()->student()->create();
    $driver = User::factory()->driver()->create();
    $vehicle = Vehicle::factory()->assignedTo($driver)->onShift()->create([
        'latitude' => 16.050889,
        'longitude' => 120.341236,
        'speed_mps' => 3.0,
        'last_seen' => now(),
        'last_moved_at' => now(),
    ]);

    $this->actingAs($student)
        ->getJson('/api/vehicles/active')
        ->assertOk()
        ->assertJsonStructure([
            '*' => [
                'id', 'route_name', 'is_full', 'user', 'latitude', 'longitude',
                'speed_mps', 'speed_kph', 'last_seen', 'shift_active',
                'is_active', 'gps_status', 'shift_started_at',
            ],
        ])
        ->assertJsonPath('0.id', $vehicle->id)
        ->assertJsonPath('0.user.name', $driver->name)
        ->assertJsonPath('0.speed_kph', 10.8)
        ->assertJsonMissingPath('0.user_id')
        ->assertJsonMissingPath('0.driver_name')
        ->assertJsonMissingPath('0.current_shift_id');
});

it('returns the documented single-vehicle tracking contract', function () {
    $student = User::factory()->student()->create();
    $vehicle = Vehicle::factory()->onShift()->create([
        'latitude' => 16.050889,
        'longitude' => 120.341236,
        'speed_mps' => null,
    ]);

    $this->actingAs($student)
        ->getJson('/api/vehicles/' . $vehicle->id)
        ->assertOk()
        ->assertJsonStructure([
            'id', 'plate_number', 'latitude', 'longitude', 'speed_mps', 'speed_kph',
            'last_seen', 'shift_active', 'is_active', 'gps_status',
            'shift_started_at', 'shift_ended_at', 'route_name',
        ])
        ->assertJsonPath('id', $vehicle->id)
        ->assertJsonPath('speed_kph', null)
        ->assertJsonMissingPath('user_id')
        ->assertJsonMissingPath('current_shift_id');
});
