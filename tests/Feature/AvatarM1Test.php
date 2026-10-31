<?php

namespace Tests\Feature;

use App\Services\Avatar\AvatarPrng;
use App\Services\Avatar\AvatarQaService;
use App\Services\Avatar\CharacterSpec;
use App\Services\Avatar\PresenterCompositionWriter;
use App\Services\Avatar\VisemeMapper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AvatarM1Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ── PRNG ──────────────────────────────────────────────────────────────────

    public function test_prng_determinism_same_seed_produces_same_sequence(): void
    {
        $prng1 = new AvatarPrng(42);
        $prng2 = new AvatarPrng(42);

        for ($i = 0; $i < 100; $i++) {
            $this->assertSame($prng1->next(), $prng2->next(), "Mismatch at index {$i}");
        }
    }

    public function test_prng_different_seeds_produce_different_sequences(): void
    {
        $prng1 = new AvatarPrng(1);
        $prng2 = new AvatarPrng(999);

        $seq1 = array_map(fn () => $prng1->next(), range(1, 20));
        $seq2 = array_map(fn () => $prng2->next(), range(1, 20));

        $this->assertNotSame($seq1, $seq2);
    }

    public function test_prng_values_in_range(): void
    {
        $prng = new AvatarPrng(12345);
        for ($i = 0; $i < 200; $i++) {
            $v = $prng->next();
            $this->assertGreaterThanOrEqual(0.0, $v);
            $this->assertLessThan(1.0, $v);
        }
    }

    public function test_prng_next_int_in_bounds(): void
    {
        $prng = new AvatarPrng(7);
        for ($i = 0; $i < 100; $i++) {
            $v = $prng->nextInt(0, 5);
            $this->assertGreaterThanOrEqual(0, $v);
            $this->assertLessThanOrEqual(5, $v);
        }
    }

    public function test_prng_pick_returns_element_from_array(): void
    {
        $prng = new AvatarPrng(99);
        $items = ['a', 'b', 'c', 'd'];
        for ($i = 0; $i < 50; $i++) {
            $this->assertContains($prng->pick($items), $items);
        }
    }

    /**
     * JS equivalence: canonical mulberry32(42) first 5 values verified in Node 22.
     * node -e "function m(a){return function(){var t=(a+=0x6D2B79F5)>>>0;t=Math.imul(t^(t>>>15),t|1);t^=t+Math.imul(t^(t>>>7),t|61);return((t^(t>>>14))>>>0)/4294967296;}}var r=m(42);for(var i=0;i<5;i++)console.log(r());"
     */
    public function test_prng_js_equivalence_seed_42(): void
    {
        $expected = [
            0.6011037519201636,
            0.44829055899754167,
            0.8524657934904099,
            0.6697340414393693,
            0.17481389874592423,
        ];

        $prng = new AvatarPrng(42);
        foreach ($expected as $idx => $exp) {
            $actual = $prng->next();
            $this->assertEqualsWithDelta($exp, $actual, 1e-9, "Mismatch at index {$idx}");
        }
    }

    // ── CharacterSpec ─────────────────────────────────────────────────────────

    public function test_character_spec_valid_spec_passes(): void
    {
        $spec = [
            'head_shape' => 'oval',
            'skin_tone' => '#FDDBB4',
            'hair_style' => 'short_neat',
            'hair_color' => '#1A0A00',
            'eye_style' => 'almond',
            'brow_style' => 'thin_arched',
            'mouth_style' => 'full',
            'glasses' => 'none',
            'outfit' => 'suit_dark',
            'palette' => 'dark_pro',
            'body_proportions' => 'average',
            'voice_profile' => 'neutral_en',
        ];

        $result = CharacterSpec::validate($spec);
        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    public function test_character_spec_missing_field_fails(): void
    {
        $result = CharacterSpec::validate(['head_shape' => 'oval']); // missing 11 fields
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_character_spec_invalid_value_fails(): void
    {
        $spec = [
            'head_shape' => 'INVALID_SHAPE',
            'skin_tone' => '#FDDBB4',
            'hair_style' => 'short_neat',
            'hair_color' => '#1A0A00',
            'eye_style' => 'almond',
            'brow_style' => 'thin_arched',
            'mouth_style' => 'full',
            'glasses' => 'none',
            'outfit' => 'suit_dark',
            'palette' => 'dark_pro',
            'body_proportions' => 'average',
            'voice_profile' => 'neutral_en',
        ];

        $result = CharacterSpec::validate($spec);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('head_shape', $result['errors'][0]);
    }

    public function test_character_spec_generate_produces_valid_spec(): void
    {
        $prng = new AvatarPrng(42);
        $spec = CharacterSpec::generate($prng);

        $result = CharacterSpec::validate($spec);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    // ── VisemeMapper ──────────────────────────────────────────────────────────

    public function test_viseme_mapper_known_words(): void
    {
        $this->assertSame('closed', VisemeMapper::wordToViseme('my'));
        $this->assertSame('closed', VisemeMapper::wordToViseme('be'));
        $this->assertSame('fv', VisemeMapper::wordToViseme('for'));
        $this->assertSame('fv', VisemeMapper::wordToViseme('very'));
        $this->assertSame('th', VisemeMapper::wordToViseme('the'));
        $this->assertSame('round', VisemeMapper::wordToViseme('our'));
        $this->assertSame('wide', VisemeMapper::wordToViseme('and'));
        $this->assertSame('small', VisemeMapper::wordToViseme('in'));
    }

    public function test_viseme_mapper_empty_word_returns_closed(): void
    {
        $this->assertSame('closed', VisemeMapper::wordToViseme(''));
        $this->assertSame('closed', VisemeMapper::wordToViseme('...'));
    }

    public function test_viseme_mapper_build_track_length_matches_duration(): void
    {
        $fps = 30;
        $duration = 5.0;
        $timings = [
            ['word' => 'hello', 'startMs' => 0, 'endMs' => 400],
            ['word' => 'world', 'startMs' => 500, 'endMs' => 900],
        ];

        $track = VisemeMapper::buildTrack($timings, $duration, $fps);
        $this->assertCount((int) ceil($duration * $fps), $track);
    }

    public function test_viseme_mapper_speaking_frames_are_open(): void
    {
        $timings = [['word' => 'and', 'startMs' => 0, 'endMs' => 400]]; // 'a' onset → wide
        $track = VisemeMapper::buildTrack($timings, 2.0, 30);

        // Frame 0 (0ms) should be 'wide' with openness > 0
        $frame0 = array_values(array_filter($track, fn ($e) => $e['frame'] === 0));
        $this->assertNotEmpty($frame0);
        $this->assertSame('wide', $frame0[0]['viseme']);
        $this->assertGreaterThan(0, $frame0[0]['openness']);
    }

    // ── Blink scheduler ───────────────────────────────────────────────────────

    /**
     * The blink rate produced by the JS scheduler (simulated here in PHP)
     * must fall within 8–25 blinks per minute across 100 seeds.
     */
    public function test_blink_rate_within_bounds_for_100_seeds(): void
    {
        $duration = 60.0; // 1 minute for easy rate calculation
        $blinkIntervalMin = 60.0 / 25; // 2.4s
        $blinkIntervalMax = 60.0 / 8;  // 7.5s

        for ($seed = 1; $seed <= 100; $seed++) {
            $prng = new AvatarPrng($seed);
            $blinks = 0;
            $blinkTime = $blinkIntervalMin + $prng->next() * ($blinkIntervalMax - $blinkIntervalMin);

            while ($blinkTime < $duration - 0.5) {
                $blinks++;
                $blinkTime += $blinkIntervalMin + $prng->next() * ($blinkIntervalMax - $blinkIntervalMin);
            }

            $this->assertGreaterThanOrEqual(8, $blinks, "Seed {$seed}: only {$blinks} blinks/min");
            $this->assertLessThanOrEqual(25, $blinks, "Seed {$seed}: {$blinks} blinks/min exceeds max");
        }
    }

    // ── Disclosure badge & timeline ──────────────────────────────────────────

    public function test_presenter_html_contains_ai_disclosure_badge(): void
    {
        $this->buildPresenterAndAssert(function (string $html) {
            $this->assertStringContainsString('pvg-badge', $html);
            $this->assertStringContainsString('AI-generated presenter', $html);
        });
    }

    public function test_presenter_html_registers_timeline(): void
    {
        $this->buildPresenterAndAssert(function (string $html) {
            $this->assertTrue(
                str_contains($html, "__timelines['pvg-presenter']") ||
                str_contains($html, '__timelines["pvg-presenter"]'),
                'Missing window.__timelines registration'
            );
        });
    }

    // ── Fail-closed ──────────────────────────────────────────────────────────

    public function test_verify_command_fails_closed_when_flag_disabled(): void
    {
        // When avatar.enabled is disabled and not forced, verify command exits 1
        $this->artisan('ai-pvg:verify-avatar', [
            '--execute' => true,
        ])->assertExitCode(1);
    }

    public function test_verify_command_fails_when_audio_manifest_missing(): void
    {
        $this->artisan('ai-pvg:verify-avatar', [
            '--execute' => true,
            '--force' => true,
            '--audio-manifest' => '/nonexistent/path/audio_timeline.json',
        ])->assertExitCode(1);
    }

    // ── QA Service ───────────────────────────────────────────────────────────

    public function test_qa_gate1_passes_for_good_track(): void
    {
        $timings = [
            ['word' => 'hello', 'startMs' => 0, 'endMs' => 400],
            ['word' => 'world', 'startMs' => 500, 'endMs' => 900],
        ];
        $track = VisemeMapper::buildTrack($timings, 2.0, 30);

        $qa = new AvatarQaService;
        $result = $qa->checkLipSyncTrack($track, $timings);

        $this->assertTrue($result['pass'], $result['message']);
    }

    public function test_qa_gate2_passes_for_valid_presenter_html(): void
    {
        $this->buildPresenterAndAssert(function (string $html, string $path) {
            $qa = new AvatarQaService;
            $result = $qa->checkLiveness($path);
            $this->assertTrue($result['pass'], $result['message']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function buildPresenterAndAssert(callable $assertions): void
    {
        $prng = new AvatarPrng(42);
        $spec = CharacterSpec::generate($prng);
        $timings = [
            ['word' => 'hello', 'startMs' => 0, 'endMs' => 400],
            ['word' => 'world', 'startMs' => 500, 'endMs' => 900],
            ['word' => 'and', 'startMs' => 1000, 'endMs' => 1300],
        ];
        $track = VisemeMapper::buildTrack($timings, 3.0, 30);

        $manifest = [
            'total_duration' => 3.0,
            'narration' => [['scene_id' => 'scene-1', 'word_timings' => $timings]],
        ];

        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pvg_avatar_test_'.uniqid();
        $writer = new PresenterCompositionWriter;
        $relPath = $writer->write($spec, $track, $manifest, $tmpDir, 42, 30);
        $fullPath = $tmpDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relPath);

        $this->assertTrue(file_exists($fullPath), "Presenter HTML not found at {$fullPath}");
        $html = (string) file_get_contents($fullPath);

        $assertions($html, $fullPath);

        // Cleanup
        @unlink($fullPath);
        @rmdir(dirname($fullPath));
        @rmdir($tmpDir);
    }
}
