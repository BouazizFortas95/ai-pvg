<?php

namespace Database\Factories;

use App\Models\VideoExport;
use App\Models\VideoRender;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoExportFactory extends Factory
{
    protected $model = VideoExport::class;

    public function definition(): array
    {
        return [
            'video_render_id' => VideoRender::factory(),
            'export_name' => 'test_export',
            'platform_preset' => 'youtube_shorts',
        ];
    }
}
