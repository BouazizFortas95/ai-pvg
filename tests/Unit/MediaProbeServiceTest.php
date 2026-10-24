<?php

namespace Tests\Unit;

use App\Services\Media\MediaProbeService;
use Tests\TestCase;

class MediaProbeServiceTest extends TestCase
{
    public function test_it_parses_a_valid_video_stream(): void
    {
        $data = [
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'width' => 1080,
                    'height' => 1920,
                    'r_frame_rate' => '30/1',
                    'avg_frame_rate' => '30/1',
                    'duration' => '6.000000',
                    'nb_frames' => '180',
                ],
            ],
            'format' => [
                'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
                'duration' => '6.000000',
                'size' => '35384',
            ],
        ];

        $result = (new MediaProbeService)->parse($data, ['path' => 'verified.mp4']);

        $this->assertTrue($result['verified']);
        $this->assertTrue($result['video_stream']);
        $this->assertFalse($result['audio_stream']);
        $this->assertSame('h264', $result['codec']);
        $this->assertSame(1080, $result['width']);
        $this->assertSame(1920, $result['height']);
        $this->assertSame(30.0, $result['fps']);
        $this->assertSame(6.0, $result['duration']);
        $this->assertSame(180, $result['frames']);
        $this->assertSame(35384, $result['size']);
    }

    public function test_it_rejects_probe_output_without_a_video_stream(): void
    {
        $data = [
            'streams' => [
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => ['duration' => '6.0', 'size' => '1000'],
        ];

        $result = (new MediaProbeService)->parse($data);

        $this->assertFalse($result['verified']);
        $this->assertFalse($result['video_stream']);
        $this->assertTrue($result['audio_stream']);
        $this->assertSame('No video stream found.', $result['reason']);
    }

    public function test_it_detects_expected_metadata_mismatches(): void
    {
        $probe = (new MediaProbeService)->parse([
            'streams' => [[
                'codec_type' => 'video',
                'codec_name' => 'vp9',
                'width' => 1920,
                'height' => 1080,
                'r_frame_rate' => '25/1',
                'duration' => '2.0',
            ]],
            'format' => ['duration' => '2.0', 'size' => '1000'],
        ]);

        $check = (new MediaProbeService)->matches($probe, [
            'codec' => 'h264',
            'width' => 1080,
            'height' => 1920,
            'fps' => 30,
            'min_duration' => 5,
        ]);

        $this->assertFalse($check['valid']);
        $this->assertCount(5, $check['errors']);
    }

    public function test_it_requires_audio_when_requested(): void
    {
        $probe = (new MediaProbeService)->parse([
            'streams' => [[
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1080,
                'height' => 1920,
                'r_frame_rate' => '30/1',
                'duration' => '6.0',
            ]],
            'format' => ['duration' => '6.0', 'size' => '1000'],
        ]);

        $check = (new MediaProbeService)->matches($probe, ['require_audio' => true]);

        $this->assertFalse($check['valid']);
        $this->assertContains('Expected an audio stream.', $check['errors']);
    }
}
