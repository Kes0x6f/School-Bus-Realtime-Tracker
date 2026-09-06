<?php

use App\Enums\VehicleRoute;
use App\Models\User;
use App\Models\Vehicle;

function makeTrackingVehicle(array $attributes = []): Vehicle
{
    $driver = User::factory()->create([
        'role'      => 'driver',
        'is_active' => true,
    ]);

    return Vehicle::create(array_merge([
        'plate_number'     => 'TRACK-' . uniqid(),
        'user_id'          => $driver->id,
        'shift_active'     => true,
        'shift_started_at' => now()->subMinute(),
        'is_active'        => true,
        'latitude'         => 16.050889,
        'longitude'        => 120.341236,
        'speed_mps'        => 3.0,
        'route_name'       => VehicleRoute::MANGALDAN->value,
    ], $attributes));
}

it('rejects guests from the active vehicle collection', function () {
    $this->getJson('/api/vehicles/active')
        ->assertUnauthorized();
});

it('rejects guests from an individual vehicle endpoint', function () {
    $vehicle = makeTrackingVehicle();

    $this->getJson('/api/vehicles/' . $vehicle->id)
        ->assertUnauthorized();
});

it('allows students to view active vehicle data without exposing assignment ids', function () {
    $student = User::factory()->create([
        'role'      => 'student',
        'is_active' => true,
    ]);
    $vehicle = makeTrackingVehicle();

    $this->actingAs($student)
        ->getJson('/api/vehicles/active')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $vehicle->id)
        ->assertJsonPath('0.user.name', $vehicle->user->name)
        ->assertJsonPath('0.speed_mps', 3)
        ->assertJsonPath('0.speed_kph', 10.8)
        ->assertJsonMissingPath('0.user_id');
});

it('allows administrators to view an individual vehicle contract', function () {
    $admin = User::factory()->create([
        'role'      => 'admin',
        'is_active' => true,
    ]);
    $vehicle = makeTrackingVehicle();

    $this->actingAs($admin)
        ->getJson('/api/vehicles/' . $vehicle->id)
        ->assertOk()
        ->assertJsonPath('id', $vehicle->id)
        ->assertJsonPath('plate_number', $vehicle->plate_number)
        ->assertJsonPath('speed_mps', 3)
        ->assertJsonPath('speed_kph', 10.8)
        ->assertJsonPath('gps_status', 'moving')
        ->assertJsonMissingPath('user_id');
});

it('does not include ended vehicles in the active collection', function () {
    $student = User::factory()->create([
        'role'      => 'student',
        'is_active' => true,
    ]);
    makeTrackingVehicle();
    makeTrackingVehicle([
        'shift_active'   => false,
        'is_active'      => false,
        'shift_ended_at' => now(),
    ]);

    $this->actingAs($student)
        ->getJson('/api/vehicles/active')
        ->assertOk()
        ->assertJsonCount(1);
});

it('rejects drivers from student tracking endpoints', function () {
    $driver = User::factory()->create([
        'role'      => 'driver',
        'is_active' => true,
    ]);
    $vehicle = makeTrackingVehicle();

    $this->actingAs($driver)
        ->getJson('/api/vehicles/active')
        ->assertForbidden();

    $this->actingAs($driver)
        ->getJson('/api/vehicles/' . $vehicle->id)
        ->assertForbidden();
});

it('rejects inactive students from live vehicle endpoints', function () {
    $student = User::factory()->create([
        'role'      => 'student',
        'is_active' => false,
    ]);
    $vehicle = makeTrackingVehicle();

    $this->actingAs($student)
        ->getJson('/api/vehicles/active')
        ->assertForbidden()
        ->assertJsonPath('message', 'Your account has been deactivated.');

    $this->actingAs($student)
        ->getJson('/api/vehicles/' . $vehicle->id)
        ->assertForbidden()
        ->assertJsonPath('message', 'Your account has been deactivated.');
});
