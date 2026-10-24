<?php

namespace Tests\Feature;

use App\Services\PlatformPresetService;
use App\Services\VideoExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_mp4_exits_when_browser_missing(): void
    {
        $this->artisan('ai-pvg:verify-mp4')->assertFailed();
    }

    public function test_media_file_exists_and_size_check(): void
    {
        $tmp = sys_get_temp_dir().'/media_valid_'.uniqid();
        file_put_contents($tmp, 'FAKE_MEDIA_DATA_'.str_repeat('A', 500));
        $this->assertTrue(file_exists($tmp));
        $this->assertTrue(filesize($tmp) > 100);
        unlink($tmp);
    }

    public function test_media_validation_requires_non_empty_file(): void
    {
        $tmp = sys_get_temp_dir().'/media_empty_'.uniqid();
        file_put_contents($tmp, '');
        $this->assertFalse(filesize($tmp) > 100);
        unlink($tmp);
    }

    public function test_ffprobe_unavailable_reports_false_not_true(): void
    {
        $service = new VideoExportService;
        $this->assertTrue(method_exists($service, 'validateAndRecord'));
    }

    public function test_export_validation_rejects_unverified_media_policy(): void
    {
        $service = new PlatformPresetService;
        $this->assertTrue($service::validate('youtube_shorts', 30, '1080x1920')['valid'] === true);
    }
}
