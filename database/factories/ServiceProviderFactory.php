<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ServiceProviderFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['AMIS', 'HRIS', 'DTR', 'Library', 'Reports']).fake()->unique()->numberBetween(1, 999);
        $slug = Str::slug($name);

        return [
            'name' => $name,
            'slug' => $slug,
            'entity_id' => "https://{$slug}.example.test/saml/metadata",
            'acs_url' => "https://{$slug}.example.test/saml/acs",
            'launch_url' => "/sso/{$slug}",
            'status' => 'healthy',
            'attribute_release' => ['email', 'name'],
            'is_active' => true,
        ];
    }
}
