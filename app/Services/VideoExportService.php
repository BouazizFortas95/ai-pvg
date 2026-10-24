<?php

namespace App\Services;

use App\Enums\VideoRenderStatus;
use App\Models\VideoExport;
use App\Services\Media\MediaProbeService;
use App\Support\MediaPath;
use App\Support\RemotionRuntime;

class VideoExportService
{
    public function __construct(?MediaProbeService $probe = null)
    {
        $this->probe = $probe ?? app(MediaProbeService::class);
    }

    protected MediaProbeService $probe;

    protected function hasFFprobe(): bool
    {
        return RemotionRuntime::ffprobePath() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extractMetadata(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $probe = $this->probe->probe($path);

        if (! ($probe['available'] ?? false)) {
            return ['verified' => false, 'note' => $probe['reason'] ?? 'ffprobe unavailable'];
        }

        return [
            'verified' => (bool) $probe['verified'],
            'codec' => $probe['codec'],
            'width' => $probe['width'],
            'height' => $probe['height'],
            'duration' => $probe['duration'],
            'fps' => $probe['fps'],
            'audio_stream' => $probe['audio_stream'],
            'raw' => $probe['raw'],
            'note' => $probe['reason'],
        ];
    }

    public function validateAndRecord(VideoExport $export): VideoExport
    {
        $render = $export->videoRender;
        $renderStatus = $render?->status instanceof \BackedEnum
            ? $render->status->value
            : $render?->status;

        if (! $render || $renderStatus !== VideoRenderStatus::Completed->value) {
            $export->update(['status' => 'failed', 'error_message' => 'Source render not completed', 'completed_at' => now()]);

            return $export->fresh();
        }

        $sourcePath = MediaPath::resolve($render->output_path);
        $exists = $sourcePath !== null && is_file($sourcePath) && (filesize($sourcePath) ?: 0) > 1000;
        $metadata = $exists && $sourcePath ? $this->extractMetadata($sourcePath) : null;
        $validated = $exists && $metadata !== null && ($metadata['verified'] ?? false) === true;

        $resolution = ($metadata['width'] ?? 0) > 0 && ($metadata['height'] ?? 0) > 0
            ? $metadata['width'].'x'.$metadata['height']
            : ($render->resolution ?? '1080x1920');

        $report = [
            'source_exists' => $exists,
            'source_path' => $sourcePath,
            'ffprobe_available' => $this->hasFFprobe(),
            'metadata' => $metadata,
            'preset_validation' => PlatformPresetService::validate(
                $export->platform_preset,
                $metadata['duration'] ?? $render->duration ?? 0,
                $resolution
            ),
        ];

        $export->update([
            'status' => $validated ? 'completed' : 'failed',
            'output_path' => $exists ? $render->output_path : null,
            'file_size' => $exists && $sourcePath ? (filesize($sourcePath) ?: 0) : null,
            'duration' => $metadata['duration'] ?? $render->duration,
            'resolution' => $resolution,
            'validation_report' => $report,
            'completed_at' => now(),
            'error_message' => $validated
                ? null
                : 'Video file missing or metadata unverified: '.($metadata['note'] ?? 'unknown reason'),
        ]);

        return $export->fresh();
    }
}
