<?php

namespace Tests\Feature;

use App\Services\Avatar\AvatarPrng;
use App\Services\Avatar\AvatarQaService;
use App\Services\Avatar\PresenterCompositionWriter;
use App\Services\Avatar\PresenterStyleGenerator;
use App\Services\Avatar\VariationEngine;
use App\Services\Avatar\VisemeMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AvatarM3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_style_generator_determinism(): void
    {
        $style1 = PresenterStyleGenerator::generate(new AvatarPrng(101));
        $style2 = PresenterStyleGenerator::generate(new AvatarPrng(101));

        $this->assertSame($style1, $style2);
    }

    public function test_style_generator_different_seeds_vary_dimensions(): void
    {
        $styleA = PresenterStyleGenerator::generate(new AvatarPrng(101));
        $styleB = PresenterStyleGenerator::generate(new AvatarPrng(999));

        $this->assertNotSame($styleA, $styleB);
        $fpA = PresenterStyleGenerator::styleFingerprint($styleA);
        $fpB = PresenterStyleGenerator::styleFingerprint($styleB);
        $this->assertNotSame($fpA, $fpB);
    }

    public function test_style_generator_variation_levels(): void
    {
        $prngLow = new AvatarPrng(42);
        $styleLow = PresenterStyleGenerator::generate($prngLow, 'low');
        $this->assertSame('calm_editorial', $styleLow['camera_motion']);
        $this->assertContains($styleLow['set_style'], ['gradient_minimal', 'modern_loft']);

        $prngHigh = new AvatarPrng(42);
        $styleHigh = PresenterStyleGenerator::generate($prngHigh, 'high');
        $this->assertContains($styleHigh['camera_motion'], ['slow_push_in', 'dynamic_float']);
        $this->assertContains($styleHigh['set_style'], ['cyber_grid', 'dark_emitter', 'newsroom_blur']);
    }

    public function test_style_fingerprint_format(): void
    {
        $style = PresenterStyleGenerator::generate(new AvatarPrng(42));
        $fp = PresenterStyleGenerator::styleFingerprint($style);

        $this->assertSame(64, strlen($fp));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fp);
    }

    public function test_mode_b_locks_character_identity_across_seeds(): void
    {
        $engine = app(VariationEngine::class);

        $run1 = $engine->selectCharacter(111, 'run_m3_1', ['mode' => 'fixed', 'character_id' => 'anchor_dan']);
        $run2 = $engine->selectCharacter(222, 'run_m3_2', ['mode' => 'fixed', 'character_id' => 'anchor_dan']);
        $run3 = $engine->selectCharacter(333, 'run_m3_3', ['mode' => 'fixed', 'character_id' => 'anchor_dan']);

        // Identity must be 100% locked
        $this->assertSame('anchor_dan', $run1['character_id']);
        $this->assertSame('anchor_dan', $run2['character_id']);
        $this->assertSame('anchor_dan', $run3['character_id']);

        $this->assertSame($run1['spec'], $run2['spec']);
        $this->assertSame($run1['spec'], $run3['spec']);

        $this->assertSame($run1['fingerprint'], $run2['fingerprint']);
        $this->assertSame($run1['fingerprint'], $run3['fingerprint']);
    }

    public function test_mode_b_varies_run_styles_across_seeds(): void
    {
        $engine = app(VariationEngine::class);

        $run1 = $engine->selectCharacter(111, 'run_m3_1', ['mode' => 'fixed', 'character_id' => 'anchor_dan']);
        $run2 = $engine->selectCharacter(222, 'run_m3_2', ['mode' => 'fixed', 'character_id' => 'anchor_dan']);

        // Face is identical, but style must vary
        $this->assertSame($run1['fingerprint'], $run2['fingerprint']);
        $this->assertNotSame($run1['style_fingerprint'], $run2['style_fingerprint']);
        $this->assertNotSame($run1['style_spec'], $run2['style_spec']);
    }

    public function test_qa_gate3_style_integrity_passes_valid_presenter_html(): void
    {
        $engine = app(VariationEngine::class);
        $char = $engine->selectCharacter(101, 'run_test_qa3', ['mode' => 'fixed']);

        $timings = [
            ['word' => 'hello', 'startMs' => 0, 'endMs' => 400],
            ['word' => 'world', 'startMs' => 500, 'endMs' => 900],
        ];
        $track = VisemeMapper::buildTrack($timings, 3.0, 30);
        $manifest = [
            'total_duration' => 3.0,
            'narration' => [['scene_id' => 'scene-1', 'word_timings' => $timings]],
        ];

        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pvg_avatar_m3_'.uniqid();
        $writer = app(PresenterCompositionWriter::class);
        $relPath = $writer->write($char['spec'], $track, $manifest, $tmpDir, 101, 30, $char['style_spec']);
        $fullPath = $tmpDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relPath);

        $qa = app(AvatarQaService::class);
        $result = $qa->checkStyleIntegrity($fullPath);

        $this->assertTrue($result['pass'], $result['message']);
        $this->assertTrue($result['has_sfx_cue']);
        $this->assertTrue($result['has_set_style']);
        $this->assertTrue($result['has_tool']);

        // Cleanup
        @unlink($fullPath);
        @rmdir(dirname($fullPath));
        @rmdir($tmpDir);
    }

    public function test_qa_gate3_fails_when_style_metadata_missing(): void
    {
        $qa = app(AvatarQaService::class);
        $tmpFile = tempnam(sys_get_temp_dir(), 'qa3_fail_');
        file_put_contents($tmpFile, '<div>Plain content without metadata</div>');

        $result = $qa->checkStyleIntegrity($tmpFile);
        $this->assertFalse($result['pass']);
        $this->assertFalse($result['has_sfx_cue']);

        @unlink($tmpFile);
    }

    public function test_verify_command_mode_b_three_runs_verifies_identity_and_style_variation(): void
    {
        $outputDir = storage_path('app/hyperframes/test_m3_verify_cli');

        $this->artisan('ai-pvg:verify-avatar', [
            '--count' => 3,
            '--mode' => 'fixed',
            '--character-id' => 'anchor_sarah',
            '--seed' => 101,
            '--execute' => true,
            '--force' => true,
            '--output' => $outputDir,
        ])
            ->expectsOutputToContain('Mode B Fixed Character Invariance & Style Variation Proof')
            ->expectsOutputToContain('Character Identity across all 3 runs: LOCKED (100% Identical)')
            ->expectsOutputToContain('M3 PASS')
            ->assertExitCode(0);

        // Verify compositions were generated
        $this->assertFileExists($outputDir.'/char_0/compositions/presenter.html');
        $this->assertFileExists($outputDir.'/char_1/compositions/presenter.html');
        $this->assertFileExists($outputDir.'/char_2/compositions/presenter.html');

        // Cleanup
        $this->deleteDir($outputDir);
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
