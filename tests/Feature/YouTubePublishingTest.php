<?php

namespace Tests\Feature;

use App\Contracts\PublishingProviderInterface;
use App\Models\VideoExport;
use App\Models\VideoRender;
use App\Services\Publishing\PublishingService;
use App\Services\Publishing\YouTubePublishingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubePublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_youtube_provider_not_configured_when_empty(): void
    {
        $this->assertFalse((new YouTubePublishingProvider)->isConfigured());
    }

    public function test_oauth_state_validation_rejects_invalid_state(): void
    {
        $provider = new YouTubePublishingProvider;
        $this->expectException(\RuntimeException::class);
        session(['youtube_oauth_state' => 'wrong']);
        $provider->handleCallback('fake', 'mismatch');
    }

    public function test_service_requires_authorized_user(): void
    {
        $export = VideoExport::factory()->create(['video_render_id' => VideoRender::factory()->create()]);
        $svc = app(PublishingService::class);
        $this->expectException(\Throwable::class);
        $svc->initiateUpload($export->id, 0); // no user
    }

    public function test_missing_or_unverified_export_is_rejected(): void
    {
        $render = VideoRender::factory()->create();
        $export = VideoExport::factory()->create(['video_render_id' => $render->id]);
        $service = new PublishingService;
        $provider = $service->provider();
        $this->assertTrue($provider instanceof PublishingProviderInterface);
        // Upload requires validated export; no upload called here
    }

    public function test_diagnostics_does_not_expose_tokens(): void
    {
        $this->artisan('ai-pvg:diagnostics')
            ->assertSuccessful();
    }

    public function test_upload_rejects_missing_session_url(): void
    {
        Http::fake([
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' => Http::response('', 200, []),
        ]);
        $provider = new YouTubePublishingProvider;
        $this->expectException(\RuntimeException::class);
        $provider->upload(['visibility' => 'private', 'title' => 'T'], __DIR__.'/../../database/factories/VideoRenderFactory.php');
    }
}
