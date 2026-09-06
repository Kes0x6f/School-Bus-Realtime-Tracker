<?php

use App\Enums\VehicleRoute;
use App\Models\Announcement;
use App\Models\User;
use App\Models\Vehicle;

function makeProtectedRouteFixtures(): array
{
    $driver = User::factory()->driver()->create();
    $idleDriver = User::factory()->driver()->create();
    $student = User::factory()->student()->create();
    $admin = User::factory()->admin()->create();
    $targetUser = User::factory()->student()->create();
    $targetDriver = User::factory()->driver()->create();

    return [
        'driver' => $driver,
        'idleDriver' => $idleDriver,
        'student' => $student,
        'admin' => $admin,
        'targetUser' => $targetUser,
        'targetDriver' => $targetDriver,
        'vehicle' => Vehicle::factory()->assignedTo($driver)->onShift()->create(),
        'idleVehicle' => Vehicle::factory()->assignedTo($idleDriver)->create(),
        'adminVehicle' => Vehicle::factory()->create(),
        'endedVehicle' => Vehicle::factory()->ended()->create(),
        'announcement' => Announcement::factory()->create(['created_by' => $admin->id]),
    ];
}

function protectedRoutePath(string $path, array $fixtures): string
{
    return strtr($path, [
        '{vehicle}' => (string) $fixtures['vehicle']->id,
        '{adminVehicle}' => (string) $fixtures['adminVehicle']->id,
        '{endedVehicle}' => (string) $fixtures['endedVehicle']->id,
        '{user}' => (string) $fixtures['targetUser']->id,
        '{driver}' => (string) $fixtures['targetDriver']->id,
        '{announcement}' => (string) $fixtures['announcement']->id,
    ]);
}

function protectedRoutePayload(string $case, array $fixtures): array
{
    return match ($case) {
        'start shift' => ['route_name' => VehicleRoute::MANGALDAN->value],
        'GPS update' => [
            'vehicle_id' => $fixtures['vehicle']->id,
            'latitude' => 16.0509,
            'longitude' => 120.3412,
            'speed_mps' => 3,
        ],
        'occupancy update' => ['is_full' => true],
        'route update' => ['route_name' => VehicleRoute::CALASIAO->value],
        'student password update' => [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ],
        'create user' => [
            'name' => 'Managed User',
            'email' => 'managed@example.com',
            'password' => 'password123',
            'role' => 'student',
        ],
        'change managed password' => [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ],
        'update user' => [
            'name' => $fixtures['targetUser']->name,
            'email' => $fixtures['targetUser']->email,
            'role' => 'student',
        ],
        'assign vehicle to user' => ['vehicle_id' => $fixtures['adminVehicle']->id],
        'create vehicle' => [
            'plate_number' => 'AUTH-MATRIX-1',
            'route_name' => VehicleRoute::MANGALDAN->value,
        ],
        'update vehicle' => [
            'plate_number' => $fixtures['adminVehicle']->plate_number,
            'route_name' => VehicleRoute::CALASIAO->value,
        ],
        'assign driver to vehicle' => ['user_id' => $fixtures['targetDriver']->id],
        'create announcement' => [
            'message' => 'Authorization matrix announcement',
            'route' => VehicleRoute::MANGALDAN->value,
        ],
        'broadcast authorization' => [
            'socket_id' => '123.456',
            'channel_name' => 'private-vehicle.' . $fixtures['vehicle']->id,
        ],
        default => [],
    };
}

$protectedApplicationRoutes = [
    'driver dashboard' => ['driver dashboard', 'GET', '/driver/dashboard', 'driver', 200],
    'start shift' => ['start shift', 'POST', '/driver/shift/start', 'idleDriver', 200],
    'end shift' => ['end shift', 'POST', '/driver/shift/end', 'driver', 200],
    'active vehicles page' => ['active vehicles page', 'GET', '/student/active-jeeps', 'student', 200],
    'tracking page' => ['tracking page', 'GET', '/student/track/{vehicle}', 'student', 200],
    'student password update' => ['student password update', 'POST', '/student/change-password', 'student', 200],
    'admin dashboard' => ['admin dashboard', 'GET', '/admin/dashboard', 'admin', 200],
    'list users' => ['list users', 'GET', '/admin/api/users', 'admin', 200],
    'create user' => ['create user', 'POST', '/admin/api/users', 'admin', 201],
    'download import template' => ['download import template', 'GET', '/admin/api/users/import/template', 'admin', 200],
    'validate user import' => ['validate user import', 'POST', '/admin/api/users/import', 'admin', 422],
    'change managed password' => ['change managed password', 'POST', '/admin/api/users/{user}/change-password', 'admin', 200],
    'update user' => ['update user', 'PUT', '/admin/api/users/{user}', 'admin', 200],
    'deactivate user' => ['deactivate user', 'POST', '/admin/api/users/{user}/deactivate', 'admin', 200],
    'reactivate user' => ['reactivate user', 'POST', '/admin/api/users/{user}/reactivate', 'admin', 200],
    'assign vehicle to user' => ['assign vehicle to user', 'POST', '/admin/api/users/{driver}/assign-vehicle', 'admin', 200],
    'list managed vehicles' => ['list managed vehicles', 'GET', '/admin/api/vehicles', 'admin', 200],
    'create vehicle' => ['create vehicle', 'POST', '/admin/api/vehicles', 'admin', 201],
    'update vehicle' => ['update vehicle', 'PUT', '/admin/api/vehicles/{adminVehicle}', 'admin', 200],
    'delete vehicle' => ['delete vehicle', 'DELETE', '/admin/api/vehicles/{endedVehicle}', 'admin', 200],
    'assign driver to vehicle' => ['assign driver to vehicle', 'POST', '/admin/api/vehicles/{adminVehicle}/assign-driver', 'admin', 200],
    'list shifts' => ['list shifts', 'GET', '/admin/api/shifts', 'admin', 200],
    'view analytics' => ['view analytics', 'GET', '/admin/api/analytics', 'admin', 200],
    'list announcements' => ['list announcements', 'GET', '/admin/api/announcements', 'admin', 200],
    'create announcement' => ['create announcement', 'POST', '/admin/api/announcements', 'admin', 201],
    'deactivate announcement' => ['deactivate announcement', 'POST', '/admin/api/announcements/{announcement}/deactivate', 'admin', 200],
    'delete announcement' => ['delete announcement', 'DELETE', '/admin/api/announcements/{announcement}', 'admin', 200],
    'list tracking vehicles' => ['list tracking vehicles', 'GET', '/api/vehicles/active', 'student', 200],
    'show tracking vehicle' => ['show tracking vehicle', 'GET', '/api/vehicles/{vehicle}', 'student', 200],
    'GPS update' => ['GPS update', 'POST', '/api/gps/update', 'driver', 200],
    'occupancy update' => ['occupancy update', 'POST', '/api/vehicles/occupancy', 'driver', 200],
    'route update' => ['route update', 'POST', '/api/driver/route', 'driver', 200],
    'admin API vehicle list' => ['admin API vehicle list', 'GET', '/api/vehicles', 'admin', 200],
    'broadcast authorization' => ['broadcast authorization', 'POST', '/broadcasting/auth', 'student', 200],
];

dataset('protected application routes', $protectedApplicationRoutes);

it('keeps the protected-route authorization matrix complete', function () use ($protectedApplicationRoutes) {
    $normalizeUri = static fn (string $uri): string => preg_replace(
        '/\{[^}]+\}/',
        '{parameter}',
        ltrim($uri, '/'),
    );

    $coveredRoutes = collect($protectedApplicationRoutes)
        ->map(fn (array $case): string => $case[1] . ' ' . $normalizeUri($case[2]))
        ->sort()
        ->values();

    $registeredRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('auth', $route->middleware(), true))
        ->map(function ($route) use ($normalizeUri): string {
            $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');

            return $method . ' ' . $normalizeUri($route->uri());
        })
        ->unique()
        ->sort()
        ->values();

    expect($coveredRoutes->all())->toBe($registeredRoutes->all());
});

it('enforces guest, role, active-account, and allowed-role boundaries', function (
    string $case,
    string $method,
    string $pathTemplate,
    string $allowedActor,
    int $allowedStatus,
) {
    $fixtures = makeProtectedRouteFixtures();
    $path = protectedRoutePath($pathTemplate, $fixtures);
    $payload = protectedRoutePayload($case, $fixtures);
    $allowedUser = $fixtures[$allowedActor];
    $wrongRole = $allowedUser->role === 'driver' ? 'student' : 'driver';
    $wrongRoleUser = User::factory()->state(['role' => $wrongRole])->create();
    $inactiveUser = User::factory()
        ->state(['role' => $allowedUser->role])
        ->inactive()
        ->create();

    $this->json($method, $path, $payload)->assertUnauthorized();

    $this->actingAs($wrongRoleUser)
        ->json($method, $path, $payload)
        ->assertForbidden();

    $this->actingAs($inactiveUser)
        ->json($method, $path, $payload)
        ->assertForbidden();

    $this->actingAs($allowedUser)
        ->json($method, $path, $payload)
        ->assertStatus($allowedStatus);
})->with('protected application routes');
