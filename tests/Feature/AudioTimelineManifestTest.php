<?php

namespace Tests\Feature;

use App\Exceptions\Rendering\AudioTimelineValidationException;
use App\Services\Rendering\Audio\AudioTimelineValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AudioTimelineManifestTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->scratch = storage_path('framework/testing/timeline_test_'.uniqid());
        File::ensureDirectoryExists($this->scratch);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->scratch)) {
            File::deleteDirectory($this->scratch);
        }

        parent::tearDown();
    }

    private function makeValidTimeline(): array
    {
        $narr1 = $this->scratch.'/narr1.wav';
        $narr2 = $this->scratch.'/narr2.wav';
        $music = $this->scratch.'/music.wav';
        $mix = $this->scratch.'/mix.wav';

        File::put($narr1, str_repeat('A', 1024));
        File::put($narr2, str_repeat('B', 1024));
        File::put($music, str_repeat('C', 2048));
        File::put($mix, str_repeat('D', 2048));

        return [
            'version' => 1,
            'title' => 'Test Valid Timeline',
            'language' => 'en',
            'total_duration' => 8.0,
            'tts_provider' => 'windows-sapi',
            'voice' => 'Microsoft Zira Desktop',
            'narration' => [
                [
                    'scene_id' => 'scene-1',
                    'path' => $narr1,
                    'start' => 0.0,
                    'end' => 3.0,
                    'duration' => 3.0,
                    'text' => 'First scene narration.',
                ],
                [
                    'scene_id' => 'scene-2',
                    'path' => $narr2,
                    'start' => 3.25,
                    'end' => 7.0,
                    'duration' => 3.75,
                    'text' => 'Second scene narration.',
                ],
            ],
            'music' => [
                'id' => 'music-1',
                'path' => $music,
                'start' => 0.0,
                'end' => 8.0,
                'duration' => 8.0,
                'volume' => 0.15,
            ],
            'sfx' => [],
            'scenes' => [
                [
                    'id' => 'scene-1',
                    'order' => 1,
                    'start' => 0.0,
                    'duration' => 3.25,
                    'end' => 3.25,
                ],
                [
                    'id' => 'scene-2',
                    'order' => 2,
                    'start' => 3.25,
                    'duration' => 4.75,
                    'end' => 8.0,
                ],
            ],
            'mix' => [
                'path' => $mix,
                'duration' => 8.0,
                'integrated_lufs' => -14.0,
                'true_peak_dbtp' => -1.5,
                'lra' => 4.0,
            ],
            'word_timings' => [],
        ];
    }

    public function test_validator_accepts_valid_manifest(): void
    {
        $validator = new AudioTimelineValidator;
        $timeline = $this->makeValidTimeline();

        $result = $validator->validate($timeline);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);

        $asserted = $validator->assertValid($timeline);
        $this->assertSame($timeline, $asserted);
    }

    public function test_fail_condition_1_overlapping_narration_clips(): void
    {
        $validator = new AudioTimelineValidator;
        $timeline = $this->makeValidTimeline();

        // Overlap: scene-2 starts at 2.5s while scene-1 ends at 3.0s
        $timeline['narration'][1]['start'] = 2.5;
        $timeline['narration'][1]['end'] = 6.25;

        $result = $validator->validate($timeline);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Narration overlap detected', implode('; ', $result['errors']));

        $this->expectException(AudioTimelineValidationException::class);
        $validator->assertValid($timeline);
    }

    public function test_fail_condition_2_non_opening_clip_starts_at_zero(): void
    {
        $validator = new AudioTimelineValidator;
        $timeline = $this->makeValidTimeline();

        // Non-first segment starting at 0.0s (causes audio collision S1)
        $timeline['narration'][1]['start'] = 0.0;
        $timeline['narration'][1]['end'] = 3.75;

        $result = $validator->validate($timeline);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('starts at 0.0s; only opening narration may start at 0', implode('; ', $result['errors']));
    }

    public function test_fail_condition_3_music_shorter_than_video_duration(): void
    {
        $validator = new AudioTimelineValidator;
        $timeline = $this->makeValidTimeline();

        // Music ends at 5.0s on an 8.0s video (causes premature mute S2)
        $timeline['music']['end'] = 5.0;
        $timeline['music']['duration'] = 5.0;

        $result = $validator->validate($timeline);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('shorter than video duration', implode('; ', $result['errors']));
    }

    public function test_fail_condition_4_unexplained_silence_gap_over_700ms(): void
    {
        $validator = new AudioTimelineValidator;
        $timeline = $this->makeValidTimeline();

        // Gap between 3.0s and 4.2s is 1.2s (> 0.7s)
        $timeline['narration'][1]['start'] = 4.2;
        $timeline['narration'][1]['end'] = 7.95;

        $result = $validator->validate($timeline);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Unexplained silence', implode('; ', $result['errors']));
    }

    public function test_fail_condition_5_scene_durations_mismatch_total_duration(): void
    {
        $validator = new AudioTimelineValidator;
        $timeline = $this->makeValidTimeline();

        // Total duration is 8.0s, but scene durations sum to 9.5s
        $timeline['scenes'][1]['duration'] = 6.25;

        $result = $validator->validate($timeline);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Sum of scene durations', implode('; ', $result['errors']));
    }

    public function test_fail_condition_6_missing_or_empty_audio_file(): void
    {
        $validator = new AudioTimelineValidator;
        $timeline = $this->makeValidTimeline();

        // File does not exist
        $timeline['narration'][0]['path'] = $this->scratch.'/non_existent.wav';

        $result = $validator->validate($timeline);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('file does not exist', implode('; ', $result['errors']));

        // File is 0 bytes
        $emptyFile = $this->scratch.'/empty.wav';
        File::put($emptyFile, '');
        $timeline['narration'][0]['path'] = $emptyFile;

        $result2 = $validator->validate($timeline);
        $this->assertFalse($result2['ok']);
        $this->assertStringContainsString('file is empty', implode('; ', $result2['errors']));
    }
}

