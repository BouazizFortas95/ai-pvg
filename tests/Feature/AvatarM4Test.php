<?php

namespace Tests\Feature;

use App\Services\Avatar\AvatarQaService;
use App\Services\Avatar\EmotionAnnotator;
use App\Services\Avatar\ToneGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AvatarM4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_tone_guard_classifies_tragic_keywords(): void
    {
        $this->assertSame('tragic', ToneGuard::classify('Breaking: 12 people killed in disaster'));
        $this->assertSame('tragic', ToneGuard::classify('Fatal explosion at warehouse'));
        $this->assertSame('tragic', ToneGuard::classify('Casualties reported in war zone'));
    }

    public function test_tone_guard_classifies_sensitive_keywords(): void
    {
        $this->assertSame('sensitive', ToneGuard::classify('Company announces massive layoff across departments'));
        $this->assertSame('sensitive', ToneGuard::classify('Security breach exposes millions of user credentials'));
        $this->assertSame('sensitive', ToneGuard::classify('FTC files antitrust lawsuit against tech giant'));
    }

    public function test_tone_guard_classifies_normal_tech_news(): void
    {
        $this->assertSame('normal', ToneGuard::classify('OpenAI launches Nova-1, its most capable AI model yet'));
        $this->assertSame('normal', ToneGuard::classify('Startup secures 50M funding round for quantum computing'));
    }

    public function test_tone_guard_bans_smiling_in_tragic_contexts(): void
    {
        $candidate = [
            'expression' => 'subtle_smile',
            'gesture' => 'hand_emphasize',
            'intensity' => 0.8,
            'sfx_cue' => 'snap',
        ];

        $guarded = ToneGuard::guard('Explosion kills five at factory', $candidate);

        $this->assertTrue($guarded['guarded']);
        $this->assertSame('serious', $guarded['expression']);
        $this->assertSame('rest', $guarded['gesture']);
        $this->assertSame('sub_drop', $guarded['sfx_cue']);
        $this->assertStringContainsString('banned smiling', $guarded['intervention_reason']);
    }

    public function test_tone_guard_bans_smiling_in_sensitive_contexts(): void
    {
        $candidate = [
            'expression' => 'subtle_smile',
            'gesture' => 'subtle_nod',
            'intensity' => 0.7,
        ];

        $guarded = ToneGuard::guard('Tech company hit with severe lawsuit and breach allegations', $candidate);

        $this->assertTrue($guarded['guarded']);
        $this->assertSame('serious', $guarded['expression']);
    }

    public function test_emotion_annotator_parses_scenes(): void
    {
        $annotator = new EmotionAnnotator;
        $scenes = [
            [
                'scene_id' => 'scene-1',
                'start' => 0.0,
                'duration' => 6.0,
                'text' => 'Breaking: OpenAI launches Nova-1 AI model setting record benchmarks!',
            ],
            [
                'scene_id' => 'scene-2',
                'start' => 6.2,
                'duration' => 5.0,
                'text' => 'Why does this release matter for the future of software engineering?',
            ],
        ];

        $timeline = $annotator->annotateScenes($scenes);

        $this->assertCount(2, $timeline);
        $this->assertSame('confident', $timeline[0]['expression']);
        $this->assertSame('thoughtful', $timeline[1]['expression']);
        $this->assertArrayHasKey('offsets', $timeline[0]);
        $this->assertLessThanOrEqual($timeline[0]['end'], $timeline[0]['gesture_return_time']);
    }

    public function test_expression_rate_limiter_smoothes_rapid_changes(): void
    {
        $annotator = new EmotionAnnotator;
        // Two consecutive scenes spaced only 0.15s apart
        $scenes = [
            ['scene_id' => 's1', 'start' => 0.0, 'duration' => 2.0, 'text' => 'Breaking news update!'],
            ['scene_id' => 's2', 'start' => 0.2, 'duration' => 2.0, 'text' => 'Why is this happening now?'],
        ];

        $timeline = $annotator->annotateScenes($scenes);

        // Second scene should inherit s1 expression because interval is 0.2s (< 0.33s)
        $this->assertSame($timeline[0]['expression'], $timeline[1]['expression']);
    }

    public function test_qa_gate4_passes_compliant_timeline(): void
    {
        $qa = new AvatarQaService;
        $timeline = [
            ['sensitivity' => 'normal', 'expression' => 'subtle_smile', 'gesture' => 'hand_emphasize'],
            ['sensitivity' => 'sensitive', 'expression' => 'serious', 'gesture' => 'subtle_nod'],
            ['sensitivity' => 'tragic', 'expression' => 'serious', 'gesture' => 'rest'],
        ];

        $result = $qa->checkToneGuardCompliance($timeline);
        $this->assertTrue($result['pass'], $result['message']);
        $this->assertEmpty($result['violations']);
    }

    public function test_qa_gate4_catches_tone_violations(): void
    {
        $qa = new AvatarQaService;
        $timeline = [
            ['sensitivity' => 'tragic', 'expression' => 'subtle_smile', 'gesture' => 'hand_emphasize'],
        ];

        $result = $qa->checkToneGuardCompliance($timeline);
        $this->assertFalse($result['pass']);
        $this->assertNotEmpty($result['violations']);
    }

    public function test_qa_gate5_expression_rate_limit(): void
    {
        $qa = new AvatarQaService;

        $goodTimeline = [
            ['expression' => 'neutral', 'start' => 0.0],
            ['expression' => 'confident', 'start' => 2.5],
            ['expression' => 'thoughtful', 'start' => 5.2],
        ];
        $this->assertTrue($qa->checkExpressionRateLimit($goodTimeline)['pass']);

        $rapidTimeline = [
            ['expression' => 'neutral', 'start' => 0.0],
            ['expression' => 'confident', 'start' => 0.1], // 0.1s is too fast!
        ];
        $this->assertFalse($qa->checkExpressionRateLimit($rapidTimeline)['pass']);
    }

    public function test_verify_command_runs_m4_and_passes_all_six_gates(): void
    {
        $outputDir = storage_path('app/hyperframes/test_m4_verify_cli');

        $this->artisan('ai-pvg:verify-avatar', [
            '--count' => 1,
            '--seed' => 42,
            '--execute' => true,
            '--force' => true,
            '--output' => $outputDir,
        ])
            ->expectsOutputToContain('[QA Gate 1]')
            ->expectsOutputToContain('[QA Gate 2]')
            ->expectsOutputToContain('[QA Gate 3]')
            ->expectsOutputToContain('[QA Gate 4]')
            ->expectsOutputToContain('[QA Gate 5]')
            ->expectsOutputToContain('[QA Gate 6]')
            ->expectsOutputToContain('M4 PASS')
            ->assertExitCode(0);

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
