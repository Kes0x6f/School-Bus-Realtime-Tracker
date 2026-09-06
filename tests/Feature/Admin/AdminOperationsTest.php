<?php

use App\Enums\VehicleRoute;
use App\Events\AnnouncementBroadcast;
use App\Events\UserAccessRevoked;
use App\Models\Announcement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    Event::fake();
    $this->admin = User::factory()->admin()->create();
});

it('creates, updates, assigns, and changes passwords for managed users', function () {
    $created = $this->actingAs($this->admin)
        ->postJson('/admin/api/users', [
            'name' => 'Driver One',
            'email' => 'driver-one@example.com',
            'password' => 'password123',
            'role' => 'driver',
        ])
        ->assertCreated()
        ->json('user');

    $user = User::findOrFail($created['id']);
    $vehicle = Vehicle::factory()->create();

    $this->actingAs($this->admin)
        ->putJson('/admin/api/users/' . $user->id, [
            'name' => 'Updated Driver',
            'email' => $user->email,
            'role' => 'driver',
        ])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson('/admin/api/users/' . $user->id . '/assign-vehicle', [
            'vehicle_id' => $vehicle->id,
        ])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson('/admin/api/users/' . $user->id . '/change-password', [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertOk();

    expect($user->refresh()->name)->toBe('Updated Driver')
        ->and($vehicle->refresh()->user_id)->toBe($user->id)
        ->and(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

it('protects active vehicles from deletion and permits ended vehicles to be removed', function () {
    $activeVehicle = Vehicle::factory()->onShift()->create();
    $endedVehicle = Vehicle::factory()->ended()->create();

    $this->actingAs($this->admin)
        ->deleteJson('/admin/api/vehicles/' . $activeVehicle->id)
        ->assertStatus(422);

    $this->actingAs($this->admin)
        ->deleteJson('/admin/api/vehicles/' . $endedVehicle->id)
        ->assertOk();

    expect(Vehicle::find($activeVehicle->id))->not->toBeNull()
        ->and(Vehicle::find($endedVehicle->id))->toBeNull();
});

it('validates and broadcasts announcement lifecycle operations', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/admin/api/announcements', [
            'message' => 'Route service update',
            'route' => VehicleRoute::MANGALDAN->value,
        ])
        ->assertCreated();

    $announcement = Announcement::findOrFail($response->json('announcement.id'));

    $this->actingAs($this->admin)
        ->postJson('/admin/api/announcements/' . $announcement->id . '/deactivate')
        ->assertOk();

    expect($announcement->refresh()->is_active)->toBeFalse();
    Event::assertDispatched(AnnouncementBroadcast::class);
});

it('reports invalid rows without creating them during CSV import', function () {
    $csv = implode("\n", [
        'name,email,password,role',
        'Valid Student,valid-import@example.com,password123,student',
        'Broken Row,not-an-email,short,unknown',
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/api/users/import', [
            'csv_file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
        ])
        ->assertOk()
        ->assertJsonPath('created', 1)
        ->assertJsonCount(1, 'errors');

    expect(User::where('email', 'valid-import@example.com')->exists())->toBeTrue()
        ->and(User::where('email', 'not-an-email')->exists())->toBeFalse();
});

it('deactivates and reactivates a managed account while revoking access', function () {
    $target = User::factory()->student()->create();

    $this->actingAs($this->admin)
        ->postJson('/admin/api/users/' . $target->id . '/deactivate')
        ->assertOk();

    expect($target->refresh()->is_active)->toBeFalse();
    Event::assertDispatched(UserAccessRevoked::class, fn (UserAccessRevoked $event) => $event->userId === $target->id);

    $this->actingAs($this->admin)
        ->postJson('/admin/api/users/' . $target->id . '/reactivate')
        ->assertOk();

    expect($target->refresh()->is_active)->toBeTrue();
});
