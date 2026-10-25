<?php

namespace Database\Factories;

use App\Models\VideoRender;
use App\Models\VideoScript;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoRenderFactory extends Factory
{
    protected $model = VideoRender::class;

    public function definition(): array
    {
        return [
            'video_script_id' => VideoScript::factory(),
            'template' => 'cyber',
            'status' => 'pending',
            'resolution' => '1080x1920',
        ];
    }
}
