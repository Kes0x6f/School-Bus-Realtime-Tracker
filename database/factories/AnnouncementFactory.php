<?php

namespace Database\Factories;

use App\Enums\VehicleRoute;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'message' => fake()->sentence(),
            'route' => null,
            'is_active' => true,
            'expires_at' => null,
            'created_by' => User::factory()->admin(),
        ];
    }

    public function forRoute(VehicleRoute $route): static
    {
        return $this->state([
            'route' => $route->value,
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state([
            'is_active' => false,
        ]);
    }
}
