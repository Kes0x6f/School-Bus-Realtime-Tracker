<?php

use App\Models\User;
use App\Models\Vehicle;

it('rejects coordinates outside the geographic boundaries', function (string $field, float $value) {
    $driver = User::factory()->driver()->create();
    $vehicle = Vehicle::factory()->assignedTo($driver)->onShift()->create();

    $payload = [
        'vehicle_id' => $vehicle->id,
        'latitude' => 16.05,
        'longitude' => 120.34,
        'speed_mps' => 0,
    ];
    $payload[$field] = $value;

    $this->actingAs($driver)
        ->postJson('/api/gps/update', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'latitude below minimum' => ['latitude', -90.01],
    'latitude above maximum' => ['latitude', 90.01],
    'longitude below minimum' => ['longitude', -180.01],
    'longitude above maximum' => ['longitude', 180.01],
]);

it('rejects a driver trying to update another drivers vehicle', function () {
    $driver = User::factory()->driver()->create();
    $assignedVehicle = Vehicle::factory()->assignedTo($driver)->onShift()->create();
    $otherVehicle = Vehicle::factory()->onShift()->create();

    $this->actingAs($driver)
        ->postJson('/api/gps/update', [
            'vehicle_id' => $otherVehicle->id,
            'latitude' => 16.05,
            'longitude' => 120.34,
            'speed_mps' => 0,
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Vehicle ID does not match your assigned vehicle.');

    expect($assignedVehicle->refresh()->latitude)->toBeNull()
        ->and($otherVehicle->refresh()->latitude)->toBeNull();
});

it('rejects gps updates until the driver starts a shift', function () {
    $driver = User::factory()->driver()->create();
    $vehicle = Vehicle::factory()->assignedTo($driver)->create([
        'shift_active' => false,
    ]);

    $this->actingAs($driver)
        ->postJson('/api/gps/update', [
            'vehicle_id' => $vehicle->id,
            'latitude' => 16.05,
            'longitude' => 120.34,
            'speed_mps' => 0,
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'No active shift. Start a shift before sending GPS updates.');
});
