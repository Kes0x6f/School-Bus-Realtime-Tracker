<?php

use App\Enums\ShiftEndReason;
use App\Events\VehicleStatusChanged;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShiftCompletionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;

function makeShiftCompletionDriverVehicle(array $attributes = []): array
{
    $driver = User::factory()->create([
        'role'      => 'driver',
        'is_active' => true,
    ]);

    $vehicle = Vehicle::create(array_merge([
        'plate_number'     => 'COMPLETE-' . uniqid(),
        'user_id'          => $driver->id,
        'shift_active'     => false,
        'is_active'        => false,
        'shift_started_at' => null,
    ], $attributes));

    return [$driver, $vehicle];
}

it('creates one current shift history row when a shift starts', function () {
    Event::fake();
    [$driver, $vehicle] = makeShiftCompletionDriverVehicle();

    $this->actingAs($driver)
        ->postJson('/driver/shift/start', [])
        ->assertOk()
        ->assertJsonPath('data.shift_active', true);

    $this->actingAs($driver)
        ->postJson('/driver/shift/start', [])
        ->assertUnprocessable();

    $vehicle->refresh();
    $shift = Shift::find($vehicle->current_shift_id);

    expect($shift)->not->toBeNull()
        ->and($shift->vehicle_id)->toBe($vehicle->id)
        ->and($shift->user_id)->toBe($driver->id)
        ->and($shift->ended_at)->toBeNull()
        ->and($shift->active_marker)->toBeTrue()
        ->and(Shift::where('vehicle_id', $vehicle->id)->count())->toBe(1);
});

it('completes the current shift row and makes repeated manual ends idempotent', function () {
    Event::fake();
    [$driver, $vehicle] = makeShiftCompletionDriverVehicle();

    $this->actingAs($driver)->postJson('/driver/shift/start', [])->assertOk();

    $first = $this->actingAs($driver)
        ->postJson('/driver/shift/end', [])
        ->assertOk()
        ->assertJsonPath('data.already_ended', false);

    $second = $this->actingAs($driver)
        ->postJson('/driver/shift/end', [])
        ->assertOk()
        ->assertJsonPath('data.already_ended', true);

    $this->artisan('vehicles:check-inactive')->assertSuccessful();

    $this->actingAs($driver)
        ->post('/logout')
        ->assertRedirect('/');

    $vehicle->refresh();

    expect($first->json('data.shift_id'))->toBe($second->json('data.shift_id'))
        ->and($vehicle->shift_active)->toBeFalse()
        ->and($vehicle->current_shift_id)->toBeNull()
        ->and(Shift::where('vehicle_id', $vehicle->id)->count())->toBe(1)
        ->and(Shift::where('vehicle_id', $vehicle->id)->value('active_marker'))->toBeNull()
        ->and(Shift::where('vehicle_id', $vehicle->id)->value('end_reason'))
            ->toBe(ShiftEndReason::MANUAL->value);

    Event::assertDispatchedTimes(VehicleStatusChanged::class, 2);
});

it('rolls back the history update when vehicle completion fails', function () {
    Event::fake();
    [$driver, $vehicle] = makeShiftCompletionDriverVehicle([
        'shift_active'     => true,
        'is_active'        => true,
        'shift_started_at' => now()->subHour(),
    ]);

    $shift = Shift::create([
        'vehicle_id' => $vehicle->id,
        'user_id'    => $driver->id,
        'started_at' => $vehicle->shift_started_at,
    ]);
    $vehicle->update(['current_shift_id' => $shift->id]);

    $failVehicleUpdate = true;
    DB::listen(function (QueryExecuted $query) use (&$failVehicleUpdate): void {
        $sql = strtolower(ltrim($query->sql));

        if ($failVehicleUpdate
            && str_starts_with($sql, 'update')
            && str_contains($sql, 'vehicles')) {
            $failVehicleUpdate = false;
            throw new RuntimeException('forced vehicle update failure');
        }
    });

    expect(fn () => app(ShiftCompletionService::class)
        ->complete($vehicle, ShiftEndReason::AUTO))
        ->toThrow(RuntimeException::class, 'forced vehicle update failure');

    expect($vehicle->refresh()->shift_active)->toBeTrue()
        ->and($vehicle->current_shift_id)->toBe($shift->id)
        ->and($shift->refresh()->ended_at)->toBeNull()
        ->and($shift->end_reason)->toBeNull();

    Event::assertNotDispatched(VehicleStatusChanged::class);
});

it('rolls back the new shift row when starting the vehicle fails', function () {
    Event::fake();
    [$driver, $vehicle] = makeShiftCompletionDriverVehicle();

    $failVehicleUpdate = true;
    DB::listen(function (QueryExecuted $query) use (&$failVehicleUpdate): void {
        $sql = strtolower(ltrim($query->sql));

        if ($failVehicleUpdate
            && str_starts_with($sql, 'update')
            && str_contains($sql, 'vehicles')) {
            $failVehicleUpdate = false;
            throw new RuntimeException('forced shift start failure');
        }
    });

    expect(fn () => app(ShiftCompletionService::class)
        ->startForDriver($driver->id))
        ->toThrow(RuntimeException::class, 'forced shift start failure');

    expect($vehicle->refresh()->shift_active)->toBeFalse()
        ->and($vehicle->current_shift_id)->toBeNull()
        ->and(Shift::where('vehicle_id', $vehicle->id)->count())->toBe(0);

    Event::assertNotDispatched(VehicleStatusChanged::class);
});

it('uses the same completion service for logout', function () {
    Event::fake();
    [$driver, $vehicle] = makeShiftCompletionDriverVehicle([
        'shift_active'     => true,
        'shift_started_at' => now()->subHour(),
    ]);

    $this->actingAs($driver)
        ->post('/logout')
        ->assertRedirect('/');

    expect($vehicle->refresh()->shift_active)->toBeFalse()
        ->and($vehicle->shifts()->count())->toBe(1)
        ->and($vehicle->shifts()->value('end_reason'))
            ->toBe(ShiftEndReason::LOGOUT->value);
});

it('enforces one active shift row per vehicle at the database layer', function () {
    [$driver, $vehicle] = makeShiftCompletionDriverVehicle();

    Shift::create([
        'vehicle_id'    => $vehicle->id,
        'user_id'       => $driver->id,
        'started_at'    => now()->subMinute(),
        'active_marker' => true,
    ]);

    expect(fn () => Shift::create([
        'vehicle_id'    => $vehicle->id,
        'user_id'       => $driver->id,
        'started_at'    => now(),
        'active_marker' => true,
    ]))->toThrow(QueryException::class);
});

it('enforces a valid one-to-one current shift reference', function () {
    [$driver, $vehicle] = makeShiftCompletionDriverVehicle();
    [, $otherVehicle] = makeShiftCompletionDriverVehicle();

    $shift = Shift::create([
        'vehicle_id'    => $vehicle->id,
        'user_id'       => $driver->id,
        'started_at'    => now(),
        'active_marker' => true,
    ]);

    $vehicle->update(['current_shift_id' => $shift->id]);

    expect(fn () => $otherVehicle->update(['current_shift_id' => $shift->id]))
        ->toThrow(QueryException::class);

    expect(fn () => Vehicle::factory()->create([
        'current_shift_id' => PHP_INT_MAX,
    ]))->toThrow(QueryException::class);
});
