<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Information Technology',
            'Human Resources',
            'Finance',
            'Academic Affairs',
            'Administration',
        ]).fake()->unique()->numberBetween(1, 999);

        return [
            'unit_id' => Unit::factory(),
            'name' => $name,
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'slug' => Str::slug($name),
            'is_active' => true,
        ];
    }
}
