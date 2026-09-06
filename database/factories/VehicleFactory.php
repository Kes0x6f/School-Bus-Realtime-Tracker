<?php

namespace Database\Factories;

use App\Enums\VehicleRoute;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'plate_number' => fake()->unique()->bothify('JEEP-####'),
            'driver_name' => null,
            'user_id' => null,
            'is_active' => true,
            'shift_active' => false,
            'shift_started_at' => null,
            'shift_ended_at' => null,
            'current_shift_id' => null,
            'latitude' => null,
            'longitude' => null,
            'speed_mps' => null,
            'last_seen' => null,
            'last_moved_at' => null,
            'route_name' => VehicleRoute::MANGALDAN->value,
            'is_full' => false,
        ];
    }

    public function assignedTo(User|int $user): static
    {
        return $this->state([
            'user_id' => $user instanceof User ? $user->id : $user,
        ]);
    }

    public function onShift(): static
    {
        return $this->state([
            'is_active' => true,
            'shift_active' => true,
            'shift_started_at' => now()->subHour(),
            'shift_ended_at' => null,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state([
            'is_active' => false,
            'shift_active' => true,
            'shift_started_at' => now()->subHour(),
            'last_seen' => now()->subMinutes(21),
        ]);
    }

    public function ended(): static
    {
        return $this->state([
            'is_active' => false,
            'shift_active' => false,
            'shift_started_at' => now()->subHour(),
            'shift_ended_at' => now(),
        ]);
    }

    public function moving(): static
    {
        return $this->state([
            'is_active' => true,
            'shift_active' => true,
            'speed_mps' => Vehicle::MOVING_THRESHOLD_MPS,
            'last_moved_at' => now(),
        ]);
    }
}
