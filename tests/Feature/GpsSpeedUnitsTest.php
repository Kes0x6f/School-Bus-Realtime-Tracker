<?php

use App\Events\VehicleLocationUpdated;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Event;

function makeSpeedTestVehicle(array $attributes = []): array
{
    $driver = User::factory()->create([
        'role'      => 'driver',
        'is_active' => true,
    ]);

    $vehicle = Vehicle::create(array_merge([
        'plate_number'     => 'SPEED-' . uniqid(),
        'user_id'          => $driver->id,
        'shift_active'     => true,
        'shift_started_at' => now()->subMinute(),
        'is_active'        => false,
    ], $attributes));

    return [$driver, $vehicle];
}

it('accepts and persists speed_mps while returning explicit display units', function () {
    Event::fake();
    [$driver, $vehicle] = makeSpeedTestVehicle();

    $this->actingAs($driver)
        ->postJson('/api/gps/update', [
            'vehicle_id' => $vehicle->id,
            'latitude'   => 16.0509,
            'longitude'  => 120.3412,
            'speed_mps'  => 3,
        ])
        ->assertOk()
        ->assertJsonPath('data.speed_mps', 3)
        ->assertJsonPath('data.speed_kph', 10.8)
        ->assertJsonPath('data.gps_status', 'moving');

    expect($vehicle->refresh()->speed_mps)->toBe(3.0);

    Event::assertDispatched(VehicleLocationUpdated::class, function (VehicleLocationUpdated $event) use ($vehicle): bool {
        return $event->vehicle->is($vehicle)
            && $event->vehicle->speed_mps === 3.0
            && $event->broadcastWith()['speed_kph'] === 10.8;
    });
});

it('rejects the ambiguous legacy speed request field', function () {
    [$driver, $vehicle] = makeSpeedTestVehicle();

    $this->actingAs($driver)
        ->postJson('/api/gps/update', [
            'vehicle_id' => $vehicle->id,
            'latitude'   => 16.0509,
            'longitude'  => 120.3412,
            'speed'      => 3,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['speed']);

    expect($vehicle->refresh()->speed_mps)->toBeNull();
});

it('rejects negative and implausibly high speed_mps values', function (float $speedMps) {
    [$driver, $vehicle] = makeSpeedTestVehicle();

    $this->actingAs($driver)
        ->postJson('/api/gps/update', [
            'vehicle_id' => $vehicle->id,
            'latitude'   => 16.0509,
            'longitude'  => 120.3412,
            'speed_mps'  => $speedMps,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['speed_mps']);
})->with([
    'negative' => -0.01,
    'too fast' => Vehicle::MAX_SPEED_MPS + 0.01,
]);

it('uses the mps movement threshold for gps status classification', function () {
    $belowThreshold = Vehicle::MOVING_THRESHOLD_MPS - 0.001;
    $atThreshold = Vehicle::MOVING_THRESHOLD_MPS;

    $vehicle = new Vehicle([
        'shift_active'  => true,
        'is_active'     => true,
        'speed_mps'     => $belowThreshold,
        'last_moved_at' => now()->subMinute(),
    ]);

    expect($vehicle->gps_status)->toBe('traffic');

    $vehicle->speed_mps = $atThreshold;

    expect($vehicle->gps_status)->toBe('moving');
});
