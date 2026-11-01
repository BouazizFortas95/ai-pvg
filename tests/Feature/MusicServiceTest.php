<?php

namespace Tests\Feature;

use App\Enums\AiUsagePurpose;
use App\Services\Audio\ElevenLabs\AudioCache;
use App\Services\Audio\ElevenLabs\CostEstimator;
use App\Services\Audio\ElevenLabs\ElevenLabsClient;
use App\Services\Audio\ElevenLabs\MusicService;
use App\Services\Automation\BudgetGuard;
use App\Services\Rendering\Audio\AudioProbe;
use App\Services\Rendering\Audio\MusicBedGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MusicServiceTest extends TestCase
{
    use RefreshDatabase;

    protected string $testCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->testCacheDir = storage_path('framework/testing/test_music_cache_'.uniqid());
        File::ensureDirectoryExists($this->testCacheDir);

        config([
            'automation.dry_run' => false,
            'automation.audio.provider' => 'elevenlabs',
            'automation.audio.elevenlabs.enabled' => true,
            'automation.audio.elevenlabs.music_enabled' => true,
            'automation.audio.elevenlabs.api_key' => 'xi-test-music-key-12345678',
            'automation.audio.elevenlabs.base_url' => 'https://api.elevenlabs.io',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testCacheDir);
        parent::tearDown();
    }

    protected function makeService(?AudioCache $cache = null): MusicService
    {
        $cache = $cache ?? new AudioCache($this->testCacheDir);
        $client = new ElevenLabsClient(apiKey: 'xi-test-music-key-12345678');
        $probe = new class extends AudioProbe {
            public function duration(string $path): float { return 15.0; }
        };
        $localMusic = new class extends MusicBedGenerator {
            public function generate(array $plan, string $outputPath): array {
                File::ensureDirectoryExists(dirname($outputPath));
                file_put_contents($outputPath, 'RIFF_LOCAL_MOCK_MUSIC');
                return ['path' => $outputPath, 'duration' => 15.0];
            }
        };

        return new MusicService(
            client: $client,
            budgetGuard: app(BudgetGuard::class),
            costEstimator: new CostEstimator,
            probe: $probe,
            localMusic: $localMusic,
            cache: $cache,
        );
    }

    public function test_generates_music_via_elevenlabs_and_records_ledger(): void
    {
        $mockMp3 = 'RIFF_ELEVENLABS_SYNTHESIZED_MUSIC_MOCK_BYTES';

        Http::fake([
            'https://api.elevenlabs.io/v1/music*' => Http::response($mockMp3, 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $service = $this->makeService();
        $result = $service->generate('Cyberpunk upbeat electronic', 15.0);

        $this->assertFalse($result['cached']);
        $this->assertEquals('elevenlabs', $result['provider']);
        $this->assertEquals(15.0, $result['duration']);
        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(0, $result['cost_micros']);

        // Verify sidecar JSON
        $metaPath = $this->testCacheDir.'/'.$result['hash'].'.json';
        $this->assertFileExists($metaPath);
        $meta = json_decode((string) file_get_contents($metaPath), true);
        $this->assertEquals('Cyberpunk upbeat electronic', $meta['mood']);
        $this->assertEquals('elevenlabs-music', $meta['model']);
        $this->assertEquals(15.0, $meta['duration']);

        // Verify ledger entry
        $this->assertDatabaseHas('ai_usage_ledger', [
            'purpose' => AiUsagePurpose::Audio->value,
            'provider' => 'elevenlabs',
            'model' => 'music',
        ]);
    }

    public function test_cache_hit_returns_cached_audio_without_api_call(): void
    {
        $cache = new AudioCache($this->testCacheDir);
        $service = $this->makeService($cache);

        $hash = $service->computeKey('Atmospheric ambient chill', 15.0, 'ambient');
        $cache->put($hash, 'RIFF_EXISTING_CACHED_MUSIC_WAV', [
            'mood' => 'Atmospheric ambient chill',
            'duration' => 15.0,
            'provider' => 'elevenlabs',
            'model' => 'elevenlabs-music',
        ]);

        $result = $service->generate('Atmospheric ambient chill', 15.0, ['theme' => 'ambient']);

        $this->assertTrue($result['cached']);
        $this->assertEquals(0, $result['cost_micros']);
        $this->assertEquals('elevenlabs', $result['provider']);
        $this->assertEquals(15.0, $result['duration']);
    }

    public function test_falls_back_to_local_generator_when_music_endpoint_disabled_or_unavailable(): void
    {
        config(['automation.audio.elevenlabs.music_enabled' => false]);

        $service = $this->makeService();
        $result = $service->generate('Dark synth tension', 15.0);

        $this->assertFalse($result['cached']);
        $this->assertEquals('local', $result['provider']);
        $this->assertEquals(0, $result['cost_micros']);
        $this->assertFileExists($result['path']);
        $this->assertStringContainsString('RIFF_LOCAL_MOCK_MUSIC', file_get_contents($result['path']));
    }

    public function test_dry_run_forces_local_music_generation(): void
    {
        config(['automation.dry_run' => true]);

        $service = $this->makeService();
        $result = $service->generate('Corporate upbeat acoustic', 15.0);

        $this->assertEquals('local', $result['provider']);
        $this->assertEquals(0, $result['cost_micros']);
    }
}

