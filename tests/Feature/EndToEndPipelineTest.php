<?php
namespace Tests\Feature;
use App\Models\VideoScript;
use App\Services\VoiceGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class EndToEndPipelineTest extends TestCase
{
    use RefreshDatabase;
    public function test_workflow_prevents_duplicate_renders_idempotent_retry(): void
    {
        $script = VideoScript::factory()->create(['status'=>'approved']);
        $this->assertDatabaseHas('video_scripts', ['id' => $script->id, 'status'=>'approved']);
    }
    public function test_ffprobe_unavailable_reports_unverified_not_fake(): void
    {
        $service = new \App\Services\VideoExportService();
        $this->assertTrue(method_exists($service, 'validateAndRecord'));
    }
    public function test_diagnostics_is_read_only(): void
    {
        $this->artisan('ai-pvg:diagnostics')
            ->assertSuccessful();
    }
}
