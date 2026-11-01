<?php

namespace Tests\Feature;

use App\Exceptions\Rendering\AudioPipelineException;
use App\Services\Audio\AudioTimelineBuilder;
use App\Services\Audio\ElevenLabs\MusicService;
use App\Services\Audio\ElevenLabs\SoundEffectService;
use App\Services\Audio\ElevenLabs\TtsService;
use App\Services\Audio\LoudnessService;
use App\Services\Audio\MixService;
use App\Services\Rendering\Audio\AudioMixer;
use App\Services\Rendering\Audio\AudioProbe;
use App\Services\Rendering\Audio\AudioTimelineValidator;
use App\Services\Rendering\Audio\TranscriptionService;
use App\Services\Rendering\Audio\TtsGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MixAndLoudnessGateTest extends TestCase
{
    use RefreshDatabase;

    protected string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = storage_path('framework/testing/audio_test_'.uniqid());
        File::ensureDirectoryExists($this->workDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDir);
        parent::tearDown();
    }

    public function test_loudness_service_passes_compliant_broadcast_master(): void
    {
        $probe = new class extends AudioProbe {
            public function loudness(string $path): array {
                return [
                    'integrated_lufs' => -14.1,
                    'true_peak_dbtp' => -1.4,
                    'lra' => 10.2,
                ];
            }
        };

        $service = new LoudnessService($probe);
        $result = $service->assertCompliant('mock_mix.wav', targetLufs: -14.0, maxTruePeak: -1.0);

        $this->assertTrue($result['passed']);
        $this->assertEquals(-14.1, $result['integrated_lufs']);
        $this->assertEquals(-1.4, $result['true_peak_dbtp']);
        $this->assertTrue($service->passesGate('mock_mix.wav'));
    }

    public function test_loudness_gate_rejects_true_peak_exceeding_ceiling(): void
    {
        $probe = new class extends AudioProbe {
            public function loudness(string $path): array {
                return [
                    'integrated_lufs' => -14.0,
                    'true_peak_dbtp' => -0.5, // > -1.0 dBTP ceiling
                    'lra' => 11.0,
                ];
            }
        };

        $service = new LoudnessService($probe);

        $this->assertFalse($service->passesGate('mock_mix.wav', -14.0, -1.0));

        $this->expectException(AudioPipelineException::class);
        $this->expectExceptionMessage('True peak (-0.5 dBTP) exceeds maximum ceiling (-1 dBTP)');

        $service->assertCompliant('mock_mix.wav', targetLufs: -14.0, maxTruePeak: -1.0);
    }

    public function test_loudness_gate_rejects_integrated_lufs_outside_tolerance_window(): void
    {
        $probe = new class extends AudioProbe {
            public function loudness(string $path): array {
                return [
                    'integrated_lufs' => -20.5, // too quiet (< -15.0)
                    'true_peak_dbtp' => -6.0,
                    'lra' => 8.0,
                ];
            }
        };

        $service = new LoudnessService($probe);

        $this->expectException(AudioPipelineException::class);
        $this->expectExceptionMessage('quieter than allowed window');

        $service->assertCompliant('mock_mix.wav', targetLufs: -14.0, tolerance: 1.0);
    }

    public function test_mix_service_coordinates_stems_and_enforces_gate(): void
    {
        $dummyMixPath = $this->workDir.'/mix.wav';
        file_put_contents($dummyMixPath, 'RIFF_DUMMY_MIX');

        $mixer = new class($dummyMixPath) extends AudioMixer {
            public function __construct(protected string $mixOut) {}
            public function mix(array $narration, string $musicPath, array $sfx, float $duration, string $workDir): array {
                return [
                    'mix' => $this->mixOut,
                    'narration' => $workDir.'/stems/narration.wav',
                    'music' => $workDir.'/stems/music.wav',
                    'sfx' => $workDir.'/stems/sfx.wav',
                    'duration' => $duration,
                    'loudness' => [
                        'integrated_lufs' => -14.0,
                        'true_peak_dbtp' => -1.5,
                        'lra' => 10.0,
                    ],
                ];
            }
        };

        $probe = new class extends AudioProbe {
            public function loudness(string $path): array {
                return ['integrated_lufs' => -14.0, 'true_peak_dbtp' => -1.5, 'lra' => 10.0];
            }
        };

        $loudness = new LoudnessService($probe);
        $mixService = new MixService(mixer: $mixer, loudnessService: $loudness);

        $result = $mixService->mix(
            narration: [['path' => 'voice1.wav', 'start' => 0.0]],
            musicPath: 'music.wav',
            sfx: [['path' => 'sfx.wav', 'start' => 1.0, 'gain' => 0.3]],
            duration: 10.0,
            workDir: $this->workDir,
        );

        $this->assertTrue($result['gate_passed']);
        $this->assertEquals($dummyMixPath, $result['mix']);
        $this->assertEquals(10.0, $result['duration']);
        $this->assertEquals(-14.0, $result['loudness']['integrated_lufs']);
    }

    public function test_audio_timeline_builder_assembles_canonical_manifest(): void
    {
        $dummyMix = $this->workDir.'/mix.wav';
        file_put_contents($dummyMix, str_repeat('RIFF_MIX_WAV_', 20));

        $dummyMusic = $this->workDir.'/music.wav';
        file_put_contents($dummyMusic, str_repeat('RIFF_MUSIC_WAV_', 20));

        $dummySfx = $this->workDir.'/sfx.wav';
        file_put_contents($dummySfx, str_repeat('RIFF_SFX_WAV_', 20));

        $tts = new class extends TtsGenerator {
            public function synthesize(string $text, string $language = 'en', ?string $voice = null, ?string $outputPath = null): array {
                File::ensureDirectoryExists(dirname($outputPath));
                file_put_contents($outputPath, str_repeat('RIFF_NARRATION_WAV_', 20));
                return ['path' => $outputPath, 'provider' => 'local', 'voice' => 'default'];
            }
        };

        $sfx = new class($dummySfx) extends SoundEffectService {
            public function __construct(protected string $sfxFile) {}
            public function generate(string $prompt, ?float $duration = null, array $options = []): array {
                return ['path' => $this->sfxFile, 'duration' => 0.5, 'cached' => true, 'provider' => 'local', 'hash' => 'h', 'cost_micros' => 0];
            }
        };

        $music = new class($dummyMusic) extends MusicService {
            public function __construct(protected string $musicFile) {}
            public function generate(string $mood, float $targetDuration, array $options = []): array {
                return ['path' => $this->musicFile, 'duration' => $targetDuration, 'cached' => true, 'provider' => 'local', 'hash' => 'h', 'cost_micros' => 0];
            }
        };

        $mixSvc = new class($dummyMix) extends MixService {
            public function __construct(protected string $mPath) {}
            public function mix(array $narration, string $musicPath, array $sfx, float $duration, string $workDir, array $options = []): array {
                return [
                    'mix' => $this->mPath,
                    'narration' => $workDir.'/stems/narration.wav',
                    'music' => $workDir.'/stems/music.wav',
                    'sfx' => $workDir.'/stems/sfx.wav',
                    'duration' => $duration,
                    'loudness' => ['integrated_lufs' => -14.0, 'true_peak_dbtp' => -1.5, 'lra' => 11.0],
                    'gate_passed' => true,
                ];
            }
        };

        $probe = new class extends AudioProbe {
            public function duration(string $path): float { return 2.5; }
            public function loudness(string $path): array { return ['integrated_lufs' => -14.0, 'true_peak_dbtp' => -1.5, 'lra' => 11.0]; }
        };

        $transcriber = new class extends TranscriptionService {
            public function transcribe(string $audioPath, string $language = 'en', ?string $workDir = null): array {
                return [
                    'ok' => true,
                    'words' => [
                        ['word' => 'Hello', 'start' => 0.0, 'end' => 0.4, 'confidence' => 0.99],
                        ['word' => 'world', 'start' => 0.45, 'end' => 0.9, 'confidence' => 0.98],
                    ],
                ];
            }
        };

        $builder = new AudioTimelineBuilder(
            tts: $tts,
            sfxService: $sfx,
            musicService: $music,
            mixService: $mixSvc,
            transcriber: $transcriber,
            probe: $probe,
            validator: new AudioTimelineValidator,
        );

        $input = [
            'title' => 'Test Video Audio Timeline',
            'language' => 'en',
            'theme' => 'cyber',
            'scenes' => [
                ['id' => 'scene_01', 'narration' => 'Hello world in AI technology.', 'directives' => ['sfx_cue' => 'whoosh']],
                ['id' => 'scene_02', 'narration' => 'Next scene description.', 'directives' => ['sfx_cue' => 'impact']],
            ],
        ];

        $timeline = $builder->build($input, $this->workDir);

        $this->assertEquals(1, $timeline['version']);
        $this->assertGreaterThan(0, $timeline['total_duration']);
        $this->assertCount(2, $timeline['narration']);
        $this->assertCount(2, $timeline['scenes']);
        $this->assertEquals(-14.0, $timeline['mix']['integrated_lufs']);
        $this->assertEquals(-1.5, $timeline['mix']['true_peak_dbtp']);
        $this->assertFileExists($this->workDir.'/audio_timeline.json');

        $savedJson = json_decode((string) file_get_contents($this->workDir.'/audio_timeline.json'), true);
        $this->assertEquals($timeline['total_duration'], $savedJson['total_duration']);
    }
}
