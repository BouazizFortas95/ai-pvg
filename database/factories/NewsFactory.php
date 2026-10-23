<?php

namespace Database\Factories;

use App\Models\News;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<News>
 */
class NewsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(nbWords: 6, variableNbWords: true);

        return [
            'title' => $title,
            'slug' => str($title)->slug(),
            'content' => fake()->paragraphs(asText: true),
            'summary' => fake()->sentence(),
            'source_name' => fake()->company(),
            'source_url' => fake()->url(),
            'image' => null,
            'category' => fake()->randomElement(['technology', 'business', 'health', 'science', 'sports']),
            'status' => fake()->randomElement(['draft', 'ready', 'processed', 'rejected']),
            'published_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }

    /**
     * Indicate that the article is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => now(),
        ]);
    }
}
