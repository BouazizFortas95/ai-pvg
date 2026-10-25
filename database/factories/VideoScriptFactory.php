<?php

namespace Database\Factories;

use App\Models\News;
use App\Models\VideoScript;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoScriptFactory extends Factory
{
    protected $model = VideoScript::class;

    public function definition(): array
    {
        return [
            'news_id' => News::factory(),
            'title' => $this->faker->sentence(),
            'language' => 'en',
            'target_duration' => 45,
            'status' => 'draft',
            'scenes' => null,
        ];
    }
}
