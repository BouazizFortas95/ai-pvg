<?php

namespace Tests\Feature;

use App\Enums\AiUsagePurpose;
use App\Models\AiUsageLedgerEntry;
use App\Services\Audio\ElevenLabs\AudioCache;
use App\Services\Audio\ElevenLabs\CostEstimator;
use App\Services\Audio\ElevenLabs\ElevenLabsClient;
use App\Services\Audio\ElevenLabs\SoundEffectService;
use App\Services\Automation\BudgetGuard;
use App\Services\Rendering\Audio\AudioProbe;
use App\Services\Rendering\Audio\SfxGenerator;
use App\Services\Automation\DryRunContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SoundEffectServiceTest extends TestCase
{
    use RefreshDatabase;

    protected string $testCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->testCacheDir = storage_path('framework/testing/test_sfx_cache_'.uniqid());
        File::ensureDirectoryExists($this->testCacheDir);

        config([
            'automation.dry_run' => false,
            'automation.audio.provider' => 'elevenlabs',
            'automation.audio.elevenlabs.enabled' => true,
            'automation.audio.elevenlabs.sfx_enabled' => true,
            'automation.audio.elevenlabs.api_key' => 'xi-test-sfx-key-12345678',
            'automation.audio.elevenlabs.base_url' => 'https://api.elevenlabs.io',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testCacheDir);
        parent::tearDown();
    }

    protected function makeService(?AudioCache $cache = null): SoundEffectService
    {
        $cache = $cache ?? new AudioCache($this->testCacheDir);
        $client = new ElevenLabsClient(apiKey: 'xi-test-sfx-key-12345678');
        $probe = new class extends AudioProbe {
            public function duration(string $path): float { return 1.5; }
        };
        $localSfx = new class extends SfxGenerator {
            public function generate(string $cue, string $outputPath): string {
                File::ensureDirectoryExists(dirname($outputPath));
                file_put_contents($outputPath, 'RIFF_LOCAL_MOCK_SFX');
                return $outputPath;
            }
        };

        return new SoundEffectService(
            client: $client,
            budgetGuard: app(BudgetGuard::class),
            costEstimator: new CostEstimator,
            probe: $probe,
            localSfx: $localSfx,
            cache: $cache,
        );
    }

    public function test_generates_sfx_via_elevenlabs_and_creates_cache_and_ledger(): void
    {
        $mockMp3 = 'RIFF_ELEVENLABS_SYNTHESIZED_SFX_MOCK_BYTES';

        Http::fake([
            'https://api.elevenlabs.io/v1/sound-generation*' => Http::response($mockMp3, 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $service = $this->makeService();
        $result = $service->generate('High tech cyber whoosh', 1.5);

        $this->assertFalse($result['cached']);
        $this->assertEquals('elevenlabs', $result['provider']);
        $this->assertEquals(1.5, $result['duration']);
        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(0, $result['cost_micros']);

        // Verify sidecar JSON
        $metaPath = $this->testCacheDir.'/'.$result['hash'].'.json';
        $this->assertFileExists($metaPath);
        $meta = json_decode((string) file_get_contents($metaPath), true);
        $this->assertEquals('High tech cyber whoosh', $meta['prompt']);
        $this->assertEquals('elevenlabs-sound-generation', $meta['model']);
        $this->assertEquals(1.5, $meta['duration']);

        // Verify ledger entry
        $this->assertDatabaseHas('ai_usage_ledger', [
            'purpose' => AiUsagePurpose::Audio->value,
            'provider' => 'elevenlabs',
            'model' => 'sound-generation',
        ]);
    }

    public function test_cache_hit_returns_cached_audio_without_api_call_or_cost(): void
    {
        $cache = new AudioCache($this->testCacheDir);
        $service = $this->makeService($cache);

        $hash = $service->computeKey('Laser beam snap', 0.8, false);
        $cache->put($hash, 'RIFF_EXISTING_CACHED_SFX_WAV', [
            'prompt' => 'Laser beam snap',
            'duration' => 0.8,
            'provider' => 'elevenlabs',
            'model' => 'elevenlabs-sound-generation',
        ]);

        // No HTTP fakes registered: any HTTP call would fail closed via preventStrayRequests
        $result = $service->generate('Laser beam snap', 0.8);

        $this->assertTrue($result['cached']);
        $this->assertEquals(0, $result['cost_micros']);
        $this->assertEquals('elevenlabs', $result['provider']);
        $this->assertEquals(0.8, $result['duration']);
    }

    public function test_falls_back_to_local_sfx_when_elevenlabs_disabled(): void
    {
        config(['automation.audio.elevenlabs.sfx_enabled' => false]);

        $service = $this->makeService();
        $result = $service->generate('Sub drop bass impact');

        $this->assertFalse($result['cached']);
        $this->assertEquals('local', $result['provider']);
        $this->assertEquals(0, $result['cost_micros']);
        $this->assertFileExists($result['path']);
        $this->assertStringContainsString('RIFF_LOCAL_MOCK_SFX', file_get_contents($result['path']));
    }

    public function test_dry_run_context_forces_local_synthesis(): void
    {
        config(['automation.dry_run' => true]);

        $service = $this->makeService();
        $result = $service->generate('Transition swoosh');

        $this->assertEquals('local', $result['provider']);
        $this->assertEquals(0, $result['cost_micros']);
    }
}
