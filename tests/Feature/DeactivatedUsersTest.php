<?php

use App\Enums\ShiftEndReason;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

function makeUserForDeactivationTest(string $role): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
    ]);
}

it('denies an inactive driver on the next protected request', function () {
    $driver = makeUserForDeactivationTest('driver');
    Vehicle::create([
        'plate_number' => 'DEACT-DRIVER',
        'user_id' => $driver->id,
    ]);
    $driver->update(['is_active' => false]);

    $this->actingAs($driver)
        ->get('/driver/dashboard')
        ->assertRedirect(route('login'));
});

it('denies an inactive student on the next protected request', function () {
    $student = makeUserForDeactivationTest('student');
    $student->update(['is_active' => false]);

    $this->actingAs($student)
        ->get('/student/active-jeeps')
        ->assertRedirect(route('login'));
});

it('denies an inactive administrator on the next protected request', function () {
    $admin = makeUserForDeactivationTest('admin');
    $admin->update(['is_active' => false]);

    $this->actingAs($admin)
        ->get('/admin/dashboard')
        ->assertRedirect(route('login'));
});

it('returns JSON 403 for an inactive API caller', function () {
    $driver = makeUserForDeactivationTest('driver');
    $vehicle = Vehicle::create([
        'plate_number' => 'DEACT-API',
        'user_id' => $driver->id,
        'shift_active' => true,
    ]);
    $driver->update(['is_active' => false]);

    $this->actingAs($driver)
        ->postJson('/api/gps/update', [
            'vehicle_id' => $vehicle->id,
            'latitude' => 16.05,
            'longitude' => 120.34,
            'speed' => 0,
        ])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Your account has been deactivated.');
});

it('removes all database sessions when an administrator deactivates a user', function () {
    $admin = makeUserForDeactivationTest('admin');
    $target = makeUserForDeactivationTest('student');

    DB::table('sessions')->insert([
        [
            'id' => 'session-one',
            'user_id' => $target->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => 'session-two',
            'user_id' => $target->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ],
    ]);

    $this->actingAs($admin)
        ->postJson('/admin/api/users/' . $target->id . '/deactivate')
        ->assertOk();

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and($target->refresh()->is_active)->toBeFalse();
});

it('ends an active driver shift when the account is deactivated', function () {
    $admin = makeUserForDeactivationTest('admin');
    $driver = makeUserForDeactivationTest('driver');
    $vehicle = Vehicle::create([
        'plate_number' => 'DEACT-SHIFT',
        'user_id' => $driver->id,
        'shift_active' => true,
        'is_active' => true,
        'shift_started_at' => now()->subHour(),
    ]);

    $this->actingAs($admin)
        ->postJson('/admin/api/users/' . $driver->id . '/deactivate')
        ->assertOk();

    $vehicle->refresh();

    expect($vehicle->shift_active)->toBeFalse()
        ->and($vehicle->is_active)->toBeFalse()
        ->and($vehicle->shift_ended_at)->not->toBeNull()
        ->and($vehicle->shifts()->value('end_reason'))->toBe(ShiftEndReason::ACCOUNT_DEACTIVATED->value);
});

it('rejects inactive users from private broadcast authorization', function () {
    $student = makeUserForDeactivationTest('student');
    $student->update(['is_active' => false]);

    $this->actingAs($student)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-user.' . $student->id,
        ])
        ->assertStatus(403);
});

it('prevents an administrator from deactivating their own account', function () {
    $admin = makeUserForDeactivationTest('admin');

    $this->actingAs($admin)
        ->postJson('/admin/api/users/' . $admin->id . '/deactivate')
        ->assertStatus(422);

    expect($admin->refresh()->is_active)->toBeTrue();
});
