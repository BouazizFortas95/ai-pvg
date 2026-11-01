<?php

namespace Tests\Feature;

use App\Services\AI\Captions\CaptionTrackBuilder;
use App\Services\AI\Captions\WordTimingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaptionPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_estimated_timings_are_monotonic_and_not_verified(): void
    {
        $service = new WordTimingService;
        $timings = $service->estimateFromText('Hello world from caption pipeline.', 3000);
        $this->assertNotEmpty($timings);
        $prevEnd = 0;
        foreach ($timings as $t) {
            $this->assertGreaterThanOrEqual($prevEnd, $t['start_ms']);
            $this->assertGreaterThanOrEqual(0, $t['start_ms']);
            $this->assertGreaterThan($t['start_ms'], $t['end_ms']);
            $this->assertStringContainsString($t['source'], 'estimated');
            $prevEnd = $t['end_ms'];
        }
    }

    public function test_verified_timings_are_never_labeled_estimated(): void
    {
        $service = new WordTimingService;
        $verified = $service->verifiedFromTtsMetadata([
            ['word' => 'Hello', 'start_ms' => 0, 'end_ms' => 500],
            ['word' => 'world', 'start_ms' => 500, 'end_ms' => 1100],
        ]);
        $this->assertSame('verified', $verified[0]['source']);
        $this->assertSame('verified', $verified[1]['source']);
    }

    public function test_caption_track_builder_respects_rtl_and_bounds(): void
    {
        $builder = new CaptionTrackBuilder;
        $chunks = $builder->buildChunks('مرحبا بالعالم في هذا النص التجريبي الطويل', 'ar', 20, 5);
        $this->assertNotEmpty($chunks);
        $first = $chunks[0];
        $this->assertTrue($first['rtl']);
        $this->assertSame('ar', $first['language']);
    }

    public function test_caption_tables_exist_and_are_empty_by_default(): void
    {
        $this->assertDatabaseCount('caption_tracks', 0);
        $this->assertDatabaseCount('audio_assets', 0);
    }

    public function test_empty_text_returns_empty_array_gracefully(): void
    {
        $service = new WordTimingService;
        $this->assertSame([], $service->estimateFromText('', 100));
    }

    public function test_long_narration_with_high_word_count_maintains_strict_monotonicity(): void
    {
        $service = new WordTimingService;
        // 250 words paragraph in 45000ms duration (where old clamp caused start_ms >= end_ms)
        $words = array_fill(0, 250, 'breakingnews');
        $text = implode(' ', $words);

        $timings = $service->estimateFromText($text, 45000.0);

        $this->assertCount(250, $timings);
        $prevEnd = 0;
        foreach ($timings as $t) {
            $this->assertGreaterThanOrEqual($prevEnd, $t['start_ms']);
            $this->assertGreaterThan($t['start_ms'], $t['end_ms']);
            $prevEnd = $t['end_ms'];
        }
        $this->assertLessThanOrEqual(45100, $prevEnd);
    }

    public function test_verified_timings_handles_degenerate_or_zero_duration_metadata(): void
    {
        $service = new WordTimingService;
        $verified = $service->verifiedFromTtsMetadata([
            ['word' => 'Token1', 'start_ms' => 0, 'end_ms' => 0],
            ['word' => 'Token2', 'start_ms' => 0, 'end_ms' => 0],
            ['word' => 'Token3', 'start_ms' => 100],
        ]);

        $this->assertCount(3, $verified);
        $prevEnd = 0;
        foreach ($verified as $t) {
            $this->assertGreaterThanOrEqual($prevEnd, $t['start_ms']);
            $this->assertGreaterThan($t['start_ms'], $t['end_ms']);
            $prevEnd = $t['end_ms'];
        }
    }
}
