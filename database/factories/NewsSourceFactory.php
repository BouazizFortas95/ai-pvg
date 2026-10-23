<?php

namespace Database\Factories;

use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NewsSource>
 */
class NewsSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'website_url' => fake()->optional()->url(),
            'rss_url' => fake()->optional()->url(),
            'description' => fake()->optional()->sentence(),
            'logo' => null,
            'language' => fake()->randomElement(['en', 'ar', 'fr']),
            'is_active' => true,
            'last_fetched_at' => null,
        ];
    }

    /**
     * Indicate that the source is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the source was recently fetched (RSS timeline).
     */
    public function fetched(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_fetched_at' => now(),
        ]);
    }
}
