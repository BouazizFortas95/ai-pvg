<?php

namespace Tests\Feature;

use App\Services\Avatar\AvatarPrng;
use App\Services\Avatar\AvatarQaService;
use App\Services\Avatar\CharacterGenerator;
use App\Services\Avatar\CharacterSpec;
use App\Services\Avatar\PresenterStyleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AvatarM6Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function getCompliantPresenterHtml(): string
    {
        return '<div id="pvg-presenter" data-composition-id="pvg-presenter" data-sfx-cue="snap" data-set-style="modern_loft">
            <div id="pvg-tool" class="pvg-tool-ticker">Ticker</div>
            <div class="pvg-badge">AI-generated presenter</div>
            <g id="pvg-eyelid-left" opacity="0"></g>
            <g id="pvg-eyelid-right" opacity="0"></g>
            <script>
                window.__timelines = window.__timelines || {};
                window.__timelines["pvg-presenter"] = gsap.timeline();
                tl.to("#pvg-arm-right", { rotate: 0, duration: 0.35 }, tReturn);
            </script>
        </div>';
    }

    public function test_full_audit_passes_on_compliant_data(): void
    {
        $qa = app(AvatarQaService::class);

        $spec = CharacterSpec::generate(new AvatarPrng(12345));
        $fp = CharacterGenerator::fingerprint($spec);
        $style = PresenterStyleGenerator::generate(new AvatarPrng(12345), 'medium');
        $styleFp = PresenterStyleGenerator::styleFingerprint($style);

        $wordTimings = [
            ['word' => 'Hello', 'startMs' => 0, 'endMs' => 500],
            ['word' => 'World', 'startMs' => 600, 'endMs' => 1000],
        ];

        // 30 fps * 1.5s = 45 frames. Spoken frames: 0..15 (0..500ms) and 18..30 (600..1000ms)
        $lipSyncTrack = [];
        for ($f = 0; $f < 45; $f++) {
            $lipSyncTrack[] = [
                'frame' => $f,
                'openness' => ($f <= 15 || ($f >= 18 && $f <= 30)) ? 0.8 : 0.0,
                'viseme' => 'wide',
            ];
        }

        $emotionTimeline = [
            [
                'scene_id' => 'scene-1',
                'start' => 0.0,
                'end' => 3.0,
                'duration' => 3.0,
                'sensitivity' => 'normal',
                'expression' => 'confident',
                'gesture' => 'subtle_nod',
                'gesture_start_time' => 0.2,
                'gesture_return_time' => 1.8,
            ],
            [
                'scene_id' => 'scene-2',
                'start' => 3.0,
                'end' => 6.0,
                'duration' => 3.0,
                'sensitivity' => 'normal',
                'expression' => 'neutral',
                'gesture' => 'rest',
            ],
        ];

        $html = $this->getCompliantPresenterHtml();

        $runA = ['fingerprint' => $fp, 'style_fingerprint' => $styleFp, 'spec' => $spec];
        $runB = ['fingerprint' => $fp, 'style_fingerprint' => $styleFp, 'spec' => $spec];

        $result = $qa->runFullAudit([
            'lip_sync_track' => $lipSyncTrack,
            'word_timings' => $wordTimings,
            'emotion_timeline' => $emotionTimeline,
            'presenter_html' => $html,
            'determinism_runs' => [$runA, $runB],
        ]);

        $this->assertTrue($result['all_passed']);
        $this->assertSame(7, $result['passed_count']);
        $this->assertSame(7, $result['total_count']);
        $this->assertStringContainsString('ALL 7/7 QA GATES PASSED', $result['summary']);

        $this->assertTrue($result['gates']['gate1_lip_sync']['pass']);
        $this->assertTrue($result['gates']['gate2_liveness']['pass']);
        $this->assertTrue($result['gates']['gate3_style']['pass']);
        $this->assertTrue($result['gates']['gate4_tone_guard']['pass']);
        $this->assertTrue($result['gates']['gate5_rate_limit']['pass']);
        $this->assertTrue($result['gates']['gate6_gesture_rest']['pass']);
        $this->assertTrue($result['gates']['gate7_determinism']['pass']);
    }

    public function test_full_audit_catches_gate1_lip_sync_failure(): void
    {
        $qa = app(AvatarQaService::class);
        $spec = CharacterSpec::generate(new AvatarPrng(42));
        $fp = CharacterGenerator::fingerprint($spec);
        $style = PresenterStyleGenerator::generate(new AvatarPrng(42), 'medium');
        $styleFp = PresenterStyleGenerator::styleFingerprint($style);

        $wordTimings = [['word' => 'Test', 'startMs' => 0, 'endMs' => 1000]];
        // 0 openness during speech
        $lipSyncTrack = [
            ['frame' => 0, 'openness' => 0.0, 'viseme' => 'closed'],
            ['frame' => 10, 'openness' => 0.0, 'viseme' => 'closed'],
        ];

        $result = $qa->runFullAudit([
            'lip_sync_track' => $lipSyncTrack,
            'word_timings' => $wordTimings,
            'emotion_timeline' => [],
            'presenter_html' => $this->getCompliantPresenterHtml(),
            'determinism_runs' => [
                ['fingerprint' => $fp, 'style_fingerprint' => $styleFp, 'spec' => $spec],
                ['fingerprint' => $fp, 'style_fingerprint' => $styleFp, 'spec' => $spec],
            ],
        ]);

        $this->assertFalse($result['all_passed']);
        $this->assertFalse($result['gates']['gate1_lip_sync']['pass']);
        $this->assertStringContainsString('FAIL lip-sync coverage', $result['gates']['gate1_lip_sync']['message']);
    }

    public function test_full_audit_catches_gate2_liveness_failure(): void
    {
        $qa = app(AvatarQaService::class);

        // Missing AI disclosure badge
        $badHtml = '<div id="pvg-presenter" data-sfx-cue="snap" data-set-style="modern_loft">
            <div id="pvg-tool">tool</div>
            <g id="pvg-eyelid-left"></g><g id="pvg-eyelid-right"></g>
            <script>window.__timelines["pvg-presenter"] = {}; rotate: 0; tReturn;</script>
        </div>';

        $result = $qa->runFullAudit([
            'lip_sync_track' => [],
            'word_timings' => [],
            'emotion_timeline' => [],
            'presenter_html' => $badHtml,
        ]);

        $this->assertFalse($result['all_passed']);
        $this->assertFalse($result['gates']['gate2_liveness']['pass']);
        $this->assertStringContainsString('missing AI disclosure badge', $result['gates']['gate2_liveness']['message']);
    }

    public function test_full_audit_catches_gate3_style_integrity_failure(): void
    {
        $qa = app(AvatarQaService::class);

        // Missing data-sfx-cue and data-set-style
        $htmlWithoutStyle = '<div id="pvg-presenter">
            <div class="pvg-badge">AI-generated presenter</div>
            <g id="pvg-eyelid-left"></g><g id="pvg-eyelid-right"></g>
            <script>window.__timelines["pvg-presenter"] = {}; rotate: 0; tReturn;</script>
        </div>';

        $result = $qa->runFullAudit([
            'lip_sync_track' => [],
            'word_timings' => [],
            'emotion_timeline' => [],
            'presenter_html' => $htmlWithoutStyle,
        ]);

        $this->assertFalse($result['all_passed']);
        $this->assertFalse($result['gates']['gate3_style']['pass']);
        $this->assertStringContainsString('missing data-sfx-cue', $result['gates']['gate3_style']['message']);
    }

    public function test_full_audit_catches_gate4_tone_guard_failure(): void
    {
        $qa = app(AvatarQaService::class);

        // Tragic beat with disallowed smile expression
        $violatingTimeline = [
            [
                'scene_id' => 'scene-1',
                'start' => 0.0,
                'end' => 3.0,
                'sensitivity' => 'tragic',
                'expression' => 'subtle_smile',
                'gesture' => 'rest',
            ],
        ];

        $result = $qa->runFullAudit([
            'lip_sync_track' => [],
            'word_timings' => [],
            'emotion_timeline' => $violatingTimeline,
            'presenter_html' => $this->getCompliantPresenterHtml(),
        ]);

        $this->assertFalse($result['all_passed']);
        $this->assertFalse($result['gates']['gate4_tone_guard']['pass']);
        $this->assertStringContainsString('tragic sensitivity has disallowed expression', $result['gates']['gate4_tone_guard']['message']);
    }

    public function test_full_audit_catches_gate5_expression_rate_limit_failure(): void
    {
        $qa = app(AvatarQaService::class);

        // Expression changes spaced 0.1s (< 0.33s minimum threshold)
        $rapidTimeline = [
            ['scene_id' => 's1', 'start' => 0.0, 'expression' => 'neutral', 'sensitivity' => 'normal', 'gesture' => 'rest'],
            ['scene_id' => 's2', 'start' => 0.1, 'expression' => 'concerned', 'sensitivity' => 'normal', 'gesture' => 'rest'],
        ];

        $result = $qa->runFullAudit([
            'lip_sync_track' => [],
            'word_timings' => [],
            'emotion_timeline' => $rapidTimeline,
            'presenter_html' => $this->getCompliantPresenterHtml(),
        ]);

        $this->assertFalse($result['all_passed']);
        $this->assertFalse($result['gates']['gate5_rate_limit']['pass']);
        $this->assertStringContainsString('fastest change interval was 0.1s', $result['gates']['gate5_rate_limit']['message']);
    }

    public function test_full_audit_catches_gate6_gesture_return_to_rest_failure(): void
    {
        $qa = app(AvatarQaService::class);

        // Gesture return time (3.5s) exceeds scene end (3.0s)
        $unreturnedTimeline = [
            [
                'scene_id' => 'scene-1',
                'start' => 0.0,
                'end' => 3.0,
                'sensitivity' => 'normal',
                'expression' => 'neutral',
                'gesture' => 'subtle_nod',
                'gesture_return_time' => 3.5,
            ],
        ];

        $result = $qa->runFullAudit([
            'lip_sync_track' => [],
            'word_timings' => [],
            'emotion_timeline' => $unreturnedTimeline,
            'presenter_html' => $this->getCompliantPresenterHtml(),
        ]);

        $this->assertFalse($result['all_passed']);
        $this->assertFalse($result['gates']['gate6_gesture_rest']['pass']);
        $this->assertStringContainsString('1 unreturned gestures', $result['gates']['gate6_gesture_rest']['message']);
    }

    public function test_full_audit_catches_gate7_determinism_failure(): void
    {
        $qa = app(AvatarQaService::class);

        $runA = [
            'fingerprint' => '1111111111111111111111111111111111111111111111111111111111111111',
            'style_fingerprint' => 'aaaa',
            'spec' => ['head_shape' => 'round'],
        ];
        $runB = [
            'fingerprint' => '2222222222222222222222222222222222222222222222222222222222222222',
            'style_fingerprint' => 'bbbb',
            'spec' => ['head_shape' => 'oval'],
        ];

        $result = $qa->runFullAudit([
            'lip_sync_track' => [],
            'word_timings' => [],
            'emotion_timeline' => [],
            'presenter_html' => $this->getCompliantPresenterHtml(),
            'determinism_runs' => [$runA, $runB],
        ]);

        $this->assertFalse($result['all_passed']);
        $this->assertFalse($result['gates']['gate7_determinism']['pass']);
        $this->assertStringContainsString('divergence detected', $result['gates']['gate7_determinism']['message']);
    }

    public function test_verify_command_runs_m6_full_audit_and_passes_all_gates(): void
    {
        $this->artisan('ai-pvg:verify-avatar', [
            '--seed' => 42,
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('AI-PVG Avatar Verify — M1 to M6')
            ->expectsOutputToContain('M6 Full QA Audit (7 Gates)')
            ->expectsOutputToContain('ALL 7/7 QA GATES PASSED — Full Avatar Pipeline M1..M6 Verified.')
            ->assertSuccessful();
    }

    public function test_verify_command_mode_b_fixed_anchor_audit(): void
    {
        $this->artisan('ai-pvg:verify-avatar', [
            '--seed' => 42,
            '--mode' => 'fixed',
            '--character-id' => 'anchor_host_01',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Mode: fixed, ID: anchor_host_01')
            ->expectsOutputToContain('ALL 7/7 QA GATES PASSED — Full Avatar Pipeline M1..M6 Verified.')
            ->assertSuccessful();
    }
}
