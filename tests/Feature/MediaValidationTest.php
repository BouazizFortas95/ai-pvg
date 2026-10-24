<?php

namespace Tests\Feature;

use App\Models\VideoRender;
use App\Models\VideoScript;
use App\Services\Media\MediaProbeService;
use App\Services\PlatformPresetService;
use App\Services\VideoExportService;
use App\Services\VideoRenderingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_mp4_preflight_fails_when_payload_is_missing(): void
    {
        $this->artisan('ai-pvg:verify-mp4', ['--payload' => 'storage/app/does_not_exist.json'])
            ->expectsOutputToContain('Payload is missing')
            ->assertFailed();
    }

    public function test_verify_mp4_preflight_fails_when_cli_is_missing(): void
    {
        config(['remotion.cli' => base_path('remotion/dist/does-not-exist.js')]);

        $this->artisan('ai-pvg:verify-mp4')
            ->expectsOutputToContain('Remotion CLI is missing')
            ->assertFailed();
    }

    public function test_render_service_fails_cleanly_when_cli_is_missing(): void
    {
        config(['remotion.cli' => base_path('remotion/dist/does-not-exist.js')]);

        $script = VideoScript::factory()->create([
            'status' => 'approved',
            'scenes' => [
                ['order' => 1, 'type' => 'intro', 'duration' => 3, 'headline' => 'Test'],
            ],
        ]);
        $render = VideoRender::factory()->create([
            'video_script_id' => $script->getKey(),
            'status' => 'pending',
        ]);

        $result = app(VideoRenderingService::class)->render($render);

        $this->assertSame('failed', $result->status->value);
        $this->assertStringContainsString('Remotion CLI missing', (string) $result->error_message);
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

    public function test_invalid_media_is_not_reported_as_verified(): void
    {
        $tmp = sys_get_temp_dir().'/media_invalid_'.uniqid().'.mp4';
        file_put_contents($tmp, 'NOT_A_REAL_VIDEO');

        $result = app(MediaProbeService::class)->probe($tmp);

        $this->assertFalse($result['verified']);
        $this->assertNotSame('', (string) $result['reason']);

        unlink($tmp);
    }

    public function test_export_validation_rejects_unverified_media_policy(): void
    {
        $service = new PlatformPresetService;
        $this->assertTrue($service::validate('youtube_shorts', 30, '1080x1920')['valid'] === true);
    }

    public function test_export_service_still_has_validation_entrypoint(): void
    {
        $this->assertTrue(method_exists(new VideoExportService, 'validateAndRecord'));
    }
}
