<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'latitude' => 16.050889,
            'longitude' => 120.341236,
            'speed_mps' => 0.0,
            'recorded_at' => now(),
        ];
    }

    public function moving(): static
    {
        return $this->state([
            'speed_mps' => Vehicle::MOVING_THRESHOLD_MPS,
        ]);
    }
}
