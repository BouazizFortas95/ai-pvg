<?php

namespace Tests\Feature;

use App\Models\VideoRender;
use App\Models\VideoScript;
use App\Services\Media\MediaProbeService;
use App\Services\VideoRenderingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class VideoRenderingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_completed_render_only_after_media_verification(): void
    {
        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-pvg-render-'.uniqid();
        $stub = $tmpDir.DIRECTORY_SEPARATOR.'fake-render.php';
        File::ensureDirectoryExists($tmpDir);

        file_put_contents($stub, <<<'PHP'
<?php
$output = null;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--output' && isset($argv[$i + 1])) {
        $output = $argv[$i + 1];
    }
}
if ($output === null) {
    fwrite(STDERR, 'missing --output');
    exit(2);
}
file_put_contents($output, str_repeat('R', 2048));
exit(0);
PHP);

        config([
            'remotion.node_binary' => PHP_BINARY,
            'remotion.cli' => $stub,
            'remotion.output_dir' => $tmpDir,
            'remotion.require_verification' => true,
            'remotion.timeout' => 30,
        ]);

        $probe = Mockery::mock(MediaProbeService::class);
        $probe->shouldReceive('probe')->once()->andReturn([
            'verified' => true,
            'duration' => 6.0,
            'width' => 1080,
            'height' => 1920,
            'fps' => 30.0,
            'codec' => 'h264',
        ]);
        $probe->shouldReceive('matches')->once()->andReturn(['valid' => true, 'errors' => []]);

        $script = VideoScript::factory()->create([
            'status' => 'approved',
            'scenes' => [
                ['order' => 1, 'type' => 'intro', 'duration' => 6, 'headline' => 'Test'],
            ],
        ]);
        $render = VideoRender::factory()->create([
            'video_script_id' => $script->getKey(),
            'status' => 'pending',
            'template' => 'cyber',
        ]);

        $result = (new VideoRenderingService($probe))->render($render);

        $this->assertSame('completed', $result->status->value);
        $this->assertSame('1080x1920', $result->resolution);
        $this->assertSame(6.0, (float) $result->duration);
        $this->assertSame($tmpDir.DIRECTORY_SEPARATOR.'render_'.$render->getKey().'.mp4', $result->output_path);
        $this->assertFileExists($result->output_path);

        File::deleteDirectory($tmpDir);
    }
}
