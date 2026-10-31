<?php

namespace Tests\Feature;

use App\Exceptions\Rendering\InvalidEditPlanException;
use App\Services\Rendering\Audio\AudioMixer;
use App\Services\Rendering\Audio\TranscriptionService;
use App\Services\Rendering\EditPlan\EditPlanBuilder;
use App\Services\Rendering\EditPlan\EditPlanValidator;
use App\Services\Rendering\HyperFramesCompositionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HyperFramesAudioPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->scratch = storage_path('framework/testing/hf_audio_'.uniqid());
        File::ensureDirectoryExists($this->scratch);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->scratch)) {
            File::deleteDirectory($this->scratch);
        }

        parent::tearDown();
    }

    public function test_edit_plan_validator_accepts_valid_plan_and_rejects_invalid(): void
    {
        $validator = new EditPlanValidator;
        $sample = json_decode((string) File::get(storage_path('app/hyperframes/samples/professional_24s.json')), true);

        $plan = (new EditPlanBuilder)->fromPayload($sample, ['preset' => '9:16', 'theme' => 'cyber']);

        $validated = $validator->validate($plan);
        $this->assertTrue($validated['ok']);
        $this->assertSame([], $validated['errors']);

        // Invalid: missing required key 'audio'
        $invalidPlan = $plan;
        unset($invalidPlan['audio']);
        $invalidResult = $validator->validate($invalidPlan);
        $this->assertFalse($invalidResult['ok']);

        $this->expectException(InvalidEditPlanException::class);
        $validator->assertValid($invalidPlan);
    }

    public function test_edit_plan_builder_generates_valid_plan_with_jcuts(): void
    {
        $builder = new EditPlanBuilder;
        $sample = json_decode((string) File::get(storage_path('app/hyperframes/samples/professional_24s.json')), true);

        $plan = $builder->fromPayload($sample, ['preset' => '9:16', 'theme' => 'cyber']);

        $this->assertSame(1, $plan['version']);
        $this->assertSame($sample['title'], $plan['title']);
        $this->assertSame(1080, $plan['width']);
        $this->assertSame(1920, $plan['height']);
        $this->assertCount(8, $plan['scenes']);

        // Scene 2 (index 1) has J-cut: audio leads picture by 0.2s
        $scene2 = $plan['scenes'][1];
        $this->assertEquals(round($scene2['start'] - 0.2, 3), $scene2['narration_start']);
    }

    public function test_composition_builder_from_edit_plan_includes_audio_mix_and_captions(): void
    {
        $builder = new HyperFramesCompositionBuilder(base_path('hyperframes'), $this->scratch);

        $fakeMix = $this->scratch.'/test_mix.wav';
        File::put($fakeMix, 'RIFFfakeWAVcontent');

        $sample = json_decode((string) File::get(storage_path('app/hyperframes/samples/professional_24s.json')), true);
        $plan = (new EditPlanBuilder)->fromPayload($sample, ['preset' => '9:16', 'theme' => 'cyber']);

        $wordTimings = [
            ['word' => 'AI-PVG', 'startMs' => 100, 'endMs' => 400],
            ['word' => 'makes', 'startMs' => 420, 'endMs' => 800],
            ['word' => 'video', 'startMs' => 820, 'endMs' => 1200],
            ['word' => 'Dual', 'startMs' => 2100, 'endMs' => 2400],
            ['word' => 'Render', 'startMs' => 2420, 'endMs' => 2800],
            ['word' => 'Audio', 'startMs' => 4600, 'endMs' => 5000],
        ];

        $built = $builder->buildFromEditPlan($plan, [
            'preset' => '9:16',
            'audio_mix' => $fakeMix,
            'word_timings' => $wordTimings,
            'captions_verified' => true,
            'run_id' => 'audio-plan-test',
        ]);

        $this->assertFileExists($built['projectDir'].'/index.html');
        $this->assertFileExists($built['projectDir'].'/manifest.json');
        $this->assertFileExists($built['projectDir'].'/compositions/captions.html');
        $this->assertFileExists($built['projectDir'].'/assets/audio/mix-1.wav');

        $indexHtml = File::get($built['projectDir'].'/index.html');
        $this->assertStringContainsString('<audio', $indexHtml);
        $this->assertStringContainsString('assets/audio/mix-1.wav', $indexHtml);
        $this->assertStringContainsString('data-volume="1"', $indexHtml);
        $this->assertStringContainsString('data-composition-src="compositions/captions.html"', $indexHtml);

        $manifest = json_decode(File::get($built['projectDir'].'/manifest.json'), true);
        $this->assertSame(['assets/audio/mix-1.wav'], $manifest['audio']);
        $this->assertSame('included', $manifest['captions']);
    }

    public function test_transcription_service_parses_json_lines(): void
    {
        $service = new TranscriptionService;

        $jsonOutput = <<<'OUT'
[hyperframes:transcribe] Starting model parakeet-tdt-0.6b-v3...
{"words":[{"text":"Hello","start":0.1,"end":0.4},{"text":"world","start":0.45,"end":0.85}]}
[hyperframes:transcribe] Complete in 0.12s
OUT;

        $words = $service->parseWords($jsonOutput);
        $this->assertCount(2, $words);
        $this->assertSame('Hello', $words[0]['word']);
        $this->assertSame(100, $words[0]['startMs']);
        $this->assertSame(400, $words[0]['endMs']);
        $this->assertSame('world', $words[1]['word']);
        $this->assertSame(450, $words[1]['startMs']);
        $this->assertSame(850, $words[1]['endMs']);
    }

    public function test_audio_mixer_builds_sidechain_filter_graph(): void
    {
        $mixer = new AudioMixer;
        $graph = $mixer->buildMixFilterGraph();

        $this->assertStringContainsString('sidechaincompress', $graph);
        $this->assertStringContainsString('amix=inputs=3', $graph);
        $this->assertStringContainsString('[mix]', $graph);
    }
}
