<?php

namespace Tests\Feature;

use App\Services\Avatar\PresenterSceneCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AvatarM5Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_presenter_scene_compiler_compiles_project(): void
    {
        $compiler = app(PresenterSceneCompiler::class);

        $manifest = [
            'total_duration' => 6.0,
            'title' => 'OpenAI launches Nova-1 AI model',
            'language' => 'en',
            'narration' => [
                [
                    'scene_id' => 'scene-1',
                    'start' => 0.0,
                    'duration' => 3.0,
                    'text' => 'Breaking: OpenAI launches Nova-1 AI model!',
                    'word_timings' => [
                        ['word' => 'Breaking:', 'startMs' => 0, 'endMs' => 500],
                        ['word' => 'OpenAI', 'startMs' => 550, 'endMs' => 1100],
                        ['word' => 'launches', 'startMs' => 1150, 'endMs' => 1800],
                        ['word' => 'Nova-1', 'startMs' => 1850, 'endMs' => 2400],
                    ],
                ],
                [
                    'scene_id' => 'scene-2',
                    'start' => 3.0,
                    'duration' => 3.0,
                    'text' => 'The model sets record benchmarks across reasoning tests.',
                    'word_timings' => [
                        ['word' => 'The', 'startMs' => 3000, 'endMs' => 3200],
                        ['word' => 'model', 'startMs' => 3250, 'endMs' => 3600],
                        ['word' => 'sets', 'startMs' => 3650, 'endMs' => 4000],
                        ['word' => 'record', 'startMs' => 4050, 'endMs' => 4600],
                    ],
                ],
            ],
        ];

        $compiled = $compiler->compile($manifest, [
            'run_id' => 'test_m5_compile',
            'seed' => 42,
            'mode' => 'infinite',
        ]);

        $projectDir = $compiled['projectDir'];
        $this->assertDirectoryExists($projectDir);
        $this->assertFileExists($projectDir.'/index.html');
        $this->assertFileExists($projectDir.'/compositions/presenter.html');

        // Verify sub-composition mount
        $indexHtml = (string) file_get_contents($projectDir.'/index.html');
        $this->assertStringContainsString('data-composition-src="compositions/presenter.html"', $indexHtml);
        $this->assertStringContainsString('data-composition-id="pvg-presenter"', $indexHtml);

        // Verify presenter HTML content
        $presenterHtml = (string) file_get_contents($projectDir.'/compositions/presenter.html');
        $this->assertStringContainsString('pvg-presenter', $presenterHtml);
        $this->assertStringContainsString('AI-generated presenter', $presenterHtml);
        $this->assertStringContainsString('pvg-badge', $presenterHtml);
    }

    public function test_presenter_scene_compiler_mode_b_fixed_anchor(): void
    {
        $compiler = app(PresenterSceneCompiler::class);

        $manifest = [
            'total_duration' => 4.0,
            'title' => 'Tech Dispatch',
            'language' => 'en',
            'narration' => [
                [
                    'scene_id' => 'scene-1',
                    'start' => 0.0,
                    'duration' => 4.0,
                    'text' => 'This is the verified anchor report.',
                    'word_timings' => [
                        ['word' => 'This', 'startMs' => 0, 'endMs' => 400],
                        ['word' => 'is', 'startMs' => 450, 'endMs' => 700],
                    ],
                ],
            ],
        ];

        $run1 = $compiler->compile($manifest, [
            'run_id' => 'test_m5_mode_b_1',
            'seed' => 101,
            'mode' => 'fixed',
            'character_id' => 'anchor_clara',
        ]);

        $run2 = $compiler->compile($manifest, [
            'run_id' => 'test_m5_mode_b_2',
            'seed' => 202,
            'mode' => 'fixed',
            'character_id' => 'anchor_clara',
        ]);

        // Identity must be identical
        $this->assertSame($run1['character']['character_id'], $run2['character']['character_id']);
        $this->assertSame($run1['character']['fingerprint'], $run2['character']['fingerprint']);
        $this->assertSame($run1['character']['spec'], $run2['character']['spec']);

        // Style must vary
        $this->assertNotSame($run1['style'], $run2['style']);
    }

    public function test_verify_command_mode_a_passes_m5(): void
    {
        $this->artisan('ai-pvg:verify-avatar', [
            '--count' => 1,
            '--mode' => 'infinite',
            '--seed' => 42,
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('[Sub-comp]    PASS')
            ->expectsOutputToContain('M5 PASS')
            ->assertExitCode(0);
    }

    public function test_verify_command_mode_b_passes_m5(): void
    {
        $this->artisan('ai-pvg:verify-avatar', [
            '--count' => 1,
            '--mode' => 'fixed',
            '--character-id' => 'anchor_marcus',
            '--seed' => 99,
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('[Sub-comp]    PASS')
            ->expectsOutputToContain('M5 PASS')
            ->assertExitCode(0);
    }
}
