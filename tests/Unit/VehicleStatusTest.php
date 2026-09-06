<?php

use App\Models\Vehicle;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('classifies the complete gps status boundary set', function () {
    $ended = new Vehicle([
        'shift_active' => false,
        'is_active' => false,
    ]);
    $disconnected = new Vehicle([
        'shift_active' => true,
        'is_active' => false,
    ]);
    $moving = new Vehicle([
        'shift_active' => true,
        'is_active' => true,
        'speed_mps' => Vehicle::MOVING_THRESHOLD_MPS,
    ]);
    $traffic = new Vehicle([
        'shift_active' => true,
        'is_active' => true,
        'speed_mps' => Vehicle::TRAFFIC_THRESHOLD_MPS,
        'last_moved_at' => now()->subSeconds(299),
    ]);
    $idle = new Vehicle([
        'shift_active' => true,
        'is_active' => true,
        'speed_mps' => 0,
        'last_moved_at' => now()->subSeconds(300),
    ]);

    expect($ended->gps_status)->toBe('shift_ended')
        ->and($disconnected->gps_status)->toBe('disconnected')
        ->and($moving->gps_status)->toBe('moving')
        ->and($traffic->gps_status)->toBe('traffic')
        ->and($idle->gps_status)->toBe('idle');
});

it('converts meters per second to kilometers per hour at the model boundary', function () {
    expect(Vehicle::speedMpsToKph(0.0))->toBe(0.0)
        ->and(Vehicle::speedMpsToKph(3.0))->toBe(10.8)
        ->and(Vehicle::speedMpsToKph(null))->toBeNull();
});
