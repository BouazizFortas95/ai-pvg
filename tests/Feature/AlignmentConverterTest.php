<?php

namespace Tests\Feature;

use App\Services\Audio\ElevenLabs\AlignmentConverter;
use Tests\TestCase;

class AlignmentConverterTest extends TestCase
{
    public function test_converts_character_alignment_to_word_timings(): void
    {
        $converter = new AlignmentConverter;

        $alignment = [
            'characters' => ['A', 'I', '-', 'P', 'V', 'G', ' ', 'n', 'e', 'w', 's'],
            'character_start_times_seconds' => [0.0, 0.05, 0.1, 0.15, 0.2, 0.25, 0.3, 0.35, 0.4, 0.45, 0.5],
            'character_end_times_seconds' => [0.05, 0.1, 0.15, 0.2, 0.25, 0.3, 0.35, 0.4, 0.45, 0.5, 0.55],
        ];

        $words = $converter->toWordTimings($alignment);

        $this->assertCount(2, $words);
        $this->assertSame('AI-PVG', $words[0]['word']);
        $this->assertSame(0, $words[0]['startMs']);
        $this->assertSame(300, $words[0]['endMs']);

        $this->assertSame('news', $words[1]['word']);
        $this->assertSame(350, $words[1]['startMs']);
        $this->assertSame(550, $words[1]['endMs']);
    }

    public function test_enforces_strictly_monotonic_timings(): void
    {
        $converter = new AlignmentConverter;

        // Malformed overlapping character timings
        $alignment = [
            'characters' => ['o', 'n', 'e', ' ', 't', 'w', 'o'],
            'character_start_times_seconds' => [0.1, 0.15, 0.2, 0.25, 0.18, 0.22, 0.26],
            'character_end_times_seconds' => [0.15, 0.2, 0.25, 0.26, 0.22, 0.26, 0.28],
        ];

        $words = $converter->toWordTimings($alignment);

        $this->assertCount(2, $words);
        $this->assertLessThan($words[0]['endMs'], $words[0]['startMs']);
        $this->assertGreaterThanOrEqual($words[0]['endMs'], $words[1]['startMs']);
    }

    public function test_empty_alignment_returns_empty_array(): void
    {
        $converter = new AlignmentConverter;
        $this->assertSame([], $converter->toWordTimings(['characters' => [], 'character_start_times_seconds' => [], 'character_end_times_seconds' => []]));
    }
}

