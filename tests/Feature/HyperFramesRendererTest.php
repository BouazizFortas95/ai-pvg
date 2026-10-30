<?php

namespace Tests\Feature;

use App\Contracts\VideoRenderer;
use App\Enums\AiUsagePurpose;
use App\Enums\VideoScriptStatus;
use App\Exceptions\Rendering\HyperFramesLintException;
use App\Models\AiUsageLedgerEntry;
use App\Models\AudioAsset;
use App\Models\BudgetPeriod;
use App\Models\CaptionTrack;
use App\Models\FeatureFlag;
use App\Models\SceneAsset;
use App\Models\User;
use App\Models\VideoRender;
use App\Models\VideoScript;
use App\Models\VisualAsset;
use App\Services\AI\AIManager;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\BudgetReservation;
use App\Services\Automation\CircuitBreaker;
use App\Services\Automation\DryRunContext;
use App\Services\Media\MediaProbeService;
use App\Services\Rendering\HyperFramesAgentAuthor;
use App\Services\Rendering\HyperFramesCompositionBuilder;
use App\Services\Rendering\HyperFramesRenderService;
use App\Services\Rendering\ProcessResult;
use App\Services\Rendering\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class HyperFramesRendererTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    private FakeHyperFramesProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->scratch = storage_path('framework/testing/hyperframes-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->scratch);

        $this->fakeToolchain();

        $this->runner = new FakeHyperFramesProcessRunner;
        $this->app->instance(ProcessRunner::class, $this->runner);

        Config::set('automation.render.driver', 'remotion');
        Config::set('automation.render.hyperframes.enabled', false);
        Config::set('automation.render.hyperframes.feature_flag', 'render.hyperframes');
        Config::set('automation.render.hyperframes.directory', base_path('hyperframes'));
        Config::set('automation.render.hyperframes.output_dir', $this->scratch.'/compositions');
        Config::set('automation.render.hyperframes.output_video_dir', $this->scratch.'/video_out');
        Config::set('automation.render.hyperframes.fps', 30);
        Config::set('automation.render.hyperframes.quality', 'looks');
        Config::set('automation.render.hyperframes.timeout', 60);
        Config::set('automation.render.hyperframes.agent.enabled', false);
        Config::set('automation.render.hyperframes.agent.feature_flag', 'render.hyperframes.agent');
        Config::set('automation.render.hyperframes.agent.use_ai', false);
        Config::set('automation.render.hyperframes.agent.copilot_reviewed', false);
        Config::set('automation.render.hyperframes.agent.estimated_call_micros', 20_000);
        Config::set('automation.dry_run', true);
        Config::set('remotion.require_verification', true);
        Config::set('remotion.expected_codec', 'h264');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        Mockery::close();

        parent::tearDown();
    }

    public function test_driver_binding_defaults_to_remotion_and_switches_to_hyperframes(): void
    {
        $this->assertSame('remotion', app(VideoRenderer::class)->getDriver());

        Config::set('automation.render.driver', 'hyperframes');

        $this->assertSame('hyperframes', app(VideoRenderer::class)->getDriver());
    }

    public function test_config_off_fails_closed_without_spawning_a_process(): void
    {
        FeatureFlag::setFlag('render.hyperframes', true);

        $render = $this->makeRender();
        $result = app(HyperFramesRenderService::class)->render($render);

        $this->assertSame('failed', $result->status->value);
        $this->assertStringContainsString('[gate]', (string) $result->error_message);
        $this->assertSame([], $this->runner->calls);
        $this->assertNull($result->output_path);
    }

    public function test_missing_feature_flag_fails_closed_even_when_config_is_on(): void
    {
        Config::set('automation.render.hyperframes.enabled', true);

        $render = $this->makeRender();
        $result = app(HyperFramesRenderService::class)->render($render);

        $this->assertSame('failed', $result->status->value);
        $this->assertStringContainsString('[gate]', (string) $result->error_message);
        $this->assertSame([], $this->runner->calls);
    }

    public function test_missing_cli_fails_closed_before_lint_or_render(): void
    {
        $this->enableGate();
        Config::set('automation.render.hyperframes.cli', $this->scratch.'/toolchain/missing-cli.mjs');

        $render = $this->makeRender();
        $result = app(HyperFramesRenderService::class)->render($render);

        $this->assertSame('failed', $result->status->value);
        $this->assertStringContainsString('[availability]', (string) $result->error_message);
        $this->assertSame([], $this->runner->calls);
    }

    public function test_lint_failure_blocks_render(): void
    {
        $this->enableGate();
        $this->runner->queue(new ProcessResult(false, 1, '', 'lint: unknown word'));

        $render = $this->makeRender();
        $result = app(HyperFramesRenderService::class)->render($render);

        $this->assertSame('failed', $result->status->value);
        $this->assertStringContainsString('[lint]', (string) $result->error_message);
        $this->assertSame(1, count($this->runner->calls));
        $this->assertContains('lint', $this->runner->calls[0]);
        $this->assertNotContains('render', $this->runner->calls[0]);
    }

    public function test_successful_lint_render_and_ffprobe_updates_the_render_model(): void
    {
        $this->enableGate();
        $this->runner->queue(new ProcessResult(true, 0, '0 errors', ''));
        $this->runner->queue(new ProcessResult(true, 0, 'render complete', ''));

        $probe = Mockery::mock(MediaProbeService::class)->makePartial();
        $probe->shouldReceive('probe')->once()->andReturn($this->probeMetadata());
        $this->app->instance(MediaProbeService::class, $probe);

        $render = $this->makeRender();
        $result = app(HyperFramesRenderService::class)->render($render);

        $this->assertSame('completed', $result->status->value);
        $this->assertSame('1080x1920', $result->resolution);
        $this->assertSame(30, $result->fps);
        $this->assertSame(6, $result->duration);
        $this->assertStringEndsWith('render_'.$render->getKey().'_hf.mp4', (string) $result->output_path);
        $this->assertFileExists($this->scratch.'/video_out/render_'.$render->getKey().'_hf.mp4');

        $this->assertCount(2, $this->runner->calls);
        $this->assertContains('lint', $this->runner->calls[0]);
        $this->assertContains('render', $this->runner->calls[1]);
        $this->assertContains('--output', $this->runner->calls[1]);
    }

    public function test_template_builder_is_byte_identical_for_identical_input(): void
    {
        $script = $this->makeScript();
        $builder = $this->makeBuilder($this->scratch.'/deterministic');
        $options = ['run_id' => 'deterministic', 'preset' => '9:16', 'theme' => 'cyber'];

        $first = $builder->buildFromScript($script, $options);
        $firstHashes = $this->hashTree($first['projectDir']);

        $second = $builder->buildFromScript($script, $options);
        $secondHashes = $this->hashTree($second['projectDir']);

        $this->assertSame($firstHashes, $secondHashes);
        $this->assertArrayHasKey('index.html', $firstHashes);
        $this->assertArrayHasKey('manifest.json', $firstHashes);
        $this->assertArrayHasKey('compositions/scene-1.html', $firstHashes);
    }

    public function test_template_builder_uses_preset_dimensions(): void
    {
        $script = $this->makeScript();
        $builder = $this->makeBuilder($this->scratch.'/presets');

        foreach (['9:16' => [1080, 1920], '16:9' => [1920, 1080], '1:1' => [1080, 1080]] as $preset => [$width, $height]) {
            $built = $builder->buildFromScript($script, [
                'run_id' => 'preset-'.str_replace(':', 'x', $preset),
                'preset' => $preset,
            ]);

            $this->assertSame($width, $built['manifest']['width'], $preset);
            $this->assertSame($height, $built['manifest']['height'], $preset);
            $this->assertStringContainsString('data-width="'.$width.'"', $built['html'], $preset);
            $this->assertStringContainsString('data-height="'.$height.'"', $built['html'], $preset);
        }
    }

    public function test_captions_are_only_emitted_when_truthful_word_timings_exist(): void
    {
        $script = $this->makeScript();
        $builder = $this->makeBuilder($this->scratch.'/captions');

        $without = $builder->buildFromScript($script, ['run_id' => 'captions-none']);
        $this->assertSame('none', $without['manifest']['captions']);
        $this->assertFileDoesNotExist($without['projectDir'].'/compositions/captions.html');

        CaptionTrack::create([
            'video_script_id' => $script->getKey(),
            'language' => 'en',
            'source' => 'verified',
            'status' => 'ready',
            'verified_at' => now(),
            'word_timings' => [
                ['word' => 'Real', 'startMs' => 0, 'endMs' => 300],
                ['word' => 'timings', 'startMs' => 300, 'endMs' => 650],
                ['word' => 'only', 'startMs' => 650, 'endMs' => 900],
                ['word' => 'no', 'startMs' => 900, 'endMs' => 1050],
                ['word' => 'invention', 'startMs' => 1050, 'endMs' => 1500],
                ['word' => 'at', 'startMs' => 1500, 'endMs' => 1650],
                ['word' => 'all', 'startMs' => 1650, 'endMs' => 1800],
            ],
        ]);

        $with = $builder->buildFromScript($script, ['run_id' => 'captions-yes']);
        $this->assertSame('included', $with['manifest']['captions']);
        $this->assertFileExists($with['projectDir'].'/compositions/captions.html');

        $html = (string) file_get_contents($with['projectDir'].'/compositions/captions.html');
        $this->assertStringContainsString('Verified transcript', $html);
        $this->assertStringContainsString('caption-1-w-0', $html);
        $this->assertStringContainsString('caption-2-w-0', $html);
        $this->assertStringContainsString('tl.set("#caption-1-card"', $html);
        $this->assertStringContainsString('color: "#00d4ff"', $html);
    }

    public function test_generated_compositions_are_fully_offline_and_use_vendored_gsap(): void
    {
        $script = $this->makeScript();
        $built = $this->makeBuilder($this->scratch.'/offline')->buildFromScript($script, [
            'run_id' => 'offline',
        ]);

        $files = [
            $built['projectDir'].'/index.html',
            $built['projectDir'].'/compositions/scene-1.html',
            $built['projectDir'].'/compositions/scene-2.html',
        ];

        foreach ($files as $file) {
            $html = (string) file_get_contents($file);
            $this->assertStringNotContainsString('http://', $html, $file);
            $this->assertStringNotContainsString('https://', $html, $file);
            $this->assertStringNotContainsString('cdn.', $html, $file);
        }

        $this->assertStringContainsString('assets/vendor/gsap.min.js', $built['html']);
        $this->assertFileExists($built['projectDir'].'/assets/vendor/gsap.min.js');
    }

    public function test_verified_visual_assets_are_copied_from_the_public_disk_with_attribution(): void
    {
        $relative = 'assets/hf-test-'.bin2hex(random_bytes(4)).'.png';
        $absolute = storage_path('app/public/'.$relative);
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, 'fake-image-bytes');

        try {
            $script = $this->makeScript();
            $visual = VisualAsset::create([
                'type' => 'image',
                'storage_path' => $relative,
                'license_type' => 'cc0',
                'attribution_text' => 'Photo: Test Source',
                'status' => 'verified',
            ]);
            SceneAsset::create([
                'video_script_id' => $script->getKey(),
                'scene_index' => 1,
                'visual_asset_id' => $visual->getKey(),
                'role' => 'b_roll',
                'status' => 'verified',
            ]);

            $built = $this->makeBuilder($this->scratch.'/assets')->buildFromScript($script, [
                'run_id' => 'assets',
            ]);

            $this->assertFileExists($built['projectDir'].'/assets/media/scene-1-'.$visual->getKey().'.png');
            $sceneHtml = (string) file_get_contents($built['projectDir'].'/compositions/scene-1.html');
            $this->assertStringContainsString('assets/media/scene-1-'.$visual->getKey().'.png', $sceneHtml);
            $this->assertStringContainsString('Photo: Test Source', $sceneHtml);
        } finally {
            File::delete($absolute);
        }
    }

    public function test_duplicate_audio_rows_are_deduplicated_to_one_voice_and_one_music_bed(): void
    {
        $voiceRelative = 'assets/hf-audio-voice-'.bin2hex(random_bytes(4)).'.wav';
        $musicRelative = 'assets/hf-audio-music-'.bin2hex(random_bytes(4)).'.wav';
        $voiceAbsolute = storage_path('app/public/'.$voiceRelative);
        $musicAbsolute = storage_path('app/public/'.$musicRelative);
        File::ensureDirectoryExists(dirname($voiceAbsolute));
        File::put($voiceAbsolute, 'voice-bytes');
        File::put($musicAbsolute, 'music-bytes');

        try {
            $script = $this->makeScript();

            foreach ([1, 2, 3] as $_) {
                AudioAsset::create([
                    'video_script_id' => $script->getKey(),
                    'kind' => 'narration',
                    'storage_path' => $voiceRelative,
                ]);
                AudioAsset::create([
                    'video_script_id' => $script->getKey(),
                    'kind' => 'background',
                    'storage_path' => $musicRelative,
                ]);
            }

            AudioAsset::create([
                'video_script_id' => $script->getKey(),
                'kind' => 'narration',
                'storage_path' => 'assets/missing-audio.wav',
            ]);

            $built = $this->makeBuilder($this->scratch.'/audio')->buildFromScript($script, [
                'run_id' => 'audio',
            ]);

            $this->assertCount(2, $built['manifest']['audio']);
            $this->assertFileExists($built['projectDir'].'/'.$built['manifest']['audio'][0]);
            $this->assertFileExists($built['projectDir'].'/'.$built['manifest']['audio'][1]);
            $this->assertStringContainsString('data-volume="1"', $built['html']);
            $this->assertStringContainsString('data-volume="0.12"', $built['html']);
        } finally {
            File::delete($voiceAbsolute);
            File::delete($musicAbsolute);
        }
    }

    public function test_long_headlines_are_scaled_down_to_fit_the_safe_band(): void
    {
        $script = VideoScript::factory()->create([
            'title' => 'Long headline test',
            'language' => 'en',
            'target_duration' => 3,
            'status' => VideoScriptStatus::Approved,
            'scenes' => [[
                'order' => 1,
                'type' => 'intro',
                'duration' => 3,
                'headline' => str_repeat('A very long grounded headline that must not overflow the vertical safe band. ', 2),
                'description' => 'Description',
            ]],
        ]);

        $built = $this->makeBuilder($this->scratch.'/long-title')->buildFromScript($script, [
            'run_id' => 'long-title',
        ]);

        $sceneHtml = (string) file_get_contents($built['projectDir'].'/compositions/scene-1.html');
        $this->assertStringContainsString('font-size: 54px', $sceneHtml);
        $this->assertStringNotContainsString('font-size: 96px', $sceneHtml);
    }

    public function test_agent_dry_run_falls_back_to_template_with_zero_spend_and_zero_rows(): void
    {
        $this->enableGate();
        $this->enableAgent();
        Config::set('automation.render.hyperframes.agent.use_ai', true);
        Config::set('automation.dry_run', true);

        $script = $this->makeScript();
        $built = app(HyperFramesAgentAuthor::class)->authorOrFallback($script, ['run_id' => 'agent-dry']);

        $this->assertSame('template_dry_run', $built['manifest']['mode']);
        $this->assertStringContainsString('data-composition-id', $built['html']);
        $this->assertSame(
            'template_dry_run',
            json_decode((string) file_get_contents($built['projectDir'].'/manifest.json'), true)['mode'] ?? null,
        );
        $this->assertSame(0, AiUsageLedgerEntry::query()->count());
        $this->assertSame(0, DB::table('budget_periods')->count());
        $this->assertSame([], $this->runner->calls);
    }

    public function test_agent_rejects_network_and_nondeterministic_html(): void
    {
        $agent = app(HyperFramesAgentAuthor::class);

        $agent->assertSafeHtml('<html><div data-composition-id="x"></div><script>window.__timelines["x"] = gsap.timeline({paused:true});</script></html>');

        foreach (['https://cdn.example/gsap.js', 'Date.now()', 'Math.random()', 'setTimeout(fn, 1)'] as $forbidden) {
            try {
                $agent->assertSafeHtml('<html><div data-composition-id="x"></div><script>window.__timelines["x"]='.$forbidden.'</script></html>');
                $this->fail('Expected rejection of '.$forbidden);
            } catch (HyperFramesLintException $exception) {
                $this->assertStringContainsString('forbidden', $exception->getMessage());
            }
        }
    }

    public function test_agent_paid_path_reserves_budget_and_settles_usage_through_the_ledger_contract(): void
    {
        $this->enableGate();
        $this->enableAgent();
        Config::set('automation.render.hyperframes.agent.use_ai', true);
        Config::set('automation.render.hyperframes.agent.copilot_reviewed', true);
        Config::set('automation.dry_run', false);

        $reservation = new BudgetReservation(1, 20_000);

        $budget = Mockery::mock(BudgetGuard::class);
        $budget->shouldReceive('authorize')->once()->with(20_000)->andReturn($reservation);
        $budget->shouldReceive('settle')->once()->withArgs(function (BudgetReservation $given, int $cost, array $usage) use ($reservation): bool {
            return $given === $reservation
                && $cost === 20_000
                && $usage['purpose'] === AiUsagePurpose::Script
                && $usage['provider'] === 'ollama';
        })->andReturn(Mockery::mock(BudgetPeriod::class));
        $budget->shouldReceive('release')->never();

        $breaker = Mockery::mock(CircuitBreaker::class);
        $breaker->shouldReceive('canAttempt')->once()->with('ai')->andReturnTrue();
        $breaker->shouldReceive('recordSuccess')->once()->with('ai');

        $dryRun = Mockery::mock(DryRunContext::class);
        $dryRun->shouldReceive('isDryRun')->andReturnFalse();
        $dryRun->shouldReceive('providerFor')->with('ai')->andReturn('ollama');

        $manager = Mockery::mock(AIManager::class);
        $manager->shouldReceive('generate')->once()->andReturn(
            '<!doctype html><html><body><div id="root" data-composition-id="agent" data-start="0" data-duration="6" data-width="1080" data-height="1920">'
            .'<div id="hero" class="clip" data-start="0" data-duration="6" data-track-index="1">Agent</div></div>'
            .'<script src="assets/vendor/gsap.min.js"></script><script>window.__timelines["agent"] = gsap.timeline({paused:true});</script></body></html>'
        );
        $manager->shouldReceive('getProviderName')->andReturn('ollama');

        $builder = $this->makeBuilder($this->scratch.'/agent-paid');
        $author = new class($builder, $this->runner, $budget, $breaker, $dryRun, $manager) extends HyperFramesAgentAuthor
        {
            public function __construct(
                HyperFramesCompositionBuilder $builder,
                ProcessRunner $runner,
                BudgetGuard $budget,
                CircuitBreaker $breaker,
                DryRunContext $dryRun,
                private readonly AIManager $manager,
            ) {
                parent::__construct($builder, $runner, $budget, $breaker, $dryRun);
            }

            protected function managerFor(string $provider): AIManager
            {
                return $this->manager;
            }
        };

        $this->runner->queue(new ProcessResult(true, 0, 'lint clean', ''));
        $this->runner->queue(new ProcessResult(true, 0, '{"ok":true}', ''));

        $built = $author->authorOrFallback($this->makeScript(), ['run_id' => 'agent-paid']);

        $this->assertSame('agent', $built['manifest']['mode']);
        $this->assertFileExists($built['projectDir'].'/index.html');
        $this->assertContains('lint', $this->runner->calls[0]);
        $this->assertContains('check', $this->runner->calls[1]);
    }

    public function test_studio_dashboard_shows_read_only_hyperframes_status(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/studio')
            ->assertOk()
            ->assertSee('HyperFrames Status')
            ->assertSee('Read-only second render backend')
            ->assertDontSee('Render HyperFrames');
    }

    private function fakeToolchain(): void
    {
        $directory = $this->scratch.'/toolchain';
        File::ensureDirectoryExists($directory);

        foreach (['node.exe', 'hyperframes.mjs', 'ffmpeg.exe', 'ffprobe.exe'] as $name) {
            File::put($directory.'/'.$name, 'fake-binary');
        }

        Config::set('automation.render.hyperframes.node_binary', $directory.'/node.exe');
        Config::set('automation.render.hyperframes.cli', $directory.'/hyperframes.mjs');
        Config::set('automation.render.hyperframes.ffmpeg_path', $directory.'/ffmpeg.exe');
        Config::set('remotion.ffprobe_path', $directory.'/ffprobe.exe');
    }

    private function enableGate(): void
    {
        Config::set('automation.render.hyperframes.enabled', true);
        FeatureFlag::setFlag('render.hyperframes', true);
    }

    private function enableAgent(): void
    {
        Config::set('automation.render.hyperframes.agent.enabled', true);
        FeatureFlag::setFlag('render.hyperframes.agent', true);
    }

    private function makeScript(): VideoScript
    {
        return VideoScript::factory()->create([
            'title' => 'HyperFrames Test Script',
            'language' => 'en',
            'target_duration' => 6,
            'status' => VideoScriptStatus::Approved,
            'scenes' => [
                [
                    'order' => 1,
                    'type' => 'intro',
                    'duration' => 3,
                    'headline' => 'First beat',
                    'description' => 'The hook lands immediately.',
                    'directives' => ['highlight_text' => 'Hook in 1.5s'],
                ],
                [
                    'order' => 2,
                    'type' => 'conclusion',
                    'duration' => 3,
                    'headline' => 'Verified output',
                    'description' => 'FFprobe confirms the render.',
                    'directives' => ['highlight_text' => 'h264 1080x1920'],
                ],
            ],
        ]);
    }

    private function makeRender(): VideoRender
    {
        return VideoRender::factory()->create([
            'video_script_id' => $this->makeScript()->getKey(),
            'template' => '9:16',
            'status' => 'pending',
        ]);
    }

    private function makeBuilder(string $outputRoot): HyperFramesCompositionBuilder
    {
        return new HyperFramesCompositionBuilder(base_path('hyperframes'), $outputRoot);
    }

    /**
     * @return array<string, string>
     */
    private function hashTree(string $directory): array
    {
        $hashes = [];

        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $hashes[$relative] = (string) sha1_file($file->getPathname());
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeMetadata(): array
    {
        return [
            'path' => 'dummy.mp4',
            'available' => true,
            'verified' => true,
            'reason' => null,
            'video_stream' => true,
            'audio_stream' => false,
            'streams_count' => 1,
            'codec' => 'h264',
            'width' => 1080,
            'height' => 1920,
            'fps' => 30.0,
            'duration' => 6.0,
            'frames' => 180,
            'size' => 512,
            'format' => 'mov,mp4,m4a,3gp,3g2,mj2',
            'ffprobe_path' => 'ffprobe',
        ];
    }
}

class FakeHyperFramesProcessRunner extends ProcessRunner
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var list<ProcessResult> */
    private array $queue = [];

    public function queue(ProcessResult $result): void
    {
        $this->queue[] = $result;
    }

    public function run(array $command, ?string $cwd = null, array $env = [], ?int $timeout = null): ProcessResult
    {
        $this->calls[] = $command;

        if (in_array('render', $command, true)) {
            $outputIndex = array_search('--output', $command, true);

            if ($outputIndex !== false && isset($command[$outputIndex + 1])) {
                File::ensureDirectoryExists(dirname($command[$outputIndex + 1]));
                File::put($command[$outputIndex + 1], str_repeat('fake-mp4', 64));
            }
        }

        return array_shift($this->queue) ?? new ProcessResult(true, 0, '', '');
    }
}
