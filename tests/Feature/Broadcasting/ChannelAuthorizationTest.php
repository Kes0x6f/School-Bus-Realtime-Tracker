<?php

use App\Models\User;
use App\Models\Vehicle;

it('allows active students and administrators to subscribe to vehicle updates', function (string $role) {
    $user = User::factory()->state(['role' => $role])->create();
    $vehicle = Vehicle::factory()->onShift()->create();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-vehicle.' . $vehicle->id,
        ])
        ->assertOk();
})->with(['student', 'admin']);

it('only allows a driver to subscribe to their assigned vehicle channel', function () {
    $driver = User::factory()->driver()->create();
    $ownVehicle = Vehicle::factory()->assignedTo($driver)->onShift()->create();
    $otherVehicle = Vehicle::factory()->onShift()->create();

    $this->actingAs($driver)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-vehicle.' . $ownVehicle->id,
        ])
        ->assertOk();

    $this->actingAs($driver)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-vehicle.' . $otherVehicle->id,
        ])
        ->assertForbidden();
});

it('rejects inactive users from every protected broadcast channel', function () {
    $user = User::factory()->student()->inactive()->create();
    $vehicle = Vehicle::factory()->onShift()->create();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-vehicle.' . $vehicle->id,
        ])
        ->assertForbidden();
});
