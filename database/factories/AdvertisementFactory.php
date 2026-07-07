<?php

namespace Database\Factories;

use App\Models\Advertisement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advertisement>
 */
class AdvertisementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'media_url' => null,
            'media_type' => null,
            'link_url' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'is_forever' => false,
            'status' => 'active',
        ];
    }
}
