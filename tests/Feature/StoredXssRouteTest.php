<?php

use App\Enums\VehicleRoute;
use App\Models\User;
use App\Models\Vehicle;

function makeDriverWithVehicle(array $vehicleAttributes = []): array
{
    $driver = User::factory()->create([
        'role' => 'driver',
        'is_active' => true,
    ]);

    $vehicle = Vehicle::create(array_merge([
        'plate_number' => fake()->unique()->bothify('TEST-###'),
        'user_id' => $driver->id,
        'route_name' => null,
        'is_active' => false,
        'shift_active' => false,
    ], $vehicleAttributes));

    return [$driver, $vehicle];
}

it('rejects an unknown route when starting a shift', function () {
    [$driver, $vehicle] = makeDriverWithVehicle();

    $this->actingAs($driver)
        ->postJson('/driver/shift/start', [
            'route_name' => '<img src=x onerror=alert(1)>',
        ])
        ->assertStatus(422);

    expect($vehicle->refresh()->route_name)->toBeNull();
});

it('rejects an unknown route during a shift', function () {
    [$driver, $vehicle] = makeDriverWithVehicle([
        'shift_active' => true,
        'route_name' => VehicleRoute::MANGALDAN->value,
    ]);

    $this->actingAs($driver)
        ->postJson('/api/driver/route', [
            'route_name' => '<svg/onload=alert(1)>',
        ])
        ->assertStatus(422);

    expect($vehicle->refresh()->route_name)->toBe(VehicleRoute::MANGALDAN->value);
});

it('does not accept route changes in GPS updates', function () {
    [$driver, $vehicle] = makeDriverWithVehicle([
        'shift_active' => true,
        'route_name' => VehicleRoute::CALASIAO->value,
    ]);

    $this->actingAs($driver)
        ->postJson('/api/gps/update', [
            'vehicle_id' => $vehicle->id,
            'latitude' => 16.05,
            'longitude' => 120.34,
            'speed_mps' => 0,
            'route_name' => '<script>alert(1)</script>',
        ])
        ->assertOk();

    expect($vehicle->refresh()->route_name)->toBe(VehicleRoute::CALASIAO->value);
});

it('rejects unknown routes from admin vehicle writes', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->postJson('/admin/api/vehicles', [
            'plate_number' => 'ADMIN-001',
            'route_name' => '<img src=x onerror=alert(1)>',
        ])
        ->assertStatus(422);

    expect(Vehicle::where('plate_number', 'ADMIN-001')->exists())->toBeFalse();
});

it('rejects unknown routes from admin vehicle updates', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $vehicle = Vehicle::create([
        'plate_number' => 'ADMIN-002',
        'route_name' => VehicleRoute::SAN_FABIAN->value,
    ]);

    $this->actingAs($admin)
        ->putJson('/admin/api/vehicles/' . $vehicle->id, [
            'plate_number' => 'ADMIN-002',
            'route_name' => '<img src=x onerror=alert(1)>',
        ])
        ->assertStatus(422);

    expect($vehicle->refresh()->route_name)->toBe(VehicleRoute::SAN_FABIAN->value);
});

it('rejects unknown routes from announcement scopes', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->postJson('/admin/api/announcements', [
            'message' => 'Unsafe scope should be rejected',
            'route' => '<svg/onload=alert(1)>',
        ])
        ->assertStatus(422);
});

it('sends a content security policy header', function () {
    $this->get('/')
        ->assertOk()
        ->assertHeader('Content-Security-Policy');
});
