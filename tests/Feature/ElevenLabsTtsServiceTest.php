<?php

namespace Tests\Feature;

use App\Exceptions\Automation\BudgetExceededException;
use App\Exceptions\Rendering\AudioPipelineException;
use App\Models\AiUsageLedgerEntry;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\VideoScript;
use App\Services\Audio\ElevenLabs\AlignmentConverter;
use App\Services\Audio\ElevenLabs\AudioCache;
use App\Services\Audio\ElevenLabs\CostEstimator;
use App\Services\Audio\ElevenLabs\ElevenLabsClient;
use App\Services\Audio\ElevenLabs\TtsService;
use App\Services\Automation\BudgetGuard;
use App\Services\Pipeline\PipelineStepService;
use App\Services\Rendering\Audio\AudioProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsTtsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected string $testAudioDir;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->testAudioDir = storage_path('app/testing_audio_cache');
        File::ensureDirectoryExists($this->testAudioDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testAudioDir)) {
            File::deleteDirectory($this->testAudioDir);
        }
        parent::tearDown();
    }

    public function test_strip_expressive_tags_removes_audio_cues_for_captions(): void
    {
        $client = new ElevenLabsClient(apiKey: 'sk_mock_test');
        $tts = new TtsService($client);

        $input = '[whisper] Breaking news tonight: [excited] OpenAI has announced nova-1. [pause] More details follow.';
        $clean = $tts->stripExpressiveTags($input);

        $this->assertSame('Breaking news tonight: OpenAI has announced nova-1. More details follow.', $clean);
        $this->assertStringNotContainsString('[whisper]', $clean);
        $this->assertStringNotContainsString('[excited]', $clean);
        $this->assertStringNotContainsString('[pause]', $clean);
    }

    public function test_cost_estimator_computes_micros_and_dollars(): void
    {
        $estimator = new CostEstimator;

        // 100 characters @ 300 micros/char = 30,000 micros = $0.03
        $text = str_repeat('a', 100);
        $micros = $estimator->estimateMicros($text);
        $dollars = $estimator->estimateDollars($text);

        $this->assertSame(30_000, $micros);
        $this->assertEqualsWithDelta(0.03, $dollars, 0.0001);
    }

    public function test_tts_service_synthesizes_records_ledger_and_caches(): void
    {
        $fakeAudio = str_repeat('RIFF....WAVEfmt ....data....', 10);
        $fakeBase64 = base64_encode($fakeAudio);

        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM/with-timestamps*' => Http::response([
                'audio_base64' => $fakeBase64,
                'alignment' => [
                    'characters' => ['H', 'e', 'l', 'l', 'o', ' ', 'w', 'o', 'r', 'l', 'd'],
                    'character_start_times_seconds' => [0.0, 0.05, 0.1, 0.15, 0.2, 0.25, 0.3, 0.35, 0.4, 0.45, 0.5],
                    'character_end_times_seconds' => [0.05, 0.1, 0.15, 0.2, 0.25, 0.3, 0.35, 0.4, 0.45, 0.5, 0.55],
                ],
            ], 200),
        ]);

        $mockProbe = \Mockery::mock(AudioProbe::class);
        $mockProbe->shouldReceive('duration')->andReturn(0.55);

        $client = new ElevenLabsClient(apiKey: 'sk_mock_test_key');
        $cache = new AudioCache($this->testAudioDir);
        $tts = new TtsService(
            client: $client,
            alignmentConverter: new AlignmentConverter,
            cache: $cache,
            costEstimator: new CostEstimator,
            budgetGuard: app(BudgetGuard::class),
            probe: $mockProbe,
        );

        $outWav = $this->testAudioDir.'/test_out.wav';
        $result = $tts->synthesize('Hello world', $outWav);

        $this->assertSame($outWav, $result['path']);
        $this->assertFalse($result['cached']);
        $this->assertSame('elevenlabs', $result['provider']);
        $this->assertCount(2, $result['word_timings']);
        $this->assertGreaterThan(0, $result['cost_micros']);

        // Check that usage was recorded in ai_usage_ledger
        $this->assertDatabaseHas('ai_usage_ledger', [
            'provider' => 'elevenlabs',
            'purpose' => 'tts',
        ]);

        // SECOND CALL: Must hit cache and make ZERO external HTTP requests
        $outWav2 = $this->testAudioDir.'/test_out2.wav';
        $result2 = $tts->synthesize('Hello world', $outWav2);

        $this->assertTrue($result2['cached']);
        $this->assertSame(0, $result2['cost_micros']);
        $this->assertCount(2, $result2['word_timings']);

        // Http should only have been called once across both syntheses
        Http::assertSentCount(1);
    }

    public function test_reconciliation_fails_when_duration_diverges_substantially(): void
    {
        $fakeAudio = str_repeat('RIFF....WAVEfmt ....data....', 10);
        $fakeBase64 = base64_encode($fakeAudio);

        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM/with-timestamps*' => Http::response([
                'audio_base64' => $fakeBase64,
                'alignment' => [
                    'characters' => ['T', 'e', 's', 't'],
                    'character_start_times_seconds' => [0.0, 0.05, 0.1, 0.15],
                    'character_end_times_seconds' => [0.05, 0.1, 0.15, 0.20], // Alignment claims 0.20s
                ],
            ], 200),
        ]);

        // Probe measures 1.20s (1.0s discrepancy, far exceeding 0.10s / 0.35s tolerance)
        $mockProbe = \Mockery::mock(AudioProbe::class);
        $mockProbe->shouldReceive('duration')->andReturn(1.20);

        $client = new ElevenLabsClient(apiKey: 'sk_mock_test_key');
        $tts = new TtsService(
            client: $client,
            alignmentConverter: new AlignmentConverter,
            cache: new AudioCache($this->testAudioDir),
            costEstimator: new CostEstimator,
            budgetGuard: app(BudgetGuard::class),
            probe: $mockProbe,
        );

        $this->expectException(AudioPipelineException::class);
        $this->expectExceptionMessage('diverges from returned alignment');

        $outWav = $this->testAudioDir.'/test_drift.wav';
        $tts->synthesize('Test', $outWav);
    }

    public function test_pipeline_step_four_with_elevenlabs_wires_cleanly(): void
    {
        $fakeAudio = str_repeat('RIFF....WAVEfmt ....data....', 10);
        $fakeBase64 = base64_encode($fakeAudio);

        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM/with-timestamps*' => Http::response([
                'audio_base64' => $fakeBase64,
                'alignment' => [
                    'characters' => ['N', 'e', 'w', 's'],
                    'character_start_times_seconds' => [0.0, 0.05, 0.1, 0.15],
                    'character_end_times_seconds' => [0.05, 0.1, 0.15, 0.20],
                ],
            ], 200),
        ]);

        $mockProbe = \Mockery::mock(AudioProbe::class);
        $mockProbe->shouldReceive('duration')->andReturn(0.20);
        $this->app->instance(AudioProbe::class, $mockProbe);

        config([
            'automation.audio.provider' => 'elevenlabs',
            'automation.audio.elevenlabs.api_key' => 'sk_mock_test_key',
        ]);
        FeatureFlag::setFlag('audio.elevenlabs.enabled', true);

        $news = News::factory()->create(['status' => 'ready']);
        $script = VideoScript::factory()->create([
            'news_id' => $news->getKey(),
            'narration' => 'News update',
            'status' => 'approved',
        ]);

        $service = app(PipelineStepService::class);
        $run = $service->findOrCreateRun();

        $result = $service->runStep4([
            'audio_provider' => 'elevenlabs',
            'enable_elevenlabs' => true,
        ], $run);

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['step']);
        $this->assertSame('elevenlabs', $result['artifacts']['tts_provider']);
        $this->assertStringContainsString('TTS Provider: elevenlabs', $result['log']);
    }
}

