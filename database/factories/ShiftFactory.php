<?php

namespace Database\Factories;

use App\Enums\ShiftEndReason;
use App\Enums\VehicleRoute;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'user_id' => User::factory()->driver(),
            'route_name' => VehicleRoute::MANGALDAN->value,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'duration_seconds' => 3600,
            'end_reason' => ShiftEndReason::MANUAL,
            'active_marker' => null,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'ended_at' => null,
            'duration_seconds' => null,
            'end_reason' => null,
            'active_marker' => true,
        ]);
    }

    public function endedBy(ShiftEndReason $reason): static
    {
        return $this->state([
            'end_reason' => $reason,
        ]);
    }
}
