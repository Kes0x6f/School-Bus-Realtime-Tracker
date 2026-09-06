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

it('keeps one driver assigned to one vehicle during reassignment', function () {
    $driver = User::factory()->driver()->create();
    $otherDriver = User::factory()->driver()->create();
    $oldVehicle = Vehicle::factory()->assignedTo($driver)->create();
    $newVehicle = Vehicle::factory()->assignedTo($otherDriver)->create();

    $this->actingAs($this->admin)
        ->postJson('/admin/api/vehicles/' . $newVehicle->id . '/assign-driver', [
            'user_id' => $driver->id,
        ])
        ->assertOk();

    expect($oldVehicle->refresh()->user_id)->toBeNull()
        ->and($newVehicle->refresh()->user_id)->toBe($driver->id)
        ->and(Vehicle::where('user_id', $driver->id)->count())->toBe(1);
});

it('filters expired inactive and differently scoped announcements for students', function () {
    $student = User::factory()->student()->create();
    $vehicle = Vehicle::factory()->onShift()->create([
        'route_name' => VehicleRoute::MANGALDAN->value,
    ]);
    $global = Announcement::factory()->create(['message' => 'Global active notice']);
    $matching = Announcement::factory()
        ->forRoute(VehicleRoute::MANGALDAN)
        ->create(['message' => 'Matching route notice']);
    $otherRoute = Announcement::factory()
        ->forRoute(VehicleRoute::CALASIAO)
        ->create(['message' => 'Other route notice']);
    $expired = Announcement::factory()
        ->expired()
        ->create(['message' => 'Expired notice']);
    $inactive = Announcement::factory()
        ->inactive()
        ->create(['message' => 'Inactive notice']);

    $response = $this->actingAs($student)
        ->get('/student/track/' . $vehicle->id)
        ->assertOk();

    $response->assertSee($global->message)
        ->assertSee($matching->message)
        ->assertDontSee($otherRoute->message)
        ->assertDontSee($expired->message)
        ->assertDontSee($inactive->message);
});
