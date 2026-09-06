<?php

use App\Enums\ShiftEndReason;
use App\Enums\VehicleRoute;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

afterEach(function () {
    Carbon::setTestNow();
});

beforeEach(function () {
    config([
        'shifts.gps_stale_seconds' => 180,
        'shifts.auto_end_seconds'  => 1200,
    ]);
});

function makeDriverVehicle(array $attributes = []): Vehicle
{
    $driver = User::factory()->create([
        'role'      => 'driver',
        'is_active' => true,
    ]);

    return Vehicle::create(array_merge([
        'plate_number'     => 'TEST-' . uniqid(),
        'user_id'          => $driver->id,
        'shift_active'     => true,
        'shift_started_at' => now()->subMinutes(2),
        'is_active'        => false,
    ], $attributes));
}

it('resets GPS state when a new shift starts', function () {
    Event::fake();

    $now = Carbon::create(2026, 8, 22, 12, 0, 0);
    Carbon::setTestNow($now);

    $driver = User::factory()->create([
        'role'      => 'driver',
        'is_active' => true,
    ]);
    $vehicle = Vehicle::create([
        'plate_number'  => 'START-RESET',
        'user_id'       => $driver->id,
        'shift_active'  => false,
        'shift_ended_at' => $now->copy()->subHour(),
        'is_active'     => true,
        'latitude'      => 16.05,
        'longitude'     => 120.34,
        'speed_mps'     => 28,
        'last_seen'     => $now->copy()->subMinutes(5),
        'last_moved_at' => $now->copy()->subMinutes(5),
        'route_name'    => VehicleRoute::CALASIAO->value,
    ]);

    $this->actingAs($driver)
        ->postJson('/driver/shift/start', [
            'route_name' => VehicleRoute::MANGALDAN->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.shift_active', true);

    $vehicle->refresh();

    expect($vehicle->shift_active)->toBeTrue()
        ->and($vehicle->shift_started_at->equalTo($now))->toBeTrue()
        ->and($vehicle->shift_ended_at)->toBeNull()
        ->and($vehicle->is_active)->toBeFalse()
        ->and($vehicle->latitude)->toBeNull()
        ->and($vehicle->longitude)->toBeNull()
        ->and($vehicle->speed_mps)->toBeNull()
        ->and($vehicle->last_seen)->toBeNull()
        ->and($vehicle->last_moved_at)->toBeNull()
        ->and($vehicle->route_name)->toBe(VehicleRoute::MANGALDAN->value);
});

it('uses shift start as the inactivity baseline when there is no GPS fix', function (
    int $elapsedMinutes,
    bool $shouldEnd,
) {
    $now = Carbon::create(2026, 8, 22, 12, 0, 0);
    Carbon::setTestNow($now);

    $vehicle = makeDriverVehicle([
        'shift_started_at' => $now->copy()->subMinutes($elapsedMinutes),
        'last_seen'        => null,
        'is_active'        => false,
    ]);

    $this->artisan('vehicles:check-inactive')->assertSuccessful();

    $vehicle->refresh();

    expect($vehicle->shift_active)->toBe(!$shouldEnd)
        ->and($vehicle->is_active)->toBeFalse()
        ->and($vehicle->shifts()->count())->toBe($shouldEnd ? 1 : 0);

    if ($shouldEnd) {
        expect($vehicle->shifts()->value('end_reason'))
            ->toBe(ShiftEndReason::AUTO->value);
    }
})->with([
    'two minutes'       => [2, false],
    'three minutes'     => [3, false],
    'nineteen minutes'  => [19, false],
    'twenty minutes'    => [20, true],
]);

it('does not use a previous shift GPS timestamp for the current shift', function () {
    $shiftStartedAt = Carbon::create(2026, 8, 22, 12, 0, 0);
    Carbon::setTestNow($shiftStartedAt->copy()->addMinutes(19));

    $vehicle = makeDriverVehicle([
        'shift_started_at' => $shiftStartedAt,
        'last_seen'        => $shiftStartedAt->copy()->subMinute(),
        'is_active'        => true,
    ]);

    $this->artisan('vehicles:check-inactive')->assertSuccessful();

    $vehicle->refresh();

    expect($vehicle->shift_active)->toBeTrue()
        ->and($vehicle->is_active)->toBeFalse()
        ->and($vehicle->shifts()->count())->toBe(0);
});

it('marks an active vehicle disconnected at the stale threshold', function () {
    $shiftStartedAt = Carbon::create(2026, 8, 22, 12, 0, 0);
    Carbon::setTestNow($shiftStartedAt->copy()->addMinutes(3));

    $vehicle = makeDriverVehicle([
        'shift_started_at' => $shiftStartedAt,
        'last_seen'        => $shiftStartedAt,
        'is_active'        => true,
    ]);

    $this->artisan('vehicles:check-inactive')->assertSuccessful();

    expect($vehicle->refresh()->is_active)->toBeFalse()
        ->and($vehicle->shift_active)->toBeTrue()
        ->and($vehicle->shifts()->count())->toBe(0);
});

it('keeps a shift active when a recent GPS update exists', function () {
    $shiftStartedAt = Carbon::create(2026, 8, 22, 12, 0, 0);
    Carbon::setTestNow($shiftStartedAt->copy()->addMinutes(20));

    $vehicle = makeDriverVehicle([
        'shift_started_at' => $shiftStartedAt,
        'last_seen'        => $shiftStartedAt->copy()->addMinutes(19),
        'is_active'        => true,
    ]);

    $this->artisan('vehicles:check-inactive')->assertSuccessful();

    expect($vehicle->refresh()->shift_active)->toBeTrue()
        ->and($vehicle->is_active)->toBeTrue()
        ->and($vehicle->shifts()->count())->toBe(0);
});

it('logs and auto-ends an active shift with a missing start timestamp', function () {
    Log::spy();
    Carbon::setTestNow(Carbon::create(2026, 8, 22, 12, 0, 0));

    $vehicle = makeDriverVehicle([
        'shift_started_at' => null,
        'last_seen'        => null,
        'is_active'        => true,
    ]);

    $this->artisan('vehicles:check-inactive')->assertSuccessful();

    $vehicle->refresh();

    expect($vehicle->shift_active)->toBeFalse()
        ->and($vehicle->is_active)->toBeFalse()
        ->and($vehicle->shift_ended_at)->not->toBeNull()
        ->and($vehicle->shift_started_at)->not->toBeNull()
        ->and($vehicle->shifts()->count())->toBe(1)
        ->and($vehicle->shifts()->value('duration_seconds'))->toBe(0)
        ->and($vehicle->shifts()->value('end_reason'))->toBe(ShiftEndReason::AUTO->value);

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('does not create a duplicate history row when inactive cleanup is retried', function () {
    Carbon::setTestNow(Carbon::create(2026, 8, 22, 12, 20, 0));

    $vehicle = makeDriverVehicle([
        'shift_started_at' => now()->subMinutes(20),
        'last_seen'        => null,
    ]);

    $this->artisan('vehicles:check-inactive')->assertSuccessful();
    $this->artisan('vehicles:check-inactive')->assertSuccessful();

    expect(Shift::where('vehicle_id', $vehicle->id)->count())->toBe(1);
});
