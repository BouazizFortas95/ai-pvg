<?php

namespace Tests\Feature;

use App\Enums\VideoExportStatus;
use App\Models\VideoExport;
use App\Models\VideoRender;
use App\Services\PlatformPresetService;
use App\Services\VideoExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoExportTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_validation(): void
    {
        $p = PlatformPresetService::get('tiktok');
        $this->assertSame('9:16', $p['aspect']);
        $v = PlatformPresetService::validate('youtube_shorts', 30, '1080x1920');
        $this->assertTrue($v['valid']);
    }

    public function test_export_model_exists(): void
    {
        $this->assertTrue(class_exists(VideoExport::class));
    }

    public function test_service_rejects_uncompleted_render(): void
    {
        $render = VideoRender::factory()->create(['status' => 'pending']);
        $export = VideoExport::factory()->create(['video_render_id' => $render->getKey()]);
        $service = new VideoExportService;
        $result = $service->validateAndRecord($export);
        $this->assertTrue($result->status === VideoExportStatus::Failed);
    }
}
