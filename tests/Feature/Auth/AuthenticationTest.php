<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('test@example.com|127.0.0.1');
});

it('logs active users in to the dashboard for their role', function (string $role, string $path) {
    $user = User::factory()->state(['role' => $role])->create([
        'email' => "{$role}@example.com",
    ]);

    $this->get('/');
    $previousSessionId = session()->getId();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect($path);

    expect(Auth::id())->toBe($user->id)
        ->and(session()->getId())->not->toBe($previousSessionId);
})->with([
    'driver' => ['driver', '/driver/dashboard'],
    'student' => ['student', '/student/active-jeeps'],
    'admin' => ['admin', '/admin/dashboard'],
]);

it('returns the same generic error for unknown and wrong-password logins', function () {
    $user = User::factory()->create([
        'email' => 'known@example.com',
    ]);

    $this->from('/')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Invalid credentials.']);

    $this->from('/')->post('/login', [
        'email' => 'unknown@example.com',
        'password' => 'wrong-password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Invalid credentials.']);

    expect(Auth::check())->toBeFalse();
});

it('does not establish a session for an inactive account', function () {
    $user = User::factory()->inactive()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Your account has been deactivated.']);

    expect(Auth::check())->toBeFalse();
});

it('rate limits repeated login failures', function () {
    $email = 'rate-limited@example.com';
    $key = $email . '|127.0.0.1';
    RateLimiter::clear($key);

    foreach (range(1, 6) as $attempt) {
        $this->post('/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'));
    }

    $this->post('/login', [
        'email' => $email,
        'password' => 'wrong-password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(RateLimiter::tooManyAttempts($key, 6))->toBeTrue();
});

it('enforces role boundaries on protected dashboards', function () {
    $student = User::factory()->student()->create();
    $driver = User::factory()->driver()->create();

    $this->actingAs($student)
        ->get('/admin/dashboard')
        ->assertForbidden();

    $this->actingAs($student)
        ->getJson('/admin/api/users')
        ->assertForbidden();

    $this->actingAs($student)
        ->postJson('/api/gps/update', [])
        ->assertForbidden();

    $this->actingAs($driver)
        ->getJson('/api/vehicles/active')
        ->assertForbidden();

    $this->get('/admin/dashboard')->assertRedirect(route('login'));
});
